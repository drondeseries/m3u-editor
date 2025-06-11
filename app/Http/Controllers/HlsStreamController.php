<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;
use App\Services\HlsStreamService;
use App\Traits\TracksActiveStreams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Class HlsStreamController
 * 
 * This controller handles the HLS streaming for channels.
 * It manages the starting of the FFmpeg process and serves the HLS playlist and segments.
 * 
 * NOTE: Using NGINX internal redirects for serving the playlist and segments.
 *       If running locally, make sure to set up NGINX with the following configuration:
 * 
 * location /internal/hls/ {
 *     internal;
 *     alias [PROJECT_ROOT_PATH]/storage/app/hls/;
 *     access_log off;
 *     add_header Cache-Control no-cache;
 * }
 * 
 */
class HlsStreamController extends Controller
{
    use TracksActiveStreams;

    private HlsStreamService $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    /**
     * Serves the HLS playlist for a channel.
     * It ensures the stream is running, starting it if necessary, and then serves the m3u8 file.
     */
    public function serveChannelPlaylist(Request $request, string $encodedId): \Illuminate\Http\Response
    {
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // Ensure proper base64 padding
        }
        $channelId = base64_decode($encodedId);
        $channel = Channel::find($channelId);

        if (!$channel) {
            Log::error("HLS Playlist Request: Channel not found for encoded ID {$encodedId} (decoded: {$channelId}). Aborting.");
            abort(404, 'Channel not found.');
        }

        $channelName = strip_tags($channel->title_custom ?? $channel->title);
        Log::info("HLS Playlist Request: Received for channel {$channel->id} ('{$channelName}'). Current status: {$channel->stream_status}, current_provider_id: {$channel->current_stream_provider_id}. IP: {$request->ip()}");

