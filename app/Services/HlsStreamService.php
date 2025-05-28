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
    public static function determineVideoCodec(?string $codecFromConfig, ?string $codecFromSettings): string
    {
        if ($codecFromConfig !== null && $codecFromConfig !== '') {
            return $codecFromConfig;
        } elseif ($codecFromSettings !== null && $codecFromSettings !== '') {
            return $codecFromSettings;
        } else {
            return 'copy'; // Default to 'copy'
        }
    }

    /**
     * Start an HLS stream for the given channel.
     *
     * @param string $type
     * @param string $id
     * @param string $streamUrl
     * @param string $title
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     */
    public function startStream(
        $type,
        $id,
        $streamUrl,
        $title,
        $userAgent = null,
        $playlistProfileId = null
    ): int {
        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        if (!($this->isRunning($type, $id))) {
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
                // VA-API defaults should remain
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
                    
                    // VA-API settings
                    'ffmpeg_vaapi_enabled' => $userPreferences->ffmpeg_vaapi_enabled ?? $settings['ffmpeg_vaapi_enabled'],
                    'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $settings['ffmpeg_vaapi_device'],
                    'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $settings['ffmpeg_vaapi_video_filter'],

                    // QSV settings
                    'ffmpeg_qsv_enabled' => $userPreferences->ffmpeg_qsv_enabled ?? false, // Default from GeneralSettings
                    'ffmpeg_qsv_device' => $userPreferences->ffmpeg_qsv_device ?? '/dev/dri/renderD128', // Default from GeneralSettings
                    'ffmpeg_qsv_video_filter' => $userPreferences->ffmpeg_qsv_video_filter ?? 'vpp_qsv=format=nv12', // Default from GeneralSettings
                    'ffmpeg_qsv_encoder_options' => $userPreferences->ffmpeg_qsv_encoder_options ?? null, // Default from GeneralSettings
                    'ffmpeg_qsv_additional_args' => $userPreferences->ffmpeg_qsv_additional_args ?? null, // Default from GeneralSettings
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
            $codecFromConfig = config('proxy.ffmpeg_codec_video', null);
            // $settings['ffmpeg_codec_video'] is already populated a few lines above this
            $videoCodec = self::determineVideoCodec($codecFromConfig, $settings['ffmpeg_codec_video']);
            $audioCodec = config('proxy.ffmpeg_codec_audio', null) ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles', null) ?: $settings['ffmpeg_codec_subtitles'];

            // Initialize Hardware Acceleration and Codec Specific Argument Variables
            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = ''; // For codec specific options like -profile:v

            // Get user defined general options (these might be appended to by hw accel logic)
            $userArgs = config('proxy.ffmpeg_additional_args', '');

            // Hardware Acceleration Logic
            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = 'h264_vaapi'; // Default VA-API H.264 encoder for HLS
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " ";
                $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_vaapi_video_filter'], "'") . "' ";
                }
                // $codecSpecificArgs typically remains empty for VA-API HLS, unless specific encoder options are needed.
            } elseif ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $videoCodec = 'h264_qsv'; // Default QSV H.264 encoder
                $qsvDeviceName = 'qsv_hw'; // Internal handle for FFmpeg hw device
                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                $hwaccelArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_qsv_video_filter'], "'") . "' ";
                }
                if (!empty($settings['ffmpeg_qsv_encoder_options'])) {
                    $codecSpecificArgs = trim($settings['ffmpeg_qsv_encoder_options']) . " ";
                }
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    // Append QSV-specific additional args to the general userArgs
                    // Ensure space separation if $userArgs already has content.
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }
            // If neither VA-API nor QSV is enabled, $videoCodec remains its default value (e.g., 'libx264'),
            // and hwaccel/filter args remain empty.

            // Update $outputFormat (must be after HW Accel logic)
            // Ensure $codecSpecificArgs has a trailing space if it's not empty.
            $outputFormat = "-c:v $videoCodec " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a $audioCodec -bsf:a aac_adtstoasc -c:s $subtitleCodec";

            // Ensure $userArgs has a trailing space if not empty, before being used in the command
            if (!empty($userArgs)) {
                $userArgs .= ' ';
            }

            // Setup the stream file paths
            if ($type === 'episode') {
                $storageDir = Storage::disk('app')->path("hls/e/{$id}");
            } else {
                $storageDir = Storage::disk('app')->path("hls/{$id}");
            }
            File::ensureDirectoryExists($storageDir, 0755);

            // Setup the stream URL (ensure paths are escaped for command line)
            $m3uPlaylist = "{$storageDir}/stream.m3u8";
            $segment = "{$storageDir}/segment_%03d.ts"; // %03d is an ffmpeg pattern, not a variable here.
            $segmentBaseUrl = $type === 'channel'
                ? url("/api/stream/{$id}") . '/'
                : url("/api/stream/e/{$id}") . '/';

            // Reconstruct FFmpeg Command
            $cmd = $ffmpegPath . ' ';
            $cmd .= $hwaccelInitArgs; 
            $cmd .= $hwaccelArgs;     

            $cmd .= '-fflags nobuffer -flags low_delay ';

            // Pre-input HTTP options ($userAgent is already escaped from earlier logic):
            $cmd .= "-user_agent ".$userAgent." -referer \"MyComputer\" " .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ';

            $cmd .= $userArgs;
            // $codecSpecificArgs is now part of $outputFormat

            $cmd .= '-re -i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; 
            
            // $cmd .= '-preset veryfast -g 15 -keyint_min 15 -sc_threshold 0 ';
            $cmd .= $outputFormat . ' ';

            $cmd .= '-f hls -hls_time 2 -hls_list_size 6 ' .
                    '-hls_flags delete_segments+append_list+independent_segments ' .
                    '-use_wallclock_as_timestamps 1 ' .
                    '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
                    '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
                    escapeshellarg($m3uPlaylist) . ' ';

            $cmd .= ($settings['ffmpeg_debug'] ? '' : ' -hide_banner -nostats -loglevel error');

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
            $pid = $status['pid'];
            Cache::forever("hls:pid:{$type}:{$id}", $pid);

            // Handle PlaylistProfile connection counting
            if ($playlistProfileId) {
                $profileRedisKey = "profile_connections:" . $playlistProfileId;
                Redis::incr($profileRedisKey);
                Log::channel('ffmpeg')->info("HLS: Incremented profile_connections for profile ID: {$playlistProfileId}. Current: " . Redis::get($profileRedisKey));
                Cache::forever("hls:profile_id:{$type}:{$id}", $playlistProfileId);
            }

            // Record timestamp in Redis (never expires until we prune)
            Redis::set("hls:{$type}_last_seen:{$id}", now()->timestamp);

            // Add to active IDs set
            Redis::sadd("hls:active_{$type}_ids", $id);
        }
        return $pid;
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function stopStream($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;
        if ($this->isRunning($type, $id)) {
            $wasRunning = true;
            // Attempt to gracefully stop the FFmpeg process
            posix_kill($pid, SIGTERM);
            sleep(1);
            if (posix_kill($pid, 0)) {
                // If the process is still running after SIGTERM, force kill it
                posix_kill($pid, SIGKILL);
            }

        // Handle PlaylistProfile connection counting
        $profileIdCacheKey = "hls:profile_id:{$type}:{$id}";
        $playlistProfileIdToDecrement = Cache::get($profileIdCacheKey);
        if ($playlistProfileIdToDecrement) {
            $profileRedisKey = "profile_connections:" . $playlistProfileIdToDecrement;
            Redis::decr($profileRedisKey);
            Log::channel('ffmpeg')->info("HLS: Decremented profile_connections for profile ID: {$playlistProfileIdToDecrement}. Current: " . Redis::get($profileRedisKey));
        }
        Cache::forget($profileIdCacheKey); // Always forget, even if it was null

            Cache::forget($cacheKey);

            // Cleanup on-disk HLS files
            $storageDir = Storage::disk('app')->path("hls/{$id}");
            File::deleteDirectory($storageDir);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        // Remove from active IDs set
        Redis::srem("hls:active_{$type}_ids", $id);

        return $wasRunning;
    }

    /**
     * Check if an HLS stream is currently running for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function isRunning($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function getPid($type, $id): ?int
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
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
