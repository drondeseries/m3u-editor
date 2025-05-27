<?php

namespace App\Http\Controllers;

use App\Models\FailoverChannel;
use App\Models\Channel; // Used by the sources relationship
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymfonyProcess; // Correctly aliased
use Exception; // For catching exceptions
use App\Exceptions\LowSpeedException; // Added for custom exception
use Illuminate\Support\Facades\Redirect; // Added for HLS playlist redirect
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Services\HlsStreamService;

class FailoverStreamController extends Controller
{
    protected HlsStreamService $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    public function __invoke(Request $request, FailoverChannel $failoverChannel, string $format = 'ts')
    {
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        Log::channel('ffmpeg')->info("Attempting to stream Failover Channel: {$failoverChannel->name} (ID: {$failoverChannel->id})");
        // Sources are ordered by 'order' column in the pivot table via the model relationship
        $sources = $failoverChannel->sources()->get();

        if ($sources->isEmpty()) {
            Log::channel('ffmpeg')->error("Failover Channel {$failoverChannel->name} (ID: {$failoverChannel->id}) has no sources defined.");
            return response("Failover channel has no sources.", 500);
        }

        Log::channel('ffmpeg')->info("Sources for {$failoverChannel->name}: " . $sources->map(function ($s) { return $s->name ?? $s->title ?? $s->id; })->toJson() . ". Speed threshold: {$failoverChannel->speed_threshold}x");

        $settings = $this->getStreamSettings();
        $baseUserAgent = $settings['ffmpeg_user_agent']; 

        foreach ($sources as $sourceChannel) {
            $currentStreamUserAgent = $sourceChannel->playlist->user_agent ?? $baseUserAgent;
            $escapedUserAgent = escapeshellarg($currentStreamUserAgent);

            Log::channel('ffmpeg')->info("Attempting source: {$sourceChannel->name} (ID: {$sourceChannel->id}) for Failover Channel {$failoverChannel->name}");

            $streamUrl = $sourceChannel->url_custom ?? $sourceChannel->url;
            $currentStreamTitle = strip_tags($sourceChannel->title_custom ?? $sourceChannel->title ?? $sourceChannel->name ?? "Source {$sourceChannel->id}");
            
            // --- FFprobe Pre-check START ---
            Log::channel('ffmpeg')->info("[PRE-CHECK] Attempting ffprobe for source: {$currentStreamTitle} (URL: {$streamUrl})");

            // Determine the command/path for ffmpeg execution first
            $ffmpegCommandToExecute = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegCommandToExecute)) {
                $ffmpegCommandToExecute = 'jellyfin-ffmpeg'; // Default ffmpeg command
            }

            // Now, derive the ffprobe path based on ffmpegCommandToExecute
            if (str_contains($ffmpegCommandToExecute, '/')) {
                // If $ffmpegCommandToExecute is a full path (e.g., /usr/bin/ffmpeg),
                // assume ffprobe is in the same directory.
                $ffprobePath = dirname($ffmpegCommandToExecute) . '/ffprobe';
            } else {
                // If $ffmpegCommandToExecute is just a command name (e.g., 'jellyfin-ffmpeg' or 'ffmpeg'),
                // assume 'ffprobe' is the command for ffprobe and is in the system PATH.
                $ffprobePath = 'ffprobe';
            }

            $precheckCmd = $ffprobePath . " -v quiet -print_format json -show_streams -select_streams v:0 -user_agent " . escapeshellarg($currentStreamUserAgent) . " -multiple_requests 1 -reconnect_on_network_error 1 -reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 -reconnect_delay_max 2 -timeout 5000000 " . escapeshellarg($streamUrl);
            Log::channel('ffmpeg')->info("[PRE-CHECK] Executing ffprobe command for [{$currentStreamTitle}]: {$precheckCmd}");
            
            $precheckProcess = SymfonyProcess::fromShellCommandline($precheckCmd);
            $precheckProcess->setTimeout(7); // 7-second timeout for ffprobe

