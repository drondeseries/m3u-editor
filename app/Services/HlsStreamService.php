<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use App\Models\ChannelStreamSource;
use App\Jobs\MonitorActiveStreamJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections, performs pre-checks using ffprobe, and monitors for slow speed.
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
        // Get stream settings, including the ffprobe timeout
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5; // Default to 5 if not set

        // Get stream settings, including the ffprobe timeout
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5; // Default to 5 if not set

        // Check if the requested model is already running
        if ($this->isRunning($type, $model->id)) {
            $activeSourceId = Redis::get("hls:active_source:{$type}:{$model->id}");
            $activeStreamSource = $activeSourceId ? ChannelStreamSource::find($activeSourceId) : null;
            $streamTitleToLog = $activeStreamSource ? $activeStreamSource->provider_name : $title;

            Log::channel('ffmpeg')->debug("HLS Stream: Found existing running stream for $type ID {$model->id} (Source: {$streamTitleToLog}) - reusing for original request {$model->id} ({$title}).");
            // Potentially refresh MonitorActiveStreamJob if needed, or assume it's running
            if ($activeStreamSource) {
                 MonitorActiveStreamJob::dispatch($model->id, $activeStreamSource->id, null);
            }
            return $model; // Return the original model as it's already streaming
        }

        // Fetch enabled stream sources, ordered by priority
        $streamSources = $model->streamSources()->where('is_enabled', true)->orderBy('priority')->get();

        if ($streamSources->isEmpty()) {
            Log::channel('ffmpeg')->error("No enabled stream sources found for {$type} {$title} (ID: {$model->id}).");
            return null;
        }

        // Record timestamp in Redis for the original model (never expires until we prune)
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set for the original model
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        // Loop over the stream sources and grab the first one that works.
        foreach ($streamSources as $streamSource) {
            $currentStreamTitle = $streamSource->provider_name ?? "Source ID {$streamSource->id}";
            $playlist = $model->playlist; // Assuming the primary model's playlist applies

            // Make sure we have a valid source (using streamSource ID and its playlist ID)
            // Note: A stream source doesn't have a direct playlist_id, it belongs to a channel which has a playlist.
            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $streamSource->id . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                Log::channel('ffmpeg')->debug("Skipping stream source {$currentStreamTitle} (ID: {$streamSource->id}) for {$type} {$title} as it was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                continue;
            }

            // Keep track of the active streams for this playlist using optimistic locking pattern
            $activeStreams = $this->incrementActiveStreams($playlist->id);

            // Then check if we're over limit
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping source {$currentStreamTitle} for {$type} {$title}.");
                continue;
            }

            $userAgent = $playlist->user_agent ?? null; // User agent from the channel's playlist
            $customHeaders = $streamSource->custom_headers ?? null;

            try {
                $this->runPreCheck($type, $model->id, $streamSource->stream_url, $userAgent, $currentStreamTitle, $ffprobeTimeout, $customHeaders);

                $this->startStreamWithSpeedCheck(
                    type: $type,
                    model: $model, // Pass the original model
                    streamUrl: $streamSource->stream_url,
                    title: $title, // Original title for logging consistency related to the channel/episode
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                    customHeaders: $customHeaders // Pass custom headers
                );

                // Stream started successfully with chosenStreamSource = $streamSource
                Redis::set("hls:active_source:{$type}:{$model->id}", $streamSource->id);
                MonitorActiveStreamJob::dispatch($model->id, $streamSource->id, null);

                // Update stream source status
                $streamSource->update(['status' => 'active', 'consecutive_failures' => 0, 'last_checked_at' => now()]);

                Log::channel('ffmpeg')->debug("Successfully started HLS stream for {$type} {$title} (ID: {$model->id}) using source {$currentStreamTitle} (ID: {$streamSource->id}) on playlist {$playlist->id}.");
                return $model; // Return the original model as the stream is for it

            } catch (SourceNotResponding $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for {$type} {$title} with source {$currentStreamTitle} (ID: {$streamSource->id}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $streamSource->increment('consecutive_failures');
                $streamSource->update(['status' => 'problematic', 'last_failed_at' => now(), 'last_checked_at' => now()]);
                continue;
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Error streaming {$type} {$title} with source {$currentStreamTitle} (ID: {$streamSource->id}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $streamSource->increment('consecutive_failures');
                $streamSource->update(['status' => 'down', 'last_failed_at' => now(), 'last_checked_at' => now()]);
                continue;
            }
        }

        Log::channel('ffmpeg')->error("No available (HLS) stream sources for {$type} {$title} (Original Model ID: {$model->id}) after trying all sources.");
        return null;
    }


    /**
     * Switch the active stream source for a channel or episode.
     *
     * @param string $type 'channel' or 'episode'
     * @param Channel|Episode $model The channel or episode model.
     * @param ChannelStreamSource $newSource The new stream source to switch to.
     * @param int|null $failedStreamSourceId The ID of the stream source that failed (optional).
     * @return bool True if switch was successful, false otherwise.
     */
    public function switchStreamSource(string $type, Channel|Episode $model, ChannelStreamSource $newSource, ?int $failedStreamSourceId): bool
    {
        $title = strip_tags($type === 'channel' ? ($model->title_custom ?? $model->title) : $model->title);
        Log::channel('ffmpeg')->info("Attempting to switch stream source for {$type} {$title} (ID: {$model->id}) to new source ID: {$newSource->id} (URL: {$newSource->stream_url}). Failed source ID: {$failedStreamSourceId}.");

        if ($failedStreamSourceId) {
            $failedSource = ChannelStreamSource::find($failedStreamSourceId);
            if ($failedSource) {
                $failedSource->update(['status' => 'down', 'last_failed_at' => now()]);
                Log::channel('ffmpeg')->info("Marked failed stream source ID {$failedStreamSourceId} as 'down'.");
            }
        }

        // Stop the current stream if it's running
        if ($this->isRunning($type, $model->id)) {
            Log::channel('ffmpeg')->info("Stopping existing stream for {$type} {$title} (ID: {$model->id}) before switching.");
            $this->stopStream($type, $model->id); // This also clears hls:active_source from Redis
        } else {
            Log::channel('ffmpeg')->info("No existing stream found running for {$type} {$title} (ID: {$model->id}). Proceeding to start new source.");
        }

        $playlist = $model->playlist; // Assuming channel/episode always has a playlist relationship
        $userAgent = $playlist->user_agent ?? null;
        $customHeaders = $newSource->custom_headers ?? null;
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        try {
            // Pre-check the new source
            $this->runPreCheck($type, $model->id, $newSource->stream_url, $userAgent, $newSource->provider_name ?? "Source ID {$newSource->id}", $ffprobeTimeout, $customHeaders);

            // Start stream with the new source
            // Note: startStreamWithSpeedCheck needs playlistId which is $playlist->id
            $this->startStreamWithSpeedCheck(
                type: $type,
                model: $model,
                streamUrl: $newSource->stream_url,
                title: $title,
                playlistId: $playlist->id,
                userAgent: $userAgent,
                customHeaders: $customHeaders
            );

            // Update database and Redis for the new source
            $newSource->update(['status' => 'active', 'consecutive_failures' => 0, 'last_checked_at' => now()]);
            Redis::set("hls:active_source:{$type}:{$model->id}", $newSource->id);
            Cache::put("hls:stream_mapping:{$type}:{$model->id}", $model->id, now()->addHours(24)); // What is $model->id mapping to $model->id? This might need to be $newSource->id

            MonitorActiveStreamJob::dispatch($model->id, $newSource->id, null);

            Log::channel('ffmpeg')->info("Successfully switched stream for {$type} {$title} (ID: {$model->id}) to new source ID: {$newSource->id}.");
            return true;

        } catch (SourceNotResponding $e) {
            Log::channel('ffmpeg')->critical("Failed to start new stream source ID {$newSource->id} for {$type} {$title} (ID: {$model->id}) during switch: PreCheck failed - {$e->getMessage()}");
            $newSource->increment('consecutive_failures');
            $newSource->update(['status' => 'problematic', 'last_failed_at' => now(), 'last_checked_at' => now()]);
            // Optionally, try to find another source or revert, for now, just log and return false
            // HandleStreamFailoverJob::dispatch($model->id, $newSource->id)->delay(now()->addSeconds(5)); // Avoid rapid loops
            return false;
        } catch (Exception $e) {
            Log::channel('ffmpeg')->critical("Failed to start new stream source ID {$newSource->id} for {$type} {$title} (ID: {$model->id}) during switch: {$e->getMessage()}");
            $newSource->increment('consecutive_failures');
            $newSource->update(['status' => 'down', 'last_failed_at' => now(), 'last_checked_at' => now()]);
            // HandleStreamFailoverJob::dispatch($model->id, $newSource->id)->delay(now()->addSeconds(5));
            return false;
        }
    }

    /**
     * Start a stream and monitor for slow speed.
     *
     * @param string $type
     * @param Channel|Episode $model
     * @param string $streamUrl
     * @param string $title
     * @param int $playlistId
     * @param string|null $userAgent
     * @param array|null $customHeaders
     * 
     * @return int The FFmpeg process ID
     * @throws Exception If the stream fails or speed drops below the threshold
     */
    private function startStreamWithSpeedCheck(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        string|null $userAgent,
        ?array $customHeaders = null
    ): int {
        // Setup the stream
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl, $customHeaders);

        // Use proc_open approach similar to startStream
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        if ($type === 'episode') {
            $workingDir = Storage::disk('app')->path("hls/e/{$model->id}");
        } else {
            $workingDir = Storage::disk('app')->path("hls/{$model->id}");
        }
        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (!is_resource($process)) {
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        // Immediately close stdin/stdout
        fclose($pipes[0]);
        fclose($pipes[1]);

        // Make stderr non-blocking
        stream_set_blocking($pipes[2], false);

        // Spawn a little "reader" that pulls from stderr and logs
        $logger = Log::channel('ffmpeg');
        $stderr = $pipes[2];

        // Get the PID and cache it
        $cacheKey = "hls:pid:{$type}:{$model->id}";

        // Register shutdown function to ensure the pipe is drained
        register_shutdown_function(function () use (
            $stderr,
            $process,
            $logger
        ) {
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
        // $cacheKey is "hls:pid:{$type}:{$model->id}" which is correct for the PID
        Cache::forever($cacheKey, $pid);

        // Store the process start time
        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$model->id} at {$currentTime}");

        // Record timestamp in Redis (never expires until we prune)
        // This key represents when the startStream method was last invoked for this model,
        // which is different from the ffmpeg process actual start time. Keep for now.
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");
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
     * @param array|null $customHeaders Custom HTTP headers for ffprobe
     * 
     * @throws Exception If the pre-check fails
     */
    private function runPreCheck(string $modelType, $modelId, string $streamUrl, ?string $userAgent, string $title, int $ffprobeTimeout, ?array $customHeaders = null)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        $cmdParts = [$ffprobePath, '-v quiet', '-print_format json', '-show_streams', '-show_format'];

        if ($userAgent) {
            $cmdParts[] = "-user_agent " . escapeshellarg($userAgent);
        }

        if (!empty($customHeaders)) {
            $headerString = '';
            foreach ($customHeaders as $key => $value) {
                $headerString .= escapeshellarg("{$key}: {$value}") . "\r\n";
            }
            $cmdParts[] = '-headers ' . escapeshellarg(trim($headerString));
        }
        
        $cmdParts[] = escapeshellarg($streamUrl);
        $cmd = implode(' ', $cmdParts);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] (Model ID: {$modelId}) with timeout {$ffprobeTimeout}s: {$cmd}");
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
     * @return bool
     */
    public function stopStream($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
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
            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000); // 100ms
                $attempts++;
            }

            // Force kill if still running
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            Cache::forget($cacheKey);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        // Remove from active IDs set
        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");
        Redis::del("hls:active_source:{$type}:{$id}"); // Remove active source tracking

        // Cleanup on-disk HLS files
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::deleteDirectory($storageDir);

        // Decrement active streams count if we have the model and playlist
        if ($model && $model->playlist) {
            $this->decrementActiveStreams($model->playlist->id);
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
     * @param array|null $customHeaders Custom HTTP headers for ffmpeg input
     * 
     * @return string The complete FFmpeg command
     */
    private function buildCmd(
        string $type,
        string $id,
        ?string $userAgent,
        string $streamUrl,
        ?array $customHeaders = null
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
                    $codecSpecificArgs = "-global_quality 23 "; // Ensure trailing space
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
            $outputFormat = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");

            // Conditionally add audio codec
            if (!empty($audioCodec)) {
                $outputFormat .= "-c:a {$audioCodec} ";
            }

            // Conditionally add subtitle codec
            if (!empty($subtitleCodec)) {
                $outputFormat .= "-c:s {$subtitleCodec} ";
            }
            $outputFormat = trim($outputFormat); // Trim trailing space

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            // Low-latency flags for better HLS performance
            $cmd .= '-fflags nobuffer+igndts+genpts -flags low_delay -avoid_negative_ts disabled ';

            // Input analysis optimization for faster stream start
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';
            
            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown ';

            // Use the user agent from settings, escape it.
            $effectiveUserAgent = $userAgent ?: ($settings['ffmpeg_user_agent'] ?? 'Mozilla/5.0');
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " ";

            // Add custom headers if provided
            if (!empty($customHeaders)) {
                $headerString = '';
                foreach ($customHeaders as $key => $value) {
                    // FFmpeg expects headers to be separated by CRLF, ensure proper escaping
                    $headerString .= "{$key}: {$value}\r\n";
                }
                // The entire header string needs to be quoted if it contains special characters for the shell
                // However, escapeshellarg might over-escape. For ffmpeg's -headers option,
                // it's often better to pass it as a single argument that ffmpeg itself parses.
                // Let's ensure the value is properly formatted for ffmpeg.
                $cmd .= '-headers ' . escapeshellarg(trim($headerString)) . ' ';
            }

            $cmd .= "-referer \"MyComputer\" " . // Referer might be better as part of custom_headers if needed
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args
            $cmd .= '-reconnect_at_eof 1 ';
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' '; // Input URL
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'

            $cmd .= trim($outputFormat) . ' ';
            $cmd .= '-fps_mode cfr ';
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

            // User agent for template
            $effectiveUserAgentForTemplate = $userAgent ?: ($settings['ffmpeg_user_agent'] ?? 'Mozilla/5.0');
            $cmd = str_replace('{USER_AGENT}', escapeshellarg($effectiveUserAgentForTemplate), $cmd);

            // Custom headers for template
            $headersForTemplate = '';
            if (!empty($customHeaders)) {
                $headerString = '';
                foreach ($customHeaders as $key => $value) {
                    $headerString .= "{$key}: {$value}\r\n";
                }
                $headersForTemplate = '-headers ' . escapeshellarg(trim($headerString));
            }
            $cmd = str_replace('{CUSTOM_HEADERS}', $headersForTemplate, $cmd); // Add new placeholder for headers

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

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }

    /**
     * Perform a health check on a given stream source.
     * (Conceptual for now, will be implemented by the job that uses it)
     *
     * @param ChannelStreamSource $streamSource
     * @return array
     */
use Illuminate\Support\Facades\Http; // Added for HTTP client

// ... (other use statements)

class HlsStreamService
{
    use TracksActiveStreams;

    // ... (existing methods) ...

    /**
     * Perform a health check on a given stream source.
     *
     * @param ChannelStreamSource $streamSource
     * @return array
     */
    public function performHealthCheck(ChannelStreamSource $streamSource): array
    {
        Log::channel('health_check')->info("Performing health check for stream source ID: {$streamSource->id} (URL: {$streamSource->stream_url})");

        $timeoutSeconds = config('failover.health_check_timeout', 10);
        $maxRetries = config('failover.health_check_retries', 1); // How many times to retry the HTTP request

        $requestOptions = ['timeout' => $timeoutSeconds];
        if (!empty($streamSource->custom_headers)) {
            $requestOptions['headers'] = $streamSource->custom_headers;
        }

        try {
            $response = Http::retry($maxRetries, 100) // Retry up to $maxRetries times, 100ms delay between retries
                            ->withOptions($requestOptions)
                            ->get($streamSource->stream_url);

            if (!$response->successful()) {
                Log::channel('health_check')->warning("Health check HTTP error for source ID {$streamSource->id}: Status {$response->status()}");
                return [
                    'status' => 'http_error',
                    'http_status' => $response->status(),
                    'message' => 'Failed to fetch manifest, HTTP status: ' . $response->status()
                ];
            }

            $manifestContent = $response->body();
            if (empty($manifestContent)) {
                Log::channel('health_check')->warning("Health check manifest empty for source ID {$streamSource->id}.");
                return ['status' => 'http_error', 'http_status' => $response->status(), 'message' => 'Manifest content is empty.'];
            }

            // Basic Manifest Parsing
            $mediaSequence = null;
            $segmentCount = 0;

            if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $manifestContent, $matches)) {
                $mediaSequence = (int)$matches[1];
            }

            $segmentCount = preg_match_all('/\.ts(\?|$)/m', $manifestContent); // Count lines ending with .ts or .ts?query_params

            if ($mediaSequence === null && $segmentCount === 0 && !str_contains(strtolower($manifestContent), '#extm3u')) {
                 Log::channel('health_check')->warning("Health check failed for source ID {$streamSource->id}: Manifest content doesn't look like HLS.");
                 return ['status' => 'manifest_error', 'http_status' => $response->status(), 'message' => 'Manifest content does not appear to be a valid HLS playlist.'];
            }


            Log::channel('health_check')->info("Health check successful for source ID {$streamSource->id}. Media Sequence: {$mediaSequence}, Segments: {$segmentCount}");
            return [
                'status' => 'ok',
                'http_status' => $response->status(),
                'manifest_content' => $manifestContent, // Be cautious logging/returning full manifest if large
                'media_sequence' => $mediaSequence,
                'segment_count' => $segmentCount,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::channel('health_check')->error("Health check connection error for source ID {$streamSource->id}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'ConnectionException: ' . $e->getMessage()];
        } catch (Exception $e) {
            Log::channel('health_check')->error("Health check general error for source ID {$streamSource->id}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'General Exception: ' . $e->getMessage()];
        }
    }
}
