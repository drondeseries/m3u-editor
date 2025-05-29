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
            
            // Existing $settings array with defaults
            $baseSettings = [
                'ffmpeg_debug' => false,
                'ffmpeg_max_tries' => 3,
                'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
                'ffmpeg_codec_video' => 'libx264', // Default, might be overridden by user
                'ffmpeg_codec_audio' => 'aac',
                'ffmpeg_codec_subtitles' => 'copy',
                'ffmpeg_path' => 'jellyfin-ffmpeg',
                'hardware_acceleration_method' => 'none', // Default for the method itself
                'ffmpeg_vaapi_device' => '/dev/dri/renderD128',
                'ffmpeg_vaapi_video_filter' => '', // Note: this filter implies full hw pipeline
                'ffmpeg_qsv_device' => '/dev/dri/renderD128',
                'ffmpeg_qsv_video_filter' => 'vpp_qsv=format=nv12', // Note: this filter implies full hw pipeline
                'ffmpeg_qsv_encoder_options' => null,
                'ffmpeg_qsv_additional_args' => null,
            ];

            try {
                // Override defaults with user preferences if they exist
                $settings = [
                    'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $baseSettings['ffmpeg_debug'],
                    'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $baseSettings['ffmpeg_max_tries'],
                    'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $baseSettings['ffmpeg_user_agent'],
                    'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $baseSettings['ffmpeg_codec_video'],
                    'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $baseSettings['ffmpeg_codec_audio'],
                    'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $baseSettings['ffmpeg_codec_subtitles'],
                    'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $baseSettings['ffmpeg_path'],
                    // Key change: Use hardware_acceleration_method from user preferences
                    'hardware_acceleration_method' => $userPreferences->hardware_acceleration_method ?? $baseSettings['hardware_acceleration_method'],
                    // VA-API specific device/filter settings
                    'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $baseSettings['ffmpeg_vaapi_device'],
                    'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $baseSettings['ffmpeg_vaapi_video_filter'],
                    // QSV specific device/filter/options settings
                    'ffmpeg_qsv_device' => $userPreferences->ffmpeg_qsv_device ?? $baseSettings['ffmpeg_qsv_device'],
                    'ffmpeg_qsv_video_filter' => $userPreferences->ffmpeg_qsv_video_filter ?? $baseSettings['ffmpeg_qsv_video_filter'],
                    'ffmpeg_qsv_encoder_options' => $userPreferences->ffmpeg_qsv_encoder_options ?? $baseSettings['ffmpeg_qsv_encoder_options'],
                    'ffmpeg_qsv_additional_args' => $userPreferences->ffmpeg_qsv_additional_args ?? $baseSettings['ffmpeg_qsv_additional_args'],
                ];
            } catch (Exception $e) {
                // If $userPreferences isn't loaded or causes an error, fall back to baseSettings
                $settings = $baseSettings;
                Log::warning("HlsStreamService: Could not load user preferences for FFmpeg settings. Using defaults. Error: " . $e->getMessage());
            }

            // Get user agent (ensure it's properly escaped when used, or use the settings one)
            // $escapedUserAgent = escapeshellarg($userAgent ?: $settings['ffmpeg_user_agent']);
            // Using settings['ffmpeg_user_agent'] directly in the command construction later, will be escaped there.

            // Get ffmpeg path
            $ffmpegPath = config('proxy.ffmpeg_path') ?: ($settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg');

            // Determine the effective video codec based on config and settings
            $finalVideoCodec = self::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null), 
                $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
            );

            // Initialize Hardware Acceleration and Codec Specific Argument Variables
            $hwaccelInitArgs = '';    // For -init_hw_device
            $hwaccelInputArgs = '';   // For -hwaccel options before input (e.g., -hwaccel vaapi -hwaccel_output_format vaapi)
            $videoFilterArgs = '';    // For -vf
            $codecSpecificArgs = '';  // For encoder options like -profile:v, -preset, etc.
            $outputVideoCodec = $finalVideoCodec; // This might be overridden by hw accel logic

            // Get user defined general options from config/proxy.php
            $userArgs = config('proxy.ffmpeg_additional_args', '');

            // --- Hardware Acceleration Setup ---

            // VA-API Settings from GeneralSettings
            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
            $vaapiFilterFromSettings = $settings['ffmpeg_vaapi_video_filter'] ?? ''; 

            // QSV Settings from GeneralSettings
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
            $qsvFilterFromSettings = $settings['ffmpeg_qsv_video_filter'] ?? ''; 
            $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? null;
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? null;

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if only toggle is on
                
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device:{$vaapiDevice} ";
                // These args are for full hardware acceleration (decode using VA-API)
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                
                if (!empty($vaapiFilterFromSettings)) {
                    $videoFilterArgs = "-vf '" . trim($vaapiFilterFromSettings, "'") . "' ";
                } else {
                    $videoFilterArgs = ""; // No default -vf filter
                }
                // If $vaapiFilterFromSettings is empty, no -vf is added here for VA-API.
                // FFmpeg will handle conversions if possible, or fail if direct path isn't supported.

            } elseif ($qsvEnabled || $isQsvCodec) {
                // Only apply QSV if VA-API wasn't chosen/enabled
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv
                $qsvDeviceName = 'qsv_hw'; // Internal FFmpeg label
                
                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";
                // These args are for full hardware acceleration (decode using QSV)
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";

                if (!empty($qsvFilterFromSettings)) {
                    // This filter is applied to frames already in QSV format
                    $videoFilterArgs = "-vf '" . trim($qsvFilterFromSettings, "'") . "' ";
                }
                if (!empty($qsvEncoderOptions)) {
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                }
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
                }
            }
            // If neither VA-API nor QSV is applicable, $outputVideoCodec uses $finalVideoCodec (e.g. libx264 or copy)
            // and $hwaccelInitArgs, $hwaccelInputArgs, $videoFilterArgs remain empty from hw accel logic.

            // --- End Hardware Acceleration Setup ---

            $audioCodec = config('proxy.ffmpeg_codec_audio', null) ?: ($settings['ffmpeg_codec_audio'] ?? 'copy');
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles', null) ?: ($settings['ffmpeg_codec_subtitles'] ?? 'copy');

            $outputFormat = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a {$audioCodec} -bsf:a aac_adtstoasc -c:s {$subtitleCodec}";

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

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            $cmd .= '-fflags nobuffer -flags low_delay ';

            // Use the user agent from settings, escape it. $userAgent parameter is ignored for now.
            $cmd .= "-user_agent " . escapeshellarg($settings['ffmpeg_user_agent']) . " -referer \"MyComputer\" " .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args

            $cmd .= '-re -i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'
            
            $cmd .= $outputFormat . ' ';
            // ... rest of the HLS options and command suffix ...
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
