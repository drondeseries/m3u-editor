<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ChannelStream;
use App\Models\UserStreamSession; // Assuming this model exists
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process; // For ffprobe
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Jobs\StartStreamProcessingJob; // Assuming this job will be created
use Illuminate\Support\Str; // For generating session_id if needed
use Symfony\Component\HttpFoundation\Response; // For HTTP status codes

class StreamController extends Controller
{
    const FFPROBE_TIMEOUT_SECONDS = 3;
    const FFMPEG_STALL_THRESHOLD_SECONDS = 15; // Example, actual segment monitoring will be in the job

    /**
     * Handles the request for a stream.
     *
     * @param Request $request
     * @param int $channel_id
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function getStream(Request $request, $channel_id)
    {
        // 0. Get or create a unique session identifier for the user/request
        // $user = $request->user(); // If using Laravel authentication
        // $session_id = $user ? 'user_' . $user->id : $request->session()->getId();
        // For simplicity without full user auth setup, let's use a cookie-based or generated session ID
        $session_id = $request->cookie('stream_session_id') ?: Str::uuid()->toString();

        $channel = Channel::findOrFail($channel_id);

        // 1. Try to find an existing UserStreamSession for this user/session and channel
        $userStreamSession = UserStreamSession::where('session_id', $session_id)
            ->where('channel_id', $channel->id)
            ->first();

        $selectedChannelStream = null;

        if ($userStreamSession && $userStreamSession->activeChannelStream) {
            // User already has an active stream session for this channel.
            // Check if its FFmpeg process is still considered healthy (quick check)
            // This is a basic check; more detailed monitoring is in MonitorStreamHealthJob
            if ($this->isFfmpegProcessHealthy($userStreamSession->ffmpeg_pid)) {
                $selectedChannelStream = $userStreamSession->activeChannelStream;
                Log::info("StreamController: Existing healthy session found for session_id {$session_id}, channel {$channel_id}, stream_id {$selectedChannelStream->id}");
            } else {
                Log::warning("StreamController: Existing session for session_id {$session_id}, channel {$channel_id} found, but FFmpeg PID {$userStreamSession->ffmpeg_pid} is not healthy. Attempting to find new stream.");
                // Mark this specific session's stream attempt as failed/stale if needed, or let Monitor job handle it.
                // For now, just proceed to select a new stream.
                $userStreamSession->delete(); // Or update status; deleting forces re-selection.
                $userStreamSession = null; // Force re-creation or selection.
            }
        }

        if (!$selectedChannelStream) {
            // 2. Select the best available stream if no healthy session or stream exists
            $availableStreams = $channel->channelStreams()
                ->where('status', '!=', 'disabled') // Exclude explicitly disabled streams
                ->where(function ($query) { // Prefer non-problematic, or problematic not checked recently
                    $query->where('status', '!=', 'problematic')
                          ->orWhere('last_error_at', '<', now()->subMinutes(5)); // Retry problematic streams after 5 mins
                })
                ->orderBy('priority', 'asc')
                ->get();

            if ($availableStreams->isEmpty()) {
                Log::error("StreamController: No available streams for channel_id {$channel_id}.");
                return response()->json(['error' => 'Channel currently unavailable.'], Response::HTTP_NOT_FOUND);
            }

            foreach ($availableStreams as $stream) {
                if ($this->isValidStreamSource($stream)) {
                    $selectedChannelStream = $stream;
                    Log::info("StreamController: Selected stream_id {$stream->id} for channel {$channel_id} after ffprobe validation.");
                    break;
                } else {
                    Log::warning("StreamController: Stream_id {$stream->id} for channel {$channel_id} failed ffprobe validation.");
                    $stream->status = 'problematic';
                    $stream->last_error_at = now();
                    $stream->consecutive_failure_count = $stream->consecutive_failure_count + 1;
                    $stream->save();
                }
            }

            if (!$selectedChannelStream) {
                Log::error("StreamController: All available streams for channel_id {$channel_id} failed ffprobe validation.");
                return response()->json(['error' => 'Channel currently unavailable due to source issues.'], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        // 3. Create or update UserStreamSession
        if (!$userStreamSession) {
            $userStreamSession = UserStreamSession::create([
                'session_id' => $session_id,
                'user_id' => null, // Replace with $user->id if auth is used
                'channel_id' => $channel->id,
                'active_channel_stream_id' => $selectedChannelStream->id,
                'session_started_at' => now(),
                'last_activity_at' => now(),
            ]);
        } else {
            // If session existed but stream was unhealthy, update it to the new stream
            $userStreamSession->active_channel_stream_id = $selectedChannelStream->id;
            $userStreamSession->ffmpeg_pid = null; // Will be set by the job
            $userStreamSession->worker_pid = null; // Will be set by the job
            $userStreamSession->last_segment_at = null;
            $userStreamSession->last_segment_media_sequence = null;
            $userStreamSession->last_activity_at = now();
            $userStreamSession->save();
        }

        // Update channel's active stream if it has changed (simplistic, could be more nuanced)
        if ($channel->active_channel_stream_id !== $selectedChannelStream->id) {
             // This is a global change, perhaps only do this if the previous active_stream_id was problematic
             // For now, let's assume any valid selection can become the "globally preferred" active one if it's better
             // $channel->active_channel_stream_id = $selectedChannelStream->id;
             // $channel->save();
             // More sophisticated logic needed here based on overall stream health.
        }

        // 4. Dispatch Job to Start/Ensure FFmpeg is running for this UserStreamSession
        // The job will handle the actual FFmpeg process and its PID management.
        StartStreamProcessingJob::dispatch($userStreamSession->id)->onQueue('streaming');


        // 5. Return the M3U8 URL to the client.
        // The client will fetch this URL, and FFmpeg (managed by the job) will serve segments to it.
        // The URL should be unique per session to allow multiple users to watch the same channel via different source streams if needed
        // or if one user's ffmpeg process dies and restarts on a new source.
        $m3u8Url = route('stream.hls.master', ['sessionId' => $userStreamSession->session_id, 'channelStreamId' => $selectedChannelStream->id]);

        // It's important to set the cookie for subsequent requests if it was newly generated.
        $response = response()->json(['m3u8_url' => $m3u8Url]);
        if (!$request->cookie('stream_session_id')) {
            $response->cookie('stream_session_id', $session_id, 60 * 24 * 7); // Cookie for 7 days
        }
        return $response;
    }

    /**
     * Serves the HLS master playlist for a given user stream session.
     * This is what the client player will actually request based on m3u8_url from getStream.
     * The actual HLS segments are served by FFmpeg directly to storage, and then by the webserver.
     * This method ensures the playlist file exists and points to the correct segments.
     */
    public function serveMasterPlaylist(Request $request, $sessionId, $channelStreamId)
    {
        $userStreamSession = UserStreamSession::where('session_id', $sessionId)
                                ->where('active_channel_stream_id', $channelStreamId)
                                ->firstOrFail();

        // Update last activity for the session
        $userStreamSession->last_activity_at = now();
        $userStreamSession->save();

        $playlistPath = storage_path("app/hls/{$userStreamSession->session_id}_{$channelStreamId}/master.m3u8");

        // Check if playlist exists and is recent enough (FFmpeg job should be creating/updating it)
        // This is a basic check. The job is responsible for its health.
        if (!file_exists($playlistPath) || (time() - filemtime($playlistPath)) > self::FFMPEG_STALL_THRESHOLD_SECONDS * 2) {
            Log::warning("StreamController: Master playlist not found or stale for session_id {$sessionId}, stream_id {$channelStreamId}. Path: {$playlistPath}");
            // Optionally, re-dispatch StartStreamProcessingJob if FFmpeg seems to have died.
            // StartStreamProcessingJob::dispatch($userStreamSession->id)->onQueue('streaming');
            return response()->json(['error' => 'Stream is starting or has encountered an issue. Please try again shortly.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // It's crucial that the web server (Nginx/Apache) is configured to serve .m3u8 and .ts files from the storage/app/hls directory.
        // Laravel itself typically doesn't serve these files directly in production for performance.
        // For development, or if direct serving is needed:
        // return response()->file($playlistPath, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        // In a typical setup, you'd redirect or ensure the client can fetch this path.
        // For now, let's assume the path is accessible via web server config.
        // The URL provided to client should point to where webserver serves this file.
        // The `route('stream.hls.master', ...)` should map to a public URL.
        // This function is more of a placeholder for how the client gets the playlist.
        // The actual serving of M3U8 and TS files is best handled by Nginx/Apache for performance.
        // If using Laravel to serve:
        if (file_exists($playlistPath)) {
            return response()->file($playlistPath, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Content-Disposition' => 'inline; filename="master.m3u8"',
            ]);
        }
        Log::error("StreamController: Master playlist file does not exist at expected path: {$playlistPath}");
        return response("Playlist not found.", Response::HTTP_NOT_FOUND);
    }

    /**
     * Serves HLS segment files.
     * NOTE: THIS IS GENERALLY NOT RECOMMENDED FOR PRODUCTION.
     * HLS segments should be served by a web server (Nginx, Apache) directly for performance.
     * This is a simplified example if direct serving via Laravel is temporarily needed.
     */
    public function serveSegment(Request $request, $sessionId, $channelStreamId, $segmentName)
    {
         // Basic validation for segment name to prevent directory traversal
        if (!preg_match('/^segment_\d{3}\.ts$/', $segmentName)) {
            return response("Invalid segment name.", Response::HTTP_BAD_REQUEST);
        }

        $segmentPath = storage_path("app/hls/{$sessionId}_{$channelStreamId}/{$segmentName}");

        if (file_exists($segmentPath)) {
            return response()->file($segmentPath, [
                'Content-Type' => 'video/mp2t', // MPEG-TS
                'Content-Disposition' => 'inline; filename="' . $segmentName . '"',
            ]);
        }
        return response("Segment not found.", Response::HTTP_NOT_FOUND);
    }


    /**
     * Quick check if an FFmpeg process is likely healthy.
     * This is a very basic check. More robust checks are in the Monitor job.
     * @param int|null $pid
     * @return bool
     */
    private function isFfmpegProcessHealthy($pid)
    {
        if (!$pid) {
            return false;
        }
        // On Linux/macOS, check if process exists. This is OS-dependent.
        // `ps -p $pid` returns 0 if process exists.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: tasklist /FI "PID eq $pid"
            // This is more complex to parse reliably here.
            // For simplicity, assume if PID exists, it might be healthy for this quick check.
            // A more robust solution would involve proper process management libraries.
            return true; // Placeholder for Windows
        } else {
            // Linux/macOS
            $result = Process::run("ps -p {$pid}");
            return $result->successful() && Str::contains($result->output(), (string)$pid);
        }
    }

    /**
     * Validates a stream source using ffprobe.
     *
     * @param ChannelStream $channelStream
     * @return bool
     */
    private function isValidStreamSource(ChannelStream $channelStream): bool
    {
        $command = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_streams',
            '-show_format',
            '-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.90 Safari/537.36', // Generic User Agent
            $channelStream->stream_url
        ];

        try {
            // Using Laravel's Process facade
            $result = Process::timeout(self::FFPROBE_TIMEOUT_SECONDS)->run(implode(' ', $command));

            if (!$result->successful()) {
                Log::warning("isValidStreamSource: ffprobe failed for {$channelStream->stream_url}. Error: " . $result->errorOutput());
                return false;
            }

            $output = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("isValidStreamSource: Failed to decode ffprobe JSON output for {$channelStream->stream_url}. Output: " . $result->output());
                return false;
            }

            // Check for at least one audio or video stream
            if (empty($output['streams'])) {
                 Log::warning("isValidStreamSource: No streams found by ffprobe for {$channelStream->stream_url}.");
                return false;
            }

            $has_av_stream = false;
            foreach($output['streams'] as $stream_info) {
                if (isset($stream_info['codec_type']) && ($stream_info['codec_type'] === 'video' || $stream_info['codec_type'] === 'audio')) {
                    $has_av_stream = true;
                    break;
                }
            }
            if (!$has_av_stream) {
                Log::warning("isValidStreamSource: No audio or video streams found by ffprobe for {$channelStream->stream_url}.");
                return false;
            }

            return true;

        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            Log::warning("isValidStreamSource: ffprobe timed out for {$channelStream->stream_url}.");
            return false;
        } catch (\Exception $e) {
            Log::error("isValidStreamSource: Exception during ffprobe for {$channelStream->stream_url}: " . $e->getMessage());
            return false;
        }
    }
}

// Placeholder for routes (web.php or api.php):
// Route::get('/stream/channel/{channel_id}/request', [App\Http\Controllers\StreamController::class, 'getStream'])->name('stream.request');
// Route::get('/stream/hls/{sessionId}/{channelStreamId}/master.m3u8', [App\Http\Controllers\StreamController::class, 'serveMasterPlaylist'])->name('stream.hls.master');
// Route::get('/stream/hls/{sessionId}/{channelStreamId}/{segmentName}', [App\Http\Controllers\StreamController::class, 'serveSegment'])->where('segmentName', 'segment_\d{3}\.ts')->name('stream.hls.segment');
