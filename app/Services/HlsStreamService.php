<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Exceptions\SourceNotResponding;
use App\Exceptions\SourceSpeedBelowThreshold;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    // Cache configuration for bad sources
    private const BAD_SOURCE_CACHE_MINUTES = 5;
    private const BAD_SOURCE_CACHE_PREFIX = 'failover:bad_source:';

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
    ): int {
        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        if (!($this->isRunning($type, $id))) {
            // Get the command
            $cmd = $this->buildCmd($type, $id, $userAgent, $streamUrl);

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
        // On Linux systems
        if (PHP_OS_FAMILY === 'Linux' && file_exists("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");
            return $cmdline && (strpos($cmdline, 'ffmpeg') !== false);
        }

        // On macOS/BSD systems
        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $output = [];
            exec("ps -p {$pid} -o command=", $output);
            return !empty($output) && strpos($output[0], 'ffmpeg') !== false;
        }

        // Default fallback (just check if process exists)
        return posix_kill($pid, 0);
    }

    /**
     * Build the FFmpeg command for HLS streaming.
     *
     * @param string $type
     * @param string $id
     * @param string|null $userAgent
     * @param string $streamUrl
     * 
     * @return string The complete FFmpeg command
     */
    private function buildCmd(
        $type,
        $id,
        $userAgent,
        $streamUrl
    ): string {
        // Get default stream settings
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Setup the stream file paths
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::ensureDirectoryExists($storageDir, 0755);

        // Setup the stream URL
        $m3uPlaylist = "{$storageDir}/stream.m3u8";
        $segment = "{$storageDir}/segment_%03d.ts";
        $segmentBaseUrl = $type === 'channel'
            ? url("/api/stream/{$id}") . '/'
            : url("/api/stream/e/{$id}") . '/';

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine the effective video codec based on config and settings
        $finalVideoCodec = ProxyService::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Initialize Hardware Acceleration and Codec Specific Argument Variables
        $hwaccelInitArgs = '';    // For -init_hw_device
        $hwaccelInputArgs = '';   // For -hwaccel options before input (e.g., -hwaccel vaapi -hwaccel_output_format vaapi)
        $videoFilterArgs = '';    // For -vf
        $codecSpecificArgs = '';  // For encoder options like -profile:v, -preset, etc.
        $outputVideoCodec = $finalVideoCodec; // This might be overridden by hw accel logic

        // Get user agent
        if (!$userAgent) {
            $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);
        }

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Command construction logic
        if (empty($customCommandTemplate)) {
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

            // Get ffmpeg output codec formats
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
            $outputFormat = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a {$audioCodec} -bsf:a aac_adtstoasc -c:s {$subtitleCodec}";

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
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $finalVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $finalVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: 'copy';
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: 'copy';

            $outputCommandSegment = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a {$audioCodecForTemplate} -bsf:a aac_adtstoasc -c:s {$subtitleCodecForTemplate}";

            $videoCodecArgs = "-c:v {$videoCodecForTemplate}" . ($codecSpecificArgs ? " " . trim($codecSpecificArgs) : "");
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // ... rest of the options and command suffix ...
        $cmd .= ' -f hls -hls_time 2 -hls_list_size 6 ' .
            '-hls_flags delete_segments+append_list+independent_segments ' .
            '-use_wallclock_as_timestamps 1 ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections, performs pre-checks using ffprobe, and monitors for slow speed.
     *
     * @param string $type
     * @param Channel $channel The Channel model instance
     * @param string $streamUrl The URL to stream from
     * @param string $title The title of the channel
     * 
     * @return int|null The FFmpeg process ID or null if no valid stream was found
     */
    public function startStreamWithFailover(
        string $type,
        Channel $channel,
        string $streamUrl,
        string $title
    ): ?int {
        // Get the failover channels (if any)
        $sourceChannel = $channel;
        $streams = collect([$channel])->concat($channel->failoverChannels);

        // Loop over the failover channels and grab the first one that works.
        foreach ($streams as $stream) {
            // Get the title for the channel
            $title = $stream->title_custom ?? $stream->title;
            $title = strip_tags($title);

            // Make sure we have a valid source channel
            $badSourceCacheKey = self::BAD_SOURCE_CACHE_PREFIX . $stream->id;
            if (Redis::exists($badSourceCacheKey)) {
                if ($sourceChannel->id === $stream->id) {
                    Log::channel('ffmpeg')->info("Skipping source ID {$title} ({$sourceChannel->id}) for as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                } else {
                    Log::channel('ffmpeg')->info("Skipping Failover Channel {$stream->name} for source {$title} ({$sourceChannel->id}) as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                }
                continue;
            }

            // Check if playlist is specified
            $playlist = $stream->playlist;

            // Keep track of the active streams for this playlist using optimistic locking pattern
            $activeStreamsKey = "active_streams:{$playlist->id}";

            // First increment the counter
            $activeStreams = Redis::incr($activeStreamsKey);

            // Make sure we haven't gone negative for any reason, this should never be 0 or less
            if ($activeStreams <= 0) {
                Redis::set($activeStreamsKey, 1);
                $activeStreams = 1;
            }
            Log::channel('ffmpeg')->info("Active streams for playlist {$playlist->id}: {$activeStreams} (after increment)");

            // Then check if we're over limit
            if ($playlist->available_streams > 0 && $activeStreams > $playlist->available_streams) {
                // We're over limit, so decrement and skip
                Redis::decr($activeStreamsKey);
                Log::channel('ffmpeg')->info("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping channel {$title}.");
                continue;
            }

            // Setup streams array
            $streamUrl = $stream->url_custom ?? $channel->url;

            // Determine the output format
            $channelId = $stream->id;
            $userAgent = $playlist->user_agent ?? null;

            try {
                // Run pre-check with ffprobe
                $this->runPreCheck($streamUrl, $userAgent, $title);

                // Attempt to start the stream and monitor for slow speed
                return $this->startStreamWithSpeedCheck(
                    type: $type,
                    id: $stream->id,
                    streamUrl: $streamUrl,
                    title: $title,
                    userAgent: $userAgent,
                );
            } catch (SourceSpeedBelowThreshold $e) {
                // Log the error and cache the bad source
                Log::channel('ffmpeg')->error("Source speed below threshold for channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, self::BAD_SOURCE_CACHE_MINUTES * 60, $e->getMessage());

                // Try the next failover channel
                continue;
            } catch (SourceNotResponding $e) {
                // Log the error and cache the bad source
                Log::channel('ffmpeg')->error("Source not responding for channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, self::BAD_SOURCE_CACHE_MINUTES * 60, $e->getMessage());

                // Try the next failover channel
                continue;
            } catch (Exception $e) {
                // Log the error and abort
                Log::channel('ffmpeg')->error("Error streaming channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, self::BAD_SOURCE_CACHE_MINUTES * 60, $e->getMessage());

                // Try the next failover channel
                continue;
            } finally {
                Redis::decr($activeStreamsKey);
            }
        }

        // If no streams were successful, log and return null
        Log::channel('ffmpeg')->error("All streams failed for {$type} {$channelId} ({$title}).");
        return null;
    }

    /**
     * Start a stream and monitor for slow speed.
     *
     * @param string $type
     * @param string $id
     * @param string $streamUrl
     * @param string $title
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     * @throws Exception If the stream fails or speed drops below the threshold
     */
    private function startStreamWithSpeedCheck(
        $type,
        $id,
        $streamUrl,
        $title,
        $userAgent
    ): int {
        // Setup the stream
        $cmd = $this->buildCmd($type, $id, $userAgent, $streamUrl);
        $lowSpeedThreshold = (float) config('proxy.ffmpeg_low_speed_threshold', 0.9);

        // Setup and start the process
        $process = SymfonyProcess::fromShellCommandline($cmd);
        $process->setTimeout(null);
        $process->start(); // Use `start()` to make sure we're running in the background

        // Track the PID for `isRunning()`
        $pid = $process->getPid();
        Cache::forever("hls:pid:{$type}:{$id}", $pid);

        $lowSpeedCount = 0;
        while ($process->isRunning()) {
            // Get the buffer output
            $buffer = $process->getIncrementalErrorOutput();

            // split out each line
            $lines = preg_split('/\r?\n/', trim($buffer));
            foreach ($lines as $line) {
                if (preg_match('/speed=\s*([0-9\.]+x)/', $line, $matches)) {
                    $speed = (float) $matches[1];
                    Log::channel('ffmpeg')->info("Speed for [{$title}]: {$speed}x");
                    if ($speed < $lowSpeedThreshold && $speed > 0.0) {
                        $lowSpeedCount++;
                        Log::channel('ffmpeg')->warning("Low speed count for [{$title}]: {$lowSpeedCount}");
                        if ($lowSpeedCount >= 3) {
                            throw new SourceSpeedBelowThreshold("Low speed threshold reached for {$title}. Speed: {$speed}");
                        }
                    }
                } elseif (!empty(trim($line))) {
                    // It's not a speed update, log as original error/info based on context
                    // For now, continue logging as error if it's not an empty line from stderr
                    Log::channel('ffmpeg')->error($line);
                }
            }
            usleep(100000); // Sleep for 100ms to reduce CPU usage
        }

        if (!$process->isSuccessful()) {
            throw new Exception("FFmpeg process failed for [{$title}]: " . $process->getErrorOutput());
        }
        Log::channel('ffmpeg')->info("Streaming {$type} {$title} with command: {$cmd}");

        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     *
     * @param string $streamUrl
     * @param string|null $userAgent
     * @param string $title
     * 
     * @throws Exception If the pre-check fails
     */
    private function runPreCheck($streamUrl, $userAgent, $title)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -select_streams v:0 -user_agent " . escapeshellarg($userAgent) . " " . escapeshellarg($streamUrl);
        Log::channel('ffmpeg')->info("[PRE-CHECK] Executing ffprobe command for [{$title}]: {$cmd}");

        $process = SymfonyProcess::fromShellCommandline($cmd);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new SourceNotResponding("ffprobe failed for {$title}: " . $process->getErrorOutput());
        }

        Log::channel('ffmpeg')->info("[PRE-CHECK] ffprobe successful for [{$title}].");
    }
}