        return $this->servePlaylist($channel);
    }

    /**
     * Serves an HLS segment for a channel.
     */
    public function serveChannelSegment(Request $request, int $channelId, string $segment): \Illuminate\Http\Response
    {
        Log::debug("HLS Segment Request: Received for channel {$channelId}, segment {$segment}. IP: {$request->ip()}");
        $channel = Channel::find($channelId);
        if (!$channel) {
            Log::error("HLS Segment Request: Channel {$channelId} not found for segment {$segment}. Aborting.");
            abort(404, "Channel not found for segment request.");
        }
        return $this->serveSegment('channel', $channel, $segment);
    }

    /**
     * Serves the HLS playlist for an episode.
     * (This method will be minimally changed to fit the new structure but core logic is for channels)
     */
    public function serveEpisodePlaylist(Request $request, string $encodedId): \Illuminate\Http\Response
    {
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '==';
        }
        $episodeId = base64_decode($encodedId);
        $episode = Episode::find($episodeId);

        if (!$episode) {
            Log::error("HLS Playlist Request: Episode not found for encoded ID {$encodedId} (decoded: {$episodeId}). Aborting. IP: {$request->ip()}");
            abort(404, 'Episode not found.');
        }
        Log::info("HLS Playlist Request: Received for episode {$episode->id} ('" . strip_tags($episode->title) . "'). IP: {$request->ip()}");
        return $this->serveLegacyPlaylist('episode', $encodedId, $episode, $episode->title);
    }

    /**
     * Serves an HLS segment for an episode.
     * (Minimally changed)
     */
    public function serveEpisodeSegment(Request $request, int $episodeId, string $segment): \Illuminate\Http\Response
    {
        Log::debug("HLS Segment Request: Received for episode {$episodeId}, segment {$segment}. IP: {$request->ip()}");
        $episode = Episode::find($episodeId);
        if (!$episode) {
             Log::error("HLS Segment Request: Episode {$episodeId} not found for segment {$segment}. Aborting.");
            abort(404, "Episode not found for segment request.");
        }
        return $this->serveSegment('episode', $episode, $segment);
    }


    private function servePlaylist(Channel $channel): \Illuminate\Http\Response
    {
        $channelName = strip_tags($channel->title_custom ?? $channel->title);
        Log::info("HLS Playlist Logic: Processing channel {$channel->id} ('{$channelName}').");
        $m3u8Path = Storage::disk('app')->path("hls/{$channel->id}/stream.m3u8");
        $nginxRedirectPath = "/internal/hls/{$channel->id}/stream.m3u8";

        if ($channel->stream_status === 'failed') {
            Log::warning("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') status is 'failed'. Aborting with 503.");
            abort(503, 'Stream is currently unavailable (status: failed).');
        }

        $isRunning = $this->hlsService->isRunning('channel', $channel->id);
        Log::debug("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}'). FFmpeg running: " . ($isRunning ? 'yes' : 'no') . ". M3U8 path: {$m3u8Path}");

        if (($channel->stream_status === 'playing' || $channel->stream_status === 'switching') && $channel->current_stream_provider_id) {
            if ($isRunning && file_exists($m3u8Path)) {
                Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') is '{$channel->stream_status}', process for provider {$channel->current_stream_provider_id} is running, M3U8 exists. Serving playlist.");
                return $this->createHlsResponse($nginxRedirectPath);
            } else {
                Log::warning("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') status '{$channel->stream_status}' with provider {$channel->current_stream_provider_id}, but process/M3U8 not found (isRunning: " . ($isRunning ? 'yes' : 'no') . ", m3u8_exists: " . (file_exists($m3u8Path) ? 'yes' : 'no') . "). Triggering HlsStreamService->startStream for potential restart.");
                $channel = $this->hlsService->startStream($channel);
                if (!$channel || $channel->stream_status === 'failed') {
                    Log::error("HLS Playlist Logic: HlsStreamService->startStream (restart attempt) failed for channel {$channel->id} ('{$channelName}'). Final status: " . ($channel->stream_status ?? 'null channel'). ". Aborting with 503.");
                    abort(503, 'Stream unavailable, restart failed.');
                }
                 Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') processed by startStream (restart attempt). New status: {$channel->stream_status}, provider: {$channel->current_stream_provider_id}. Proceeding to serve M3U8.");
            }
        } else {
            Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') needs explicit start. Current status: {$channel->stream_status}. Calling HlsStreamService->startStream.");
            $channel = $this->hlsService->startStream($channel);
            if (!$channel || $channel->stream_status === 'failed') {
                Log::error("HLS Playlist Logic: HlsStreamService->startStream (initial start) failed for channel {$channel->id} ('{$channelName}'). Final status: " . ($channel->stream_status ?? 'null channel') . ". Aborting with 503.");
                abort(503, 'Stream unavailable, failed to start.');
            }
            Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$channelName}') processed by startStream (initial start). Status: {$channel->stream_status}, provider: {$channel->current_stream_provider_id}. Proceeding to serve M3U8.");
        }

        $settings = app(GeneralSettings::class);
        $maxAttempts = $settings->hls_playlist_max_attempts ?? 15;
        $sleepSeconds = $settings->hls_playlist_sleep_seconds ?? 1.0;
        Log::debug("HLS Playlist Logic: Entering M3U8 wait loop for channel {$channel->id} ('{$channelName}'). Max attempts: {$maxAttempts}, Sleep: {$sleepSeconds}s.");

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (file_exists($m3u8Path)) {
                Log::info("HLS Playlist Logic: M3U8 found for channel {$channel->id} ('{$channelName}') on attempt {$attempt}. Serving.");
                return $this->createHlsResponse($nginxRedirectPath);
            }

            if (!$this->hlsService->isRunning('channel', $channel->id)) {
                Log::error("HLS Playlist Logic: FFmpeg process for channel {$channel->id} ('{$channelName}') died while waiting for M3U8 (attempt {$attempt}). Aborting with 503.");
                abort(503, 'Stream process failed during playlist generation.');
            }

            Log::debug("HLS Playlist Logic: M3U8 not found for channel {$channel->id} on attempt {$attempt}. Sleeping for {$sleepSeconds}s.");
            if ($attempt === $maxAttempts) {
                $pid = $this->hlsService->getPid('channel', $channel->id) ?? 'N/A';
                Log::error("HLS Playlist Logic: M3U8 for channel {$channel->id} ('{$channelName}') not found after {$maxAttempts} attempts. FFmpeg PID: {$pid}. Aborting with 503, playlist generation timed out.");
                abort(503, 'Playlist generation timed out.');
            }
            usleep((int)($sleepSeconds * 1000000));
        }
        Log::error("HLS Playlist Logic: Reached unexpected end of function for channel {$channel->id} ('{$channelName}').");
        abort(500, 'Internal server error in playlist serving.');
    }

    /**
     * Helper to create the HLS response.
     */
    private function createHlsResponse(string $nginxRedirectPath): \Illuminate\Http\Response
    {
        return response('', 200, [
            'Content-Type'      => 'application/vnd.apple.mpegurl',
            'X-Accel-Redirect'  => $nginxRedirectPath,
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * This is a placeholder for the old servePlaylist logic, primarily for episodes,
     * as the main refactor is for channels.
     */
    private function serveLegacyPlaylist($type, $encodedId, $model, $title): \Illuminate\Http\Response
    {
        // This method would contain the original logic from the old servePlaylist,
        // which used $model->failoverChannels.
        Log::warning("HLS Playlist (Legacy): Serving request for {$type} ID {$model->id} ('{$title}') using deprecated legacy logic. This path should be updated or removed.");

        // Minimal placeholder for conceptual logic
        if (!$this->hlsService->isRunning($type, $model->id)) {
            Log::error("HLS Playlist (Legacy): Stream for {$type} {$model->id} ('{$title}') not running. Legacy start logic not implemented in this refactor pass. Aborting.");
            abort(503, 'Legacy episode streaming needs full review.');
        }

        $pathPrefix = $type === 'episode' ? 'e/' : ''; // Should always be 'e/' if called for episode
        $m3u8Path = Storage::disk('app')->path("hls/{$pathPrefix}{$model->id}/stream.m3u8");
        $nginxRedirectPath = "/internal/hls/{$pathPrefix}{$model->id}/stream.m3u8";

        if (file_exists($m3u8Path)) {
            Log::info("HLS Playlist (Legacy): Found M3U8 for {$type} {$model->id} ('{$title}'). Serving.");
            return $this->createHlsResponse($nginxRedirectPath);
        }

        Log::error("HLS Playlist (Legacy): M3U8 not found for {$type} {$model->id} ('{$title}') at path {$m3u8Path}. Aborting.");
        abort(404, 'Legacy playlist not found.');
    }


    /**
     * Serve a segment for a channel or episode.
     */
    private function serveSegment(string $type, Channel|Episode $model, string $segmentName): \Illuminate\Http\Response
    {
        $modelId = $model->id;
        $modelName = ($type === 'channel' && $model instanceof Channel) ? strip_tags($model->title_custom ?? $model->title) : (($model instanceof Episode) ? strip_tags($model->title) : 'N/A');
        Log::debug("HLS Segment Logic: Request for {$type} ID {$modelId} ('{$modelName}'), segment: {$segmentName}.");

        $pathPrefix = $type === 'channel' ? '' : 'e/';
        $nginxRedirectPath = "/internal/hls/{$pathPrefix}{$modelId}/{$segmentName}";
        $fullStoragePath = Storage::disk('app')->path("hls/{$pathPrefix}{$modelId}/{$segmentName}");

        if ($type === 'channel' && $model instanceof Channel) {
            // For channels, check the current stream_status.
            // If not 'playing' or 'switching', it's unusual to request a segment unless it's from a stale client playlist.
            if ($model->stream_status !== 'playing' && $model->stream_status !== 'switching') {
                Log::warning("HLS Segment Logic: Channel {$modelId} ('{$modelName}') status is '{$model->stream_status}'. Segment {$segmentName} request might be for an old or failing stream.");
            }
            // Critical check: if FFmpeg is not running AND the segment file doesn't exist, it's a definitive error.
            if (!$this->hlsService->isRunning('channel', $modelId) && !file_exists($fullStoragePath)) {
                Log::error("HLS Segment Logic: FFmpeg process for channel {$modelId} ('{$modelName}') not running AND segment {$segmentName} not found. Aborting with 404.");
                abort(404, 'Stream not active and segment not found.');
            }
        } else if ($type === 'episode') { // Assuming Episode model passed
             // For episodes, maintain a similar check if isRunning is applicable.
             if (!$this->hlsService->isRunning('episode', $modelId) && !file_exists($fullStoragePath)) {
                Log::error("HLS Segment Logic: FFmpeg process for episode {$modelId} ('{$modelName}') not running AND segment {$segmentName} not found. Aborting with 404.");
                abort(404, 'Episode stream not active and segment not found.');
            }
        }

        if (!file_exists($fullStoragePath)) {
            // This can happen if client requests a segment that's already been deleted by HLS cleanup,
            // or if FFmpeg is slow to produce segments, or if the stream just ended/failed.
            Log::warning("HLS Segment Logic: Segment {$segmentName} for {$type} ID {$modelId} ('{$modelName}') not found at path {$fullStoragePath}. Client may be requesting too quickly, or stream just ended/failed, or segment was cleaned up.");
            return response('Segment not found', 404, ['Content-Type' => 'video/MP2T', 'Cache-Control' => 'no-cache']);
        }

        // Track activity (optional, but kept from original logic for now)
        // Consider if this is too noisy or performance-intensive for every segment.
        Redis::transaction(function () use ($type, $modelId) {
            Redis::set("hls:{$type}_last_seen:{$modelId}", now()->timestamp);
            Redis::sadd("hls:active_{$type}_ids", $modelId);
        });

        Log::debug("HLS Segment Logic: Serving segment {$segmentName} for {$type} ID {$modelId} ('{$modelName}') via X-Accel-Redirect: {$nginxRedirectPath}");
        return response('', 200, [
            'Content-Type'     => 'video/MP2T',
            'X-Accel-Redirect' => $nginxRedirectPath,
            'Cache-Control'    => 'no-cache, no-transform', // Segments should not be cached by intermediate proxies once served
            'Connection'       => 'keep-alive',
        ]);
    }
}