            try {
                $precheckProcess->run();
                if (!$precheckProcess->isSuccessful()) {
                    Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$currentStreamTitle}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                    continue; // Try next source
                }
                Log::channel('ffmpeg')->info("[PRE-CHECK] ffprobe successful for source [{$currentStreamTitle}].");
            } catch (Exception $e) { // Catches ProcessTimedOutException, ProcessFailedException etc. from run()
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe exception for source [{$currentStreamTitle}]: " . $e->getMessage());
                continue; // Try next source
            }
            // --- FFprobe Pre-check END ---

            // Reset status for the main FFmpeg attempt, only if ffprobe passed
            $status = ['lowSpeedCount' => 0, 'processFailed' => false, 'clientAborted' => false];
            
            $ffmpegPath = $ffmpegCommandToExecute; // Use the resolved command for the main ffmpeg execution

            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = '';

            $videoCodec = $sourceChannel->video_codec_custom ?? $settings['ffmpeg_codec_video'];
            $audioCodec = $sourceChannel->audio_codec_custom ?? $settings['ffmpeg_codec_audio'];
            $subtitleCodec = $sourceChannel->subtitle_codec_custom ?? $settings['ffmpeg_codec_subtitles'];

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = 'h264_vaapi';
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$settings['ffmpeg_vaapi_device']} ";
                $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_vaapi_video_filter'], "'") . "' ";
                }
            }

            $outputFormatString = $format === 'ts'
                ? "-c:v $videoCodec -c:a $audioCodec -c:s $subtitleCodec -f mpegts pipe:1"
                : "-c:v $videoCodec -ac 2 -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
            
            $userArgs = $settings['ffmpeg_additional_args'] ?? '';
            if (!empty($userArgs) && substr($userArgs, -1) !== ' ') $userArgs .= ' ';

            $cmd = $ffmpegPath . ' ';
            $cmd .= $hwaccelInitArgs;
            $cmd .= $hwaccelArgs;
            $cmd .= "-user_agent ".$escapedUserAgent." -referer \"MyComputer\" " .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5';
            if (stripos($streamUrl, '.mkv') !== false) {
                $cmd .= ' -analyzeduration 10M -probesize 10M';
            }
            $cmd .= ' -noautorotate ';
            $cmd .= $userArgs;
            $cmd .= $codecSpecificArgs;
            $cmd .= '-re -i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs;
            $cmd .= $outputFormatString;
            $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -loglevel error');

            Log::channel('ffmpeg')->info("Executing FFmpeg for source [{$currentStreamTitle}]: {$cmd}");
            
            // $status is now reset before this try block thanks to the pre-check logic above

            try {
                return new StreamedResponse(function () use ($cmd, $currentStreamTitle, $failoverChannel, &$status) {
                    ignore_user_abort(false);
                    while (ob_get_level()) ob_end_flush();
                    flush();
                    ini_set('zlib.output_compression', 0);

                    // Use the aliased SymfonyProcess
                    $process = SymfonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);

                    $process->run(function ($type, $buffer) use ($currentStreamTitle, $failoverChannel, &$status) {
                        if (connection_aborted()) {
                            Log::channel('ffmpeg')->info("Connection aborted by client for [{$currentStreamTitle}].");
                            $status['clientAborted'] = true;
                            throw new Exception("CLIENT_ABORTED_FAILOVER"); 
                        }

                        if ($type === SymfonyProcess::OUT) { // Use aliased SymfonyProcess here too
                            echo $buffer;
                            flush();
                            usleep(10000); 
                        } elseif ($type === SymfonyProcess::ERR) { // And here
                            $lines = preg_split('/\r?\n/', trim($buffer)); // Corrected regex for preg_split
                            foreach ($lines as $line) {
                                if (preg_match('/speed=\s*([0-9\.]+x)/', $line, $matches)) {
                                    $speedValue = rtrim($matches[1], 'x');
                                    Log::channel('ffmpeg')->info("FFmpeg speed for [{$currentStreamTitle}]: {$speedValue}x");
                                    if ((float)$speedValue < (float)$failoverChannel->speed_threshold && (float)$speedValue > 0.0) {
                                        $status['lowSpeedCount']++;
                                        Log::channel('ffmpeg')->warning("Low speed count for [{$currentStreamTitle}]: {$status['lowSpeedCount']}");
                                        if ($status['lowSpeedCount'] >= 3) {
                                            Log::channel('ffmpeg')->error("Speed for [{$currentStreamTitle}] consistently below threshold ({$failoverChannel->speed_threshold}x). Triggering failover by exception.");
                                            throw new LowSpeedException("FFMPEG_SPEED_LOW_FAILOVER");
                                        }
                                    } else {
                                        $status['lowSpeedCount'] = 0; 
                                    }
                                } elseif (!empty(trim($line))) {
                                    Log::channel('ffmpeg')->error("FFmpeg (source: [{$currentStreamTitle}]): {$line}");
                                }
                            }
                        }
                    });

                    if (!$process->isSuccessful() && !$status['clientAborted'] && !($process->getExitCode() === null && $process->isRunning())) {
                        Log::channel('ffmpeg')->error("FFmpeg process for source [{$currentStreamTitle}] was not successful. Exit code: " . $process->getExitCode() . ". Output: " . $process->getErrorOutput());
                        $status['processFailed'] = true; 
                    }
                }, 200, [
                    'Content-Type' => $format === 'ts' ? 'video/MP2T' : 'video/mp4',
                    'Connection' => 'keep-alive',
                    'Cache-Control' => 'no-store, no-transform',
                    'Content-Disposition' => 'inline; filename="stream.' . $format . '"',
                    'X-Accel-Buffering' => 'no',
                ]);

            } catch (LowSpeedException $e) {
                Log::channel('ffmpeg')->info("Low speed detected for source [{$currentStreamTitle}]. Trying next source for Failover Channel [{$failoverChannel->name}].");
                continue; 
            } catch (Exception $e) {
                if ($e->getMessage() === "CLIENT_ABORTED_FAILOVER") {
                    Log::channel('ffmpeg')->info("Client aborted during stream of [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]. Stopping failover attempts.");
                    return response("Stream aborted by client.", 499); 
                }
                Log::channel('ffmpeg')->error("Error streaming source [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]: " . $e->getMessage());
                continue; 
            }
        }

        Log::channel('ffmpeg')->error("All sources for Failover Channel {$failoverChannel->name} failed.");
        return response("All sources failed or no suitable stream found.", 503); 
    }
    
    protected function getStreamSettings(): array
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
            'ffmpeg_vaapi_enabled' => false,
            'ffmpeg_vaapi_device' => '/dev/dri/renderD128',
            'ffmpeg_vaapi_video_filter' => 'scale_vaapi=format=nv12',
        ];

        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $settings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $settings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $settings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $settings['ffmpeg_path'],
                'ffmpeg_vaapi_enabled' => $userPreferences->ffmpeg_vaapi_enabled ?? $settings['ffmpeg_vaapi_enabled'],
                'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $settings['ffmpeg_vaapi_device'],
                'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $settings['ffmpeg_vaapi_video_filter'],
            ];
            $settings['ffmpeg_additional_args'] = config('proxy.ffmpeg_additional_args', '');
        } catch (Exception $e) {
            Log::error("Error fetching stream settings: " . $e->getMessage());
        }
        return $settings;
    }

    public function serveHlsPlaylist(Request $request, FailoverChannel $failoverChannel)
    {
        Log::channel('ffmpeg')->info("HLS playlist requested for Failover Channel: {$failoverChannel->name} (ID: {$failoverChannel->id})");

        $sessionCacheKey = 'hls:failover_session:' . $failoverChannel->id;
        $sessionData = Cache::get($sessionCacheKey);

        $sources = $sessionData['sources_list'] ?? null;
        $currentSourceIndex = $sessionData['current_source_index'] ?? -1;
        $currentSource = null;
        $pid = $sessionData['current_ffmpeg_pid'] ?? null;
        $currentSourceChannelId = $sessionData['current_source_channel_id'] ?? null;

        if ($sources === null) {
            Log::channel('ffmpeg')->info("HLS Failover: No session found for {$failoverChannel->name}. Initializing sources.");
            $allEnabledSources = $failoverChannel->sources()
                                               ->where('channels.enabled', true)
                                               ->orderBy('pivot_order', 'asc') // Ensure 'pivot_order' is correct
                                               ->get();

            if ($allEnabledSources->isEmpty()) {
                Log::channel('ffmpeg')->error("HLS Failover: No enabled sources for {$failoverChannel->name}.");
                abort(404, 'No enabled sources found for this failover channel.');
            }

            $sources = $allEnabledSources->map(function ($src) {
                return [
                    'id' => $src->id,
                    'url' => $src->url_custom ?? $src->url,
                    'user_agent' => $src->playlist->user_agent ?? null,
                    'title' => strip_tags($src->title_custom ?? $src->title ?? $src->name ?? "Source {$src->id}")
                ];
            })->toArray();
            
            $currentSourceIndex = -1; // Will be incremented before first use
            $pid = null;
            Log::channel('ffmpeg')->info("HLS Failover: Sources initialized for {$failoverChannel->name}. Found " . count($sources) . " sources.");
        }

        if ($pid !== null && $currentSourceChannelId !== null && $this->hlsService->isRunning('channel', $currentSourceChannelId)) {
            // Current source is presumably healthy
            $currentSource = $sources[$currentSourceIndex];
            Log::channel('ffmpeg')->info("HLS Failover: Existing FFmpeg process (PID: {$pid}) for source {$currentSource['id']} is running for {$failoverChannel->name}.");
        } else {
            if ($pid !== null) {
                 Log::channel('ffmpeg')->warning("HLS Failover: FFmpeg process (PID: {$pid}) for source {$currentSourceChannelId} is no longer running for {$failoverChannel->name}. Attempting next source.");
            }
            $currentSourceIndex++;

            if ($currentSourceIndex >= count($sources)) {
                Cache::forget($sessionCacheKey);
                Log::channel('ffmpeg')->error("HLS Failover: All sources exhausted for {$failoverChannel->name}.");
                abort(503, "All HLS sources for Failover Channel {$failoverChannel->name} failed or are unavailable.");
            }

            $currentSource = $sources[$currentSourceIndex];
            $currentSourceChannelId = $currentSource['id']; // Update currentSourceChannelId
            Log::channel('ffmpeg')->info("HLS Failover: Attempting to start stream for new source {$currentSource['id']} ({$currentSource['title']}) at index {$currentSourceIndex} for {$failoverChannel->name}.");

            try {
                // Stop any potentially lingering process for a *different* source ID from a previous failover attempt for this *same* failover channel session
                if ($pid !== null && $sessionData['current_source_channel_id'] !== $currentSource['id']) {
                    Log::channel('ffmpeg')->info("HLS Failover: Stopping lingering FFmpeg (PID: {$pid}) for old source {$sessionData['current_source_channel_id']} before starting new one for {$currentSource['id']}.");
                    $this->hlsService->stopStream('channel', $sessionData['current_source_channel_id']);
                }

                $pid = $this->hlsService->startStream(
                    type: 'channel',
                    id: $currentSource['id'],
                    streamUrl: $currentSource['url'],
                    title: $currentSource['title'],
                    userAgent: $currentSource['user_agent']
                );
                Log::channel('ffmpeg')->info("HLS Failover: Successfully started FFmpeg (PID: {$pid}) for source {$currentSource['id']} for {$failoverChannel->name}.");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("HLS Failover: Failed to start stream for source {$currentSource['id']} (Failover: {$failoverChannel->name}): " . $e->getMessage());
                $pid = null; // Ensure pid is null if startStream failed
            }
        }
        
        // Update session state
        $sessionData = [
            'sources_list' => $sources,
            'current_source_index' => $currentSourceIndex,
            'current_source_channel_id' => $currentSource['id'], // Use ID from the definitely assigned $currentSource
            'current_ffmpeg_pid' => $pid
        ];
        Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
        Log::channel('ffmpeg')->info("HLS Failover: Session updated for {$failoverChannel->name}. Current source ID: {$currentSource['id']}, PID: {$pid}.");

        // Serve Playlist
        $playlistPath = Storage::disk('app')->path("hls/{$currentSource['id']}/stream.m3u8");
        $playlistReady = false;
        for ($i = 0; $i < 10; $i++) { // 10 attempts, 1 sec sleep
            if (file_exists($playlistPath) && filesize($playlistPath) > 0) {
                $playlistReady = true;
                break;
            }
            if (!$this->hlsService->isRunning('channel', $currentSource['id'])) {
                Log::channel('ffmpeg')->error("HLS Failover: FFmpeg process (PID: {$pid}) for source {$currentSource['id']} died while waiting for playlist for {$failoverChannel->name}.");
                // Clear PID from session so next request attempts failover
                $sessionData['current_ffmpeg_pid'] = null;
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                abort(503, "Playlist generation failed as stream ended prematurely. Please try again.");
            }
            sleep(1);
        }

        if ($playlistReady) {
            Log::channel('ffmpeg')->info("HLS Failover: Playlist found for source {$currentSource['id']}. Serving to client for {$failoverChannel->name}. Path: {$playlistPath}");
            return response('', 200, [
                'Content-Type'      => 'application/vnd.apple.mpegurl',
                'X-Accel-Redirect'  => "/internal/hls/{$currentSource['id']}/stream.m3u8",
                'Cache-Control'     => 'no-cache, no-transform',
                'Connection'        => 'keep-alive',
            ]);
        } else {
            if (!$this->hlsService->isRunning('channel', $currentSource['id'])) {
                Log::channel('ffmpeg')->error("HLS Failover: Playlist not found and FFmpeg process (PID: {$pid}) for source {$currentSource['id']} is NOT running for {$failoverChannel->name}. Forcing re-evaluation.");
                Cache::forget($sessionCacheKey); // Force re-evaluation on next request
                abort(404, "Playlist not found for current source and process died.");
            } else {
                Log::channel('ffmpeg')->warning("HLS Failover: Playlist for source {$currentSource['id']} not ready after 10s for {$failoverChannel->name}, but FFmpeg (PID: {$pid}) still running. Client should retry.");
                abort(503, "Playlist not ready, please try again shortly.");
            }
        }
    }
}
