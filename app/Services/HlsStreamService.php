<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;
use App\Jobs\MonitorFfmpegStreamJob;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections and performs pre-checks using ffprobe.
     *
     * @param string $type
     * @param Channel|Episode $model The Channel model instance
     * @param string $title The title of the channel
     */
    public function startStream(
        string $type,
        Channel|Episode $model, // This $model is the *original* requested channel/episode
        string $title           // This $title is the title of the *original* model
    ): ?object {
        $lockKey = "lock:hls_startup:{$type}:{$model->id}";
        $lock = Cache::lock($lockKey, 60); // Create a lock instance with 60s TTL
        $lockAcquired = false; // Flag to track if lock was acquired

        try {
            if ($lock->get()) { // Attempt to acquire the lock
                $lockAcquired = true;
                // Lock acquired, proceed with stream starting logic
            } else {
                // Failed to acquire lock, another process is likely starting this stream.
                Log::channel('ffmpeg')->warning("HLS Stream: Could not acquire startup lock for $type ID {$model->id} ({$title}). Another request may be processing it.");
                // Consider throwing a custom exception here if the controller needs to handle it specifically.
                // For now, returning null will lead to a 503 if no stream is found by the controller.
                return null;
            }

            // Get stream settings, including the ffprobe timeout
            $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5; // Default to 5 if not set

        // Get the failover channels (if any)
        $streams = collect([$model]);

        // If type is channel, include failover channels
        if ($model instanceof Channel) {
            if ($model->is_custom && !($model->url_custom || $model->url)) {
                // If the custom channel has no URL set, log it and set $streams to the channel failovers only
                Log::channel('ffmpeg')->debug("HLS Stream: Custom channel {$model->id} ({$title}) has no URL set. Using failover channels only.");

                // Set $streams to only the failover channels if no URL is set
                $streams = $model->failoverChannels;
            } else {
                $streams = $streams->concat($model->failoverChannels);
            }
        }

        // First check if any of the streams (including failovers) are already running
        foreach ($streams as $stream) {
            if ($this->isRunning($type, $stream->id)) {
                $existingStreamTitle = $type === 'channel'
                    ? ($stream->title_custom ?? $stream->title)
                    : $stream->title;
                $existingStreamTitle = strip_tags($existingStreamTitle);

                Log::channel('ffmpeg')->debug("HLS Stream: Found existing running stream for $type ID {$stream->id} ({$existingStreamTitle}) - reusing for original request {$model->id} ({$title}).");
                return $stream; // Return the already running stream
            }
        }

        // Record timestamp in Redis for the original model (never expires until we prune)
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set for the original model
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        // Loop over the failover channels and grab the first one that works.
        foreach ($streams as $stream) { // $stream is the current primary or failover channel being attempted
            // URL for the current stream being attempted
            $currentAttemptStreamUrl = $type === 'channel'
                ? ($stream->url_custom ?? $stream->url) // Use current $stream's URL
                : $stream->url;

            // Get the title for the current stream in the loop
            $currentStreamTitle = $type === 'channel'
                ? ($stream->title_custom ?? $stream->title)
                : $stream->title;
            $currentStreamTitle = strip_tags($currentStreamTitle);

            // Check if playlist is specified for the current stream
            $playlist = $stream->getEffectivePlaylist();

            // Make sure we have a valid source channel (using current stream's ID and its playlist ID)
            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $stream->id . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                if ($model->id === $stream->id) {
                    Log::channel('ffmpeg')->debug("Skipping source ID {$currentStreamTitle} ({$stream->id}) for as it was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                } else {
                    Log::channel('ffmpeg')->debug("Skipping Failover {$type} {$stream->name} for source {$model->title} ({$model->id}) as it (stream ID {$stream->id}) was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                }
                continue;
            }

            // Keep track of the active streams for this playlist using optimistic locking pattern
            $activeStreams = $this->incrementActiveStreams($playlist->id);

            // Then check if we're over limit
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                // We're over limit, so decrement and skip
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping channel {$currentStreamTitle}.");
                continue;
            }

            $userAgent = $playlist->user_agent ?? null;
            try {
                // Pass the ffprobe timeout to runPreCheck
                // Pass $type and $stream->id for caching stream info
                $this->runPreCheck($type, $stream->id, $currentAttemptStreamUrl, $userAgent, $currentStreamTitle, $ffprobeTimeout);

                $this->startStreamingProcess(
                    type: $type,
                    model: $stream, // Pass the current $stream object
                    streamUrl: $currentAttemptStreamUrl, // Pass the URL of the current $stream
                    title: $currentStreamTitle, // Pass the title of the current $stream
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                );
                Log::channel('ffmpeg')->debug("Successfully started HLS stream for {$type} {$currentStreamTitle} (ID: {$stream->id}) on playlist {$playlist->id}.");
                return $stream; // Return the successful stream object

            } catch (SourceNotResponding $e) {
                // Log the error and cache the bad source
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());

                // Try the next failover channel
                continue;
            } catch (Exception $e) {
                // Log the error and abort
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Error streaming channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());

                // Try the next failover channel
                continue;
            }
        }
        // If loop finishes, no stream was successfully started
        Log::channel('ffmpeg')->error("No available (HLS) streams for {$type} {$title} (Original Model ID: {$model->id}) after trying all sources.");
        return null; // Return null if no stream started
        } catch (LockTimeoutException $e) {
            // This specific exception for lock timeout might not be strictly necessary
            // if $lock->get() is the primary way we check, but good for robustness.
            Log::channel('ffmpeg')->error("HLS Stream: Lock timeout while trying to acquire startup lock for $type ID {$model->id} ({$title}).");
            return null;
        } catch (Exception $e) {
            // Catch any other general exceptions that might occur within the lock acquisition block
            // This is a safety net, specific errors should be handled within the main loop if possible.
            Log::channel('ffmpeg')->error("HLS Stream: Unexpected exception during stream startup for $type ID {$model->id} ({$title}): " . $e->getMessage());
            // Lock release is handled in the finally block.
            throw $e; // Re-throw the exception
        } finally {
            // Always ensure the lock is released if it was acquired by this instance.
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    /**
     * Start a stream process.
     *
     * @param string $type
     * @param Channel|Episode $model
     * @param string $streamUrl
     * @param string $title
     * @param int $playlistId
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     * @throws Exception If the stream fails
     */
    private function startStreamingProcess(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        string|null $userAgent,
    ): int {
        // Setup the stream
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl);

        // Define stderr log path
        $stderrLogDir = storage_path(config('proxy.ffmpeg_stderr_log_directory', 'logs/ffmpeg_stderr'));
        File::ensureDirectoryExists($stderrLogDir);
        $stderrLogFilename = "ffmpeg_stderr_{$type}_{$model->id}_" . time() . ".log";
        $stderrLogPath = $stderrLogDir . '/' . $stderrLogFilename;

        // Store the stderr log path for potential cleanup by stopStream
        $stderrLogCacheKey = "hls:stderr_log_path:{$type}:{$model->id}";
        Cache::put($stderrLogCacheKey, $stderrLogPath, now()->addDays(config('proxy.ffmpeg_stderr_log_retention_days', 2)));


        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['file', $stderrLogPath, 'a'] // stderr (append to log file)
        ];
        $pipes = [];

        if ($type === 'episode') {
            $workingDir = Storage::disk('app')->path("hls/e/{$model->id}");
        } else {
            $workingDir = Storage::disk('app')->path("hls/{$model->id}");
        }
        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (!is_resource($process)) {
            // Clean up the empty log file if proc_open fails
            if (File::exists($stderrLogPath)) {
                File::delete($stderrLogPath);
            }
            Cache::forget($stderrLogCacheKey);
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        // Immediately close stdin and stdout as they are not directly used by this PHP process
        fclose($pipes[0]);
        fclose($pipes[1]);
        // stderr is a file, no need to fclose here, FFmpeg process will handle it.

        // Cache the actual FFmpeg PID
        $status = proc_get_status($process);
        $pid = $status['pid'];
        $cacheKey = "hls:pid:{$type}:{$model->id}";
        Cache::forever($cacheKey, $pid);

        // The old register_shutdown_function is removed as stderr is now logged to a file
        // and monitored by MonitorFfmpegStreamJob.
        // We still need to ensure proc_close is called eventually,
        // but it's generally handled when the process object goes out of scope or explicitly.
        // For detached processes, this is less of an immediate concern for this script.
        // proc_close($process); // This would block if called here before process ends.

        // Store the process start time
        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$model->id} at {$currentTime}");

        // Record timestamp in Redis
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} (PID: {$pid}) with command: {$cmd}. Stderr logged to: {$stderrLogPath}");

        // Dispatch the monitoring job if live failover is enabled
        if (config('proxy.ffmpeg_live_failover_enabled', false)) {
            MonitorFfmpegStreamJob::dispatch(
                $type,
                $model->id,
                $pid,
                $streamUrl,
                $stderrLogPath
            )->onQueue(config('proxy.ffmpeg_monitor_job_queue', 'default'));
            Log::channel('ffmpeg')->info("Dispatched MonitorFfmpegStreamJob for {$type} ID {$model->id}, PID {$pid}.");
        } else {
            Log::channel('ffmpeg')->info("Live failover monitoring is disabled. MonitorFfmpegStreamJob not dispatched for {$type} ID {$model->id}, PID {$pid}.");
        }

        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     *
     * @param string $modelType // 'channel' or 'episode'
     * @param int|string $modelId    // ID of the channel or episode
     * @param string $streamUrl
     * @param string|null $userAgent
     * @param string $title
     * @param int $ffprobeTimeout The timeout for the ffprobe process in seconds
     * 
     * @throws Exception If the pre-check fails
     */
    private function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');

        // Updated command to include -show_format and remove -select_streams to get all streams for detailed info
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent " . escapeshellarg($userAgent) . " " . escapeshellarg($streamUrl);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$cmd}");
        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);
        try {
            $precheckProcess->run();
            if (!$precheckProcess->isSuccessful()) {
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
            }
            Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

            // Check channel health
            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            $extractedDetails = [];

            if (json_last_error() === JSON_ERROR_NONE && !empty($streamInfo)) {
                // Format Section
                if (isset($streamInfo['format'])) {
                    $format = $streamInfo['format'];
                    $extractedDetails['format'] = [
                        'duration' => $format['duration'] ?? null,
                        'size' => $format['size'] ?? null,
                        'bit_rate' => $format['bit_rate'] ?? null,
                        'nb_streams' => $format['nb_streams'] ?? null,
                        'tags' => $format['tags'] ?? [],
                    ];
                }

                $videoStreamFound = false;
                $audioStreamFound = false;

                if (isset($streamInfo['streams']) && is_array($streamInfo['streams'])) {
                    foreach ($streamInfo['streams'] as $stream) {
                        if (!$videoStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                            $extractedDetails['video'] = [
                                'codec_long_name' => $stream['codec_long_name'] ?? null,
                                'width' => $stream['width'] ?? null,
                                'height' => $stream['height'] ?? null,
                                'color_range' => $stream['color_range'] ?? null,
                                'color_space' => $stream['color_space'] ?? null,
                                'color_transfer' => $stream['color_transfer'] ?? null,
                                'color_primaries' => $stream['color_primaries'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $logResolution = ($stream['width'] ?? 'N/A') . 'x' . ($stream['height'] ?? 'N/A');
                            Log::channel('ffmpeg')->debug(
                                "[PRE-CHECK] Source [{$title}] video stream: " .
                                    "Codec: " . ($stream['codec_name'] ?? 'N/A') . ", " .
                                    "Format: " . ($stream['pix_fmt'] ?? 'N/A') . ", " .
                                    "Resolution: " . $logResolution . ", " .
                                    "Profile: " . ($stream['profile'] ?? 'N/A') . ", " .
                                    "Level: " . ($stream['level'] ?? 'N/A')
                            );
                            $videoStreamFound = true;
                        } elseif (!$audioStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                            $extractedDetails['audio'] = [
                                'codec_name' => $stream['codec_name'] ?? null,
                                'profile' => $stream['profile'] ?? null,
                                'channels' => $stream['channels'] ?? null,
                                'channel_layout' => $stream['channel_layout'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $audioStreamFound = true;
                        }
                        if ($videoStreamFound && $audioStreamFound) {
                            break;
                        }
                    }
                }
                if (!empty($extractedDetails)) {
                    $detailsCacheKey = "hls:streaminfo:details:{$modelType}:{$modelId}";
                    Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails)); // Cache for 24 hours
                    Log::channel('ffmpeg')->debug("[PRE-CHECK] Cached detailed streaminfo for {$modelType} ID {$modelId}.");
                }
            } else {
                Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output for [{$title}]. Output: " . $ffprobeJsonOutput);
            }
        } catch (Exception $e) {
            throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
        }
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $type
     * @param string $id
     * @param int|null $pidToKill Specific PID to target, used by failover.
     * @return bool
     */
    public function stopStream($type, $id, ?int $pidToKill = null): bool
    {
        $pidCacheKey = "hls:pid:{$type}:{$id}";
        $cachedPid = Cache::get($pidCacheKey);
        $pid = $pidToKill ?: $cachedPid; // Use specific PID if provided, else fallback to cached PID

        $wasRunning = false;

        // Get the model to access playlist for stream count decrementing
        $model = null;
        if ($type === 'channel') {
            $model = Channel::find($id);
        } elseif ($type === 'episode') {
            $model = Episode::find($id);
        }

        if ($this->isRunning($type, $id)) {
            $wasRunning = true;

            // Give process time to cleanup gracefully
            posix_kill($pid, SIGTERM); // Send TERM signal
            $attempts = 0;
            // Check if process is still alive. posix_kill($pid, 0) returns true if process exists.
            while ($attempts < 30 && $pid && posix_kill($pid, 0)) {
                usleep(100000); // 100ms
                $attempts++;
            }

            // Force kill if still running
            if ($pid && posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL); // Send KILL signal
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            // Only forget the main PID cache if the PID we just killed matches the cached one,
            // or if no specific PID was given (meaning we intended to stop the canonical stream for this ID).
            if ($pid === $cachedPid || $pidToKill === null) {
                Cache::forget($pidCacheKey);
            }
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process {$pid} for {$type} {$id} to stop (or PID was not found).");
        }

        // If we are stopping the canonical stream for this ID (not just a rogue PID)
        if ($pidToKill === null || $pidToKill === $cachedPid) {
            // Remove from active IDs set
            Redis::srem("hls:active_{$type}_ids", $id);
            Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
            Redis::del("hls:streaminfo:details:{$type}:{$id}");

            // Clean up the stderr log file associated with this stream
            $stderrLogCacheKey = "hls:stderr_log_path:{$type}:{$id}";
            $stderrLogPath = Cache::get($stderrLogCacheKey);
            if ($stderrLogPath && File::exists($stderrLogPath)) {
                File::delete($stderrLogPath);
                Log::channel('ffmpeg')->debug("Deleted stderr log file: {$stderrLogPath}");
            }
            Cache::forget($stderrLogCacheKey);
        }


        // Cleanup on-disk HLS files (only if this is the canonical stream being stopped)
        // This check is important because a failover might stop an old PID while a new one is already using the directory.
        if ($pidToKill === null || $pidToKill === $cachedPid) {
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
            File::deleteDirectory($storageDir);
            Log::channel('ffmpeg')->debug("Deleted HLS directory: {$storageDir}");

            // Decrement active streams count if we have the model and playlist
            if ($model) {
                $playlist = $model->getEffectivePlaylist();
                if ($playlist) {
                    $this->decrementActiveStreams($playlist->id);
                }
            }

            // Clean up any stream mappings that point to this stopped stream
            $mappingPattern = "hls:stream_mapping:{$type}:*";
            $mappingKeys = Redis::keys($mappingPattern);
            foreach ($mappingKeys as $key) {
                if (Cache::get($key) == $id) {
                    Cache::forget($key);
                    Log::channel('ffmpeg')->debug("Cleaned up stream mapping: {$key} -> {$id}");
                }
            }
            Log::channel('ffmpeg')->debug("Cleaned up stream resources for {$type} {$id}");
        } else {
            Log::channel('ffmpeg')->debug("PID {$pidToKill} was specified for {$type} {$id}, HLS directory and stream counts not cleaned up by this call to preserve new stream if failover occurred.");
        }


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

        // Construct segmentBaseUrl based on proxy_url_override
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $parsedUrl = parse_url($proxyOverrideUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $base = "{$scheme}://{$host}{$port}";
            $path = $type === 'channel' ? "/api/stream/{$id}/" : "/api/stream/e/{$id}/";
            $segmentBaseUrl = $base . $path;
        } else {
            $segmentBaseUrl = $type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/';
        }

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

                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";

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
                } else {
                    // Add default QSV video filter for HLS if not set by user
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }
                if (!empty($qsvEncoderOptions)) { // $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options']
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                } else {
                    // Default QSV encoder options for HLS if not set by user
                    $codecSpecificArgs = "-preset medium -global_quality 23 "; // Ensure trailing space
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

            // Start building ffmpeg output codec formats
            $outputFormat = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");

            if ($settings['ffmpeg_output_include_aud'] ?? true) {
                $outputFormat .= '-aud:v:0 1 ';
            }

            // Conditionally add audio codec
            if (!empty($audioCodec)) {
                $outputFormat .= "-c:a {$audioCodec} ";
                if ($settings['ffmpeg_audio_disposition_default'] ?? true) {
                    $outputFormat .= '-disposition:a:0 default ';
                }
            }

            // Conditionally add subtitle codec
            if ($settings['ffmpeg_disable_subtitles'] ?? true) {
                $outputFormat .= '-sn ';
            } elseif (!empty($subtitleCodec)) {
                $outputFormat .= "-c:s {$subtitleCodec} ";
            }
            $outputFormat = trim($outputFormat); // Trim trailing space

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            // Start with -y for overwriting output files
            $cmd = escapeshellcmd($ffmpegPath) . ' -y ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            // New general input options from settings
            if ($settings['ffmpeg_input_copyts'] ?? true) {
                $cmd .= '-copyts ';
            }
            if ($settings['ffmpeg_input_stream_loop'] ?? false) {
                $cmd .= '-stream_loop -1 ';
            }
            if ($settings['ffmpeg_enable_print_graphs'] ?? false) {
                $timestamp = now()->format('Ymd-His');
                $graphFileName = "{$storageDir}/ffmpeg-graph-{$type}-{$id}-{$timestamp}.txt";
                $cmd .= '-print_graphs_file ' . escapeshellarg($graphFileName) . ' ';
            }

            // Input analysis optimization for faster stream start (using settings)
            // analysis duration, probesize, max_delay, fpsprobesize
            $cmd .= '-analyzeduration ' . escapeshellarg($settings['ffmpeg_input_analyzeduration'] ?? '3M') . ' ';
            $cmd .= '-probesize ' . escapeshellarg($settings['ffmpeg_input_probesize'] ?? '3M') . ' ';
            $cmd .= '-max_delay ' . escapeshellarg($settings['ffmpeg_input_max_delay'] ?? '5000000') . ' ';
            $cmd .= '-fpsprobesize 0 '; // Keep this existing flag

            // Use the new default 'nobuffer+igndts' for ffmpeg_input_fflags from settings
            $cmd .= '-fflags ' . escapeshellarg($settings['ffmpeg_input_fflags'] ?? 'nobuffer+igndts') . ' ';
            $cmd .= '-flags low_delay '; // Add -flags low_delay separately
            $cmd .= '-avoid_negative_ts disabled '; // Keep this flag

            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown ';

            // Use the user agent from settings, escape it. $userAgent parameter is ignored for now.
            $effectiveUserAgent = $userAgent ?: $settings['ffmpeg_user_agent'];
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " -referer \"MyComputer\" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'

            $cmd .= $outputFormat . ' ';
            $cmd .= '-fps_mode cfr '; // Replaced deprecated -vsync cfr
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

            $outputCommandSegment = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") .
                "-c:a {$audioCodecForTemplate} -c:s {$subtitleCodecForTemplate}";

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

        // Get HLS time from settings or use default
        $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
        $hlsListSize = 15; // Kept as a variable for future configurability

        // ... rest of the options and command suffix ...
        $cmd .= " -f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
            '-hls_flags delete_segments+append_list+split_by_time ' .
            '-use_wallclock_as_timestamps 1 -start_number 0 ' .
            '-hls_allow_cache 0 -hls_segment_type mpegts ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';

        // Loglevel (very last)
        if ($settings['ffmpeg_debug'] ?? false) {
            // Check if 'ffmpeg_enable_print_graphs' is also true, timing implies verbose.
            // If print_graphs is enabled, it often benefits from verbose logging.
            // The 'timing' data is useful for performance analysis.
            $cmd .= ' -loglevel verbose'; // Reverted from verbose+timing
        } else {
            $cmd .= ' -hide_banner -nostats -loglevel error';
        }

        return trim($cmd);
    }

    /**
     * Trigger a live failover for a stream that is currently experiencing issues.
     *
     * @param string $modelType 'channel' or 'episode'
     * @param int|string $modelId ID of the Channel or Episode
     * @param string $failedStreamUrl The URL that was confirmed to be failing
     * @param int $currentPidToKill The PID of the FFmpeg process for the failing stream
     * @return bool True if failover was attempted and a new stream started, false otherwise
     */
    public function triggerLiveFailover(string $modelType, $modelId, string $failedStreamUrl, int $currentPidToKill): bool
    {
        Log::channel('ffmpeg')->warning("LIVE FAILOVER: Initiating for {$modelType} ID {$modelId}, PID {$currentPidToKill}, Failed URL: {$failedStreamUrl}");

        // 1. Stop the current failing stream, targeting the specific PID
        $this->stopStream($modelType, $modelId, $currentPidToKill);
        Log::channel('ffmpeg')->info("LIVE FAILOVER: Stopped failing FFmpeg process PID {$currentPidToKill} for {$modelType} ID {$modelId}.");

        // 2. Load the primary model instance
        $originalModel = null;
        if ($modelType === 'channel') {
            $originalModel = Channel::with('failoverChannels')->find($modelId);
        } elseif ($modelType === 'episode') {
            $originalModel = Episode::find($modelId);
        }

        if (!$originalModel) {
            Log::channel('ffmpeg')->error("LIVE FAILOVER: Could not find {$modelType} with ID {$modelId}. Aborting failover.");
            return false;
        }

        $originalModelTitle = strip_tags($modelType === 'channel' ? ($originalModel->title_custom ?? $originalModel->title) : $originalModel->title);

        // 3. Mark the failed stream URL as bad for this specific model and its playlist
        // The bad source cache key is typically model_id + playlist_id.
        // We need to ensure we're using the correct playlist context for the failed URL.
        // For simplicity, we'll mark it bad based on the original model's effective playlist.
        // This assumes the failedStreamUrl was associated with this originalModel directly or as one of its failovers.
        $effectivePlaylist = $originalModel->getEffectivePlaylist();
        if ($effectivePlaylist) {
            // The bad source cache key used in startStream is `ProxyService::BAD_SOURCE_CACHE_PREFIX . $stream->id . ':' . $playlist->id;`
            // Here, $originalModel->id is the ID of the stream configuration (e.g. channel 123)
            // and $failedStreamUrl is one of its sources.
            // We mark the *combination* of this model ID and its playlist as having a bad experience with $failedStreamUrl.
            // However, the current bad source logic is tied to the source *model's* ID, not the URL itself.
            // For live failover, we need to be more granular. Let's create a specific cache key for the URL for this model.
            $badSourceUrlCacheKey = "hls:bad_live_source_url:{$modelType}:{$originalModel->id}:" . md5($failedStreamUrl);
            $badSourceCooldown = config('proxy.ffmpeg_live_failover_bad_source_cooldown_seconds', 300);
            Redis::setex($badSourceUrlCacheKey, $badSourceCooldown, "Failed during live monitoring at " . now()->toDateTimeString());
            Log::channel('ffmpeg')->info("LIVE FAILOVER: Marked URL {$failedStreamUrl} as bad for {$modelType} ID {$originalModel->id} for {$badSourceCooldown} seconds. Cache key: {$badSourceUrlCacheKey}");
        } else {
            Log::channel('ffmpeg')->warning("LIVE FAILOVER: Could not determine effective playlist for {$modelType} ID {$originalModel->id} to mark bad source URL.");
        }


        // 4. Prepare a list of potential sources to try
        $sourcesToTry = collect([]);
        if ($originalModel instanceof Channel) {
            // Add primary URL if it exists
            $primaryUrl = $originalModel->url_custom ?? $originalModel->url;
            if ($primaryUrl) {
                $sourcesToTry->push(['model' => $originalModel, 'url' => $primaryUrl, 'title' => $originalModelTitle]);
            }
            // Add failover channels
            foreach ($originalModel->failoverChannels as $failoverChannel) {
                $failoverUrl = $failoverChannel->url_custom ?? $failoverChannel->url;
                if ($failoverUrl) {
                    $failoverTitle = strip_tags($failoverChannel->title_custom ?? $failoverChannel->title);
                    $sourcesToTry->push(['model' => $failoverChannel, 'url' => $failoverUrl, 'title' => $failoverTitle]);
                }
            }
        } elseif ($originalModel instanceof Episode) {
            if ($originalModel->url) {
                 $sourcesToTry->push(['model' => $originalModel, 'url' => $originalModel->url, 'title' => $originalModelTitle]);
            }
        }

        // 5. Iterate and attempt to start a new stream
        // This loop is similar to the one in `startStream` but simplified for failover context.
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        foreach ($sourcesToTry as $sourceAttempt) {
            $currentStreamModel = $sourceAttempt['model']; // This is the Channel/Episode object providing the URL
            $currentAttemptStreamUrl = $sourceAttempt['url'];
            $currentStreamTitle = $sourceAttempt['title'];

            // Skip the URL that just failed
            if ($currentAttemptStreamUrl === $failedStreamUrl) {
                Log::channel('ffmpeg')->debug("LIVE FAILOVER: Skipping URL {$currentAttemptStreamUrl} as it's the one that just failed.");
                continue;
            }

            // Check if this URL was recently marked as bad by a live failover for the *original* model
            $badUrlCacheKey = "hls:bad_live_source_url:{$modelType}:{$originalModel->id}:" . md5($currentAttemptStreamUrl);
            if (Redis::exists($badUrlCacheKey)) {
                Log::channel('ffmpeg')->debug("LIVE FAILOVER: Skipping URL {$currentAttemptStreamUrl} for {$modelType} ID {$originalModel->id} as it was recently marked bad by live failover. Reason: " . Redis::get($badUrlCacheKey));
                continue;
            }

            // Also check the standard bad source cache (which is based on $currentStreamModel->id and its playlist)
            $playlistForCurrentAttempt = $currentStreamModel->getEffectivePlaylist();
            if (!$playlistForCurrentAttempt) {
                 Log::channel('ffmpeg')->warning("LIVE FAILOVER: Could not get effective playlist for potential source model ID {$currentStreamModel->id} ({$currentStreamTitle}). Skipping.");
                 continue;
            }
            $standardBadSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $currentStreamModel->id . ':' . $playlistForCurrentAttempt->id;
            if (Redis::exists($standardBadSourceCacheKey)) {
                Log::channel('ffmpeg')->debug("LIVE FAILOVER: Skipping URL {$currentAttemptStreamUrl} (from model ID {$currentStreamModel->id}) as it's marked in standard bad source cache for playlist {$playlistForCurrentAttempt->id}. Reason: " . Redis::get($standardBadSourceCacheKey));
                continue;
            }

            // Check stream limits for the playlist of the source being attempted
            // Note: The active streams count for the *original* model's playlist was decremented by stopStream if the PID matched.
            // If the new source is on a *different* playlist, we need to manage its count.
            // For simplicity in live failover, we are assuming the failover source uses resources associated with the *original* model's context.
            // The primary stream count management is done in startStream. Here, we are just trying to get *any* source up for the *original* model's slot.

            $userAgent = $playlistForCurrentAttempt->user_agent ?? null;

            try {
                Log::channel('ffmpeg')->info("LIVE FAILOVER: Attempting to start new stream for {$modelType} ID {$originalModel->id} using URL {$currentAttemptStreamUrl} from source model ID {$currentStreamModel->id} ('{$currentStreamTitle}').");

                // Pre-check the new source
                // We use $originalModel->id for pre-check caching because the HLS segments belong to the original model.
                $this->runPreCheck($modelType, $originalModel->id, $currentAttemptStreamUrl, $userAgent, $currentStreamTitle, $ffprobeTimeout);

                // Start the streaming process.
                // IMPORTANT: We use $originalModel here, not $currentStreamModel, because the HLS output path,
                // PID caching, and monitoring job are all tied to the ID of the stream the user *requested*,
                // which is $originalModel->id.
                // The $currentStreamModel only provides the $currentAttemptStreamUrl and $currentStreamTitle for this attempt.
                $this->startStreamingProcess(
                    type: $modelType,
                    model: $originalModel, // Use the original model for consistent HLS path and IDing
                    streamUrl: $currentAttemptStreamUrl,
                    title: $currentStreamTitle, // Can use the title of the actual source for logging clarity
                    playlistId: $playlistForCurrentAttempt->id, // Playlist ID of the source providing the URL
                    userAgent: $userAgent
                );

                Log::channel('ffmpeg')->info("LIVE FAILOVER: Successfully started new stream for {$modelType} ID {$originalModel->id} using URL {$currentAttemptStreamUrl}.");
                // Prevent this URL from being immediately retried if this new stream also fails quickly
                $this->clearRecentlyFailedMarkerForSuccessfulFailover($modelType, $originalModel->id);
                return true; // Failover successful

            } catch (SourceNotResponding $e) {
                Log::channel('ffmpeg')->error("LIVE FAILOVER: SourceNotResponding for {$modelType} ID {$originalModel->id} with URL {$currentAttemptStreamUrl}. Error: " . $e->getMessage());
                // Mark this URL as bad using only the short-term live failover cache for the original model ID.
                // Do NOT use $standardBadSourceCacheKey here to avoid influencing new client sessions.
                Redis::setex($badUrlCacheKey, $badSourceCooldown, "Failed during live failover pre-check: " . $e->getMessage());
                Log::channel('ffmpeg')->info("LIVE FAILOVER: Marked URL {$currentAttemptStreamUrl} as bad for {$modelType} ID {$originalModel->id} (short-term live cache only) due to SourceNotResponding.");
                continue; // Try next source
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("LIVE FAILOVER: Exception for {$modelType} ID {$originalModel->id} with URL {$currentAttemptStreamUrl}. Error: " . $e->getMessage());
                // Mark this URL as bad using only the short-term live failover cache for the original model ID.
                // Do NOT use $standardBadSourceCacheKey here to avoid influencing new client sessions.
                Redis::setex($badUrlCacheKey, $badSourceCooldown, "Exception during live failover attempt: " . $e->getMessage());
                Log::channel('ffmpeg')->info("LIVE FAILOVER: Marked URL {$currentAttemptStreamUrl} as bad for {$modelType} ID {$originalModel->id} (short-term live cache only) due to Exception.");
                continue; // Try next source
            }
        }

        // 6. If all sources failed
        Log::channel('ffmpeg')->error("LIVE FAILOVER: All available sources failed for {$modelType} ID {$originalModel->id}. No new stream started.");
        // Implement cycle prevention: Mark the original model as "all sources failed" for a longer period
        $allSourcesFailedCacheKey = "hls:all_sources_failed_live:{$modelType}:{$originalModel->id}";
        $allSourcesFailedCooldown = config('proxy.ffmpeg_live_failover_all_sources_failed_cooldown_seconds', 900); // e.g., 15 minutes
        Redis::setex($allSourcesFailedCacheKey, $allSourcesFailedCooldown, now()->toDateTimeString());
        Log::channel('ffmpeg')->warning("LIVE FAILOVER: {$modelType} ID {$originalModel->id} marked as all sources failed for {$allSourcesFailedCooldown} seconds.");

        return false;
    }

    /**
     * Clears the 'all sources failed' marker for a stream if a failover was ultimately successful.
     * This prevents a successful stream from being blocked by a previous total outage.
     */
    private function clearRecentlyFailedMarkerForSuccessfulFailover(string $modelType, $modelId): void
    {
        $allSourcesFailedCacheKey = "hls:all_sources_failed_live:{$modelType}:{$modelId}";
        if (Redis::exists($allSourcesFailedCacheKey)) {
            Redis::del($allSourcesFailedCacheKey);
            Log::channel('ffmpeg')->info("LIVE FAILOVER: Cleared 'all sources failed' marker for {$modelType} ID {$modelId} due to successful failover.");
        }
    }
}
