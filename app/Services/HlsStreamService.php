<?php

namespace App\Services;

use Exception;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HlsStreamService
{
    /**
     * Start an HLS stream for the given channel.
     *
     * @param string $id
     * @param string $streamUrl
     * @param string $title
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     */
    public function startStream(
        $id,
        $streamUrl,
        $title,
        $userAgent = null,
    ): int {
        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey);
        if (!($this->isRunning($id))) {
            // Get user preferences
            $userPreferences = app(GeneralSettings::class);
            $settings = [
                'ffmpeg_debug' => false,
                'ffmpeg_max_tries' => 3,
                'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
                'ffmpeg_codec_video' => 'libx264',
                'ffmpeg_codec_audio' => 'aac',
                'ffmpeg_codec_subtitles' => 'copy',
                'ffmpeg_path' => 'jellyfin-ffmpeg',
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
                ];
            } catch (Exception $e) {
                // Ignore
            }

            // Get user agent
            if (!$userAgent) {
                $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);
            }

            // Get ffmpeg path
            $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegPath)) {
                $ffmpegPath = 'jellyfin-ffmpeg';
            }

            // Get ffmpeg output codec formats
            $videoCodec = config('proxy.ffmpeg_codec_video') ?: $settings['ffmpeg_codec_video'];
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
            $outputFormat = "-c:v $videoCodec -c:a $audioCodec -bsf:a aac_adtstoasc -c:s $subtitleCodec";

            // Initialize hardware acceleration arguments string
            $hwaccelArgsString = '';
            if (str_contains($videoCodec, '_qsv')) {
                // Ensure a trailing space if hwaccel args are added
                $hwaccelArgsString = '-hwaccel qsv -qsv_device /dev/dri/renderD128 ';
            } elseif (str_contains($videoCodec, '_vaapi')) {
                // Ensure a trailing space if hwaccel args are added
                $hwaccelArgsString = '-hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi ';
            }

            // Get user defined options
            $userArgs = config('proxy.ffmpeg_additional_args', '');
            if (!empty($userArgs)) {
                $userArgs .= ' ';
            }

            // Setup the stream file paths
            $storageDir = Storage::disk('app')->path("hls/{$id}");
            File::ensureDirectoryExists($storageDir, 0755);

            // Setup the stream URL
            $m3uPlaylist = "{$storageDir}/stream.m3u8";
            $segment = "{$storageDir}/segment_%03d.ts";
            $segmentBaseUrl = url("/api/stream/{$id}") . '/';

            $cmd = sprintf(
                $ffmpegPath . ' ' .
                    // Optimization options:
                    '-fflags nobuffer -flags low_delay ' .
                    // Hardware acceleration (includes trailing space if not empty)
                    '%s' .
                    // Pre-input HTTP options:
                    '-user_agent "%s" -referer "MyComputer" ' .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ' .
                    // User defined options:
                    '%s' .
                    // I/O options:
                    '-re -i "%s" ' .
                    // Output options:
                    '-preset veryfast -g 15 -keyint_min 15 -sc_threshold 0 ' .
                    '%s ' . // output format
                    // HLS options:
                    '-f hls -hls_time 2 -hls_list_size 6 ' .
                    '-hls_flags delete_segments+append_list+independent_segments ' .
                    '-use_wallclock_as_timestamps 1 ' .
                    '-hls_segment_filename %s ' .
                    '-hls_base_url %s %s ' .
                    // Logging:
                    '%s',
                $hwaccelArgsString,           // QSV hardware acceleration arguments (or empty string)
                $userAgent,                   // for -user_agent
                $userArgs,                    // user defined options
                $streamUrl,                   // input URL
                $outputFormat,                // output format
                $segment,                     // segment filename
                $segmentBaseUrl,              // base URL for segments
                $m3uPlaylist,                 // playlist filename
                $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
            );

            // Log the command for debugging
            Log::channel('ffmpeg')->info("Streaming channel {$title} with command: {$cmd}");

            // Tell proc_open to give us back a stderr pipe
            $descriptors = [
                0 => ['pipe', 'r'], // stdin (we won't use)
                1 => ['pipe', 'w'], // stdout (we won't use)
                2 => ['pipe', 'w'], // stderr (we will log)
            ];
            $pipes = [];
            $process = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($process)) {
                Log::channel('ffmpeg')->error("Failed to launch FFmpeg for channel {$id}");
                abort(500, 'Could not start stream.');
            }

            // Immediately close stdin/stdout
            fclose($pipes[0]);
            fclose($pipes[1]);

            // Make stderr non-blocking
            stream_set_blocking($pipes[2], false);

            // Spawn a little "reader" that pulls from stderr and logs
            $logger = Log::channel('ffmpeg');
            $stderr = $pipes[2];

            // Register shutdown function to ensure the pipe is drained
            register_shutdown_function(function () use ($stderr, $process, $logger) {
                while (!feof($stderr)) {
                    $line = fgets($stderr);
                    if ($line !== false) {
                        $logger->error(trim($line));
                    }
                }
                fclose($stderr);
                proc_close($process);
            });

            // Cache the actual FFmpeg PID
            $status = proc_get_status($process);
            $pid = $status['pid']; // This is the actual FFmpeg PID
            Cache::forever("hls:pid:{$id}", $pid);

            // Record timestamp in Redis (never expires until we prune) - Old system
            Redis::set("hls:last_seen:{$id}", now()->timestamp);

            // Add to active IDs set - Old system
            Redis::sadd('hls:active_ids', $id);

            // New Stream Statistics Logging
            $app_stream_id = "hls_{$id}_{$pid}";

            $hw_accel_method_used = "None";
            if (str_contains($videoCodec, '_vaapi')) {
                $hw_accel_method_used = "VAAPI";
            } elseif (str_contains($videoCodec, '_qsv')) {
                $hw_accel_method_used = "QSV";
            }

            $streamData = [
                'stream_id' => $app_stream_id,
                'channel_id' => $id,
                'channel_title' => $title,
                'client_ip' => "N/A", // Not available at this service level for HLS
                'user_agent_raw' => "N/A", // $userAgent is FFmpeg's, not the client's
                'stream_type' => "HLS_STREAM",
                'stream_format_requested' => "hls",
                'video_codec_selected' => $videoCodec,
                'audio_codec_selected' => $audioCodec,
                'hw_accel_method_used' => $hw_accel_method_used,
                'ffmpeg_pid' => $pid,
                'start_time_unix' => time(),
                'source_stream_url' => $streamUrl,
                'ffmpeg_command' => $cmd,
            ];
            Redis::hmset("stream_stats:details:{$app_stream_id}", $streamData);
            Redis::sadd("stream_stats:active_ids", $app_stream_id);
            Redis::expire("stream_stats:details:{$app_stream_id}", 10800); // 3 hours expiry

        }
        return $pid;
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $id
     * @return bool
     */
    public function stopStream($id): bool
    {
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey); // Get PID from cache first
        $wasRunning = false;

        // Attempt to clean up Redis stats if PID was cached, regardless of current running state
        if ($pid) {
            $app_stream_id = "hls_{$id}_{$pid}";
            Redis::del("stream_stats:details:{$app_stream_id}");
            Redis::srem("stream_stats:active_ids", $app_stream_id);
        }

        if ($this->isRunning($id)) { // isRunning uses its own Cache::get call, but $pid here is from our earlier call
            $wasRunning = true;
            // Attempt to gracefully stop the FFmpeg process
            posix_kill($pid, SIGTERM);
            sleep(1);
            if (posix_kill($pid, 0)) {
                // If the process is still running after SIGTERM, force kill it
                posix_kill($pid, SIGKILL);
            }
            Cache::forget($cacheKey); // Forget the PID from cache

            // Cleanup on-disk HLS files
            $storageDir = Storage::disk('app')->path("hls/{$id}");
            File::deleteDirectory($storageDir);
        } else {
            // If not running, but we had a PID, we've already attempted cleanup.
            // If PID was not in cache, $pid would be null, and no specific app_stream_id cleanup for stats occurs.
            // Log warning if we intended to stop a specific process but it wasn't found running.
            if (Cache::has($cacheKey) && !$pid) { 
                 // This case should ideally not be hit if isRunning uses the same Cache::get logic
                 Log::channel('ffmpeg')->warning("FFmpeg process for channel {$id} was cached but PID not retrieved for stats cleanup, or process already gone.");
            } else if (!$pid) {
                 Log::channel('ffmpeg')->warning("No cached FFmpeg PID for channel {$id} to stop or clean up stats.");
            } else {
                 Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop (PID: {$pid}). Stats cleanup attempted.");
            }
            // Ensure cache key is forgotten if it somehow still exists but process is not running
            Cache::forget($cacheKey);
        }

        // Remove from active IDs set (Old system)
        Redis::srem('hls:active_ids', $id);


        return $wasRunning;
    }

    /**
     * Check if an HLS stream is currently running for the given channel ID.
     *
     * @param string $id
     * @return bool
     */
    public function isRunning($id): bool
    {
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream for the given channel ID.
     *
     * @param string $id
     * @return bool
     */
    public function getPid($id): ?int
    {
        $cacheKey = "hls:pid:{$id}";
        return Cache::get($cacheKey);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        return true;
        //
        // TODO: This is a placeholder for the actual implementation.
        //       Currently not working, seems like the process is not flagged correctly (always false).
        //       Need to do some more investigation...
        //
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (! file_exists($cmdlinePath)) {
            return false;
        }

        $cmd = @file_get_contents($cmdlinePath);
        // FFmpeg’s binary name should appear first
        return $cmd && strpos($cmd, 'ffmpeg') !== false;
    }
}
