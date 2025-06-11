<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;
use App\Jobs\MonitorStreamHealthJob;
use App\Models\ChannelStreamProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Cache\LockTimeoutException;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream for the given channel by selecting an appropriate provider.
     */
    public function startStream(Channel $channel): ?Channel
    {
        $originalChannelTitle = strip_tags($channel->title_custom ?? $channel->title);
        $lockKey = "stream-start-{$channel->id}";
        $lock = Cache::lock($lockKey, 60); // Lock for 60 seconds

        Log::info("HlsStreamService: Start stream requested for channel {$channel->id} ('{$originalChannelTitle}'). Current status: {$channel->stream_status}, current provider: {$channel->current_stream_provider_id}. Attempting to acquire lock: {$lockKey}");

        try {
            if (!$lock->block(10)) { // Wait up to 10 seconds to acquire the lock
                Log::warning("HlsStreamService: Could not acquire lock '{$lockKey}' for channel {$channel->id}. Another start/switch operation may be in progress.");
                return null; // Or throw an exception
            }
            Log::debug("HlsStreamService: Lock '{$lockKey}' acquired for channel {$channel->id}.");

            // Re-fetch channel state within lock to ensure latest data
            $channel->refresh();

            if ($this->isRunning('channel', $channel->id)) {
                $currentProvider = $channel->currentStreamProvider;
                $providerInfo = $currentProvider ? "provider {$currentProvider->id} ('{$currentProvider->provider_name}', URL: {$currentProvider->stream_url})" : "an unknown provider";
                Log::info("HlsStreamService: Stream for channel {$channel->id} ('{$originalChannelTitle}') is already running with {$providerInfo}. Ensuring monitor job is active and returning channel.");
                if ($currentProvider) {
                    MonitorStreamHealthJob::dispatch($channel->id, $currentProvider->id)->onQueue('stream-monitoring');
                }
                return $channel;
            }

            Log::info("HlsStreamService: No active FFmpeg process for channel {$channel->id} ('{$originalChannelTitle}'). Proceeding to stop/cleanup and select provider.");
            $this->stopStream('channel', $channel->id, false); // Cleanup, don't decrement regular count

            // Debugging provider fetching
            Log::debug("HlsStreamService: Channel {$channel->id} ('{$originalChannelTitle}') - Checking stream providers. Relation count: " . $channel->streamProviders()->count());
            $allProviders = $channel->streamProviders()->get(); // Get all, regardless of is_active for debugging
            if ($allProviders->isEmpty()) {
                Log::debug("HlsStreamService: Channel {$channel->id} ('{$originalChannelTitle}') - No providers found by the streamProviders() relationship at all.");
            } else {
                foreach ($allProviders as $idx => $p) {
                    Log::debug("HlsStreamService: Channel {$channel->id} ('{$originalChannelTitle}') - Provider #{$idx} (All): ID={$p->id}, URL=" . ($p->stream_url ?? 'N/A') . ", Priority={$p->priority}, IsActive=" . ($p->is_active ? 'true' : 'false') . ", Status=" . ($p->status ?? 'N/A'));
                }
            }

            $streamProviders = $channel->streamProviders()->where('is_active', true)->orderBy('priority')->get();
            Log::debug("HlsStreamService: Channel {$channel->id} ('{$originalChannelTitle}') - Found " . $streamProviders->count() . " active providers after filtering by is_active=true.");


            if ($streamProviders->isEmpty()) {
                Log::error("HlsStreamService: No active stream providers available for channel {$channel->id} ('{$originalChannelTitle}'). Cannot start stream.");
                $channel->stream_status = 'failed';
                $channel->current_stream_provider_id = null;
                $channel->failed_at = now();
                $channel->save();
                return null;
            }

            $streamSettings = ProxyService::getStreamSettings();
            $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;
            $playlist = $channel->playlist;

            Redis::set("hls:channel_last_seen:{$channel->id}", now()->timestamp);
            Redis::sadd("hls:active_channel_ids", $channel->id);

            foreach ($streamProviders as $provider) {
                $providerInfo = "provider {$provider->id} ('{$provider->provider_name}', URL: {$provider->stream_url})";
                Log::info("HlsStreamService: Attempting {$providerInfo} for channel {$channel->id} ('{$originalChannelTitle}')");

                $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . 'provider:' . $provider->id;
                if (Redis::exists($badSourceCacheKey)) {
                    Log::info("HlsStreamService: Skipping {$providerInfo} for channel {$channel->id} as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                    continue;
                }

                if ($playlist) {
                    $activeStreams = $this->incrementActiveStreams($playlist->id);
                    if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                        $this->decrementActiveStreams($playlist->id);
                        Log::warning("HlsStreamService: Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping {$providerInfo} for channel {$channel->id}.");
                        continue;
                    }
                }

                try {
                    Log::debug("HlsStreamService: Validating {$providerInfo} for channel {$channel->id} - Step 1: HTTP HEAD request.");
                    $validationResponse = Http::timeout(3)->head($provider->stream_url);
                    if (!$validationResponse->successful()) {
                        Log::warning("HlsStreamService: Provider {$provider->id} (URL: {$provider->stream_url}) for channel {$channel->id} failed HEAD validation. Status: {$validationResponse->status()}");
                        throw new SourceNotResponding("HEAD request failed with status " . $validationResponse->status());
                    }
                    Log::debug("HlsStreamService: Provider {$provider->id} for channel {$channel->id} - Step 1: HTTP HEAD OK.");

                    Log::debug("HlsStreamService: Validating {$providerInfo} for channel {$channel->id} - Step 2: FFprobe pre-check.");
                    $this->runPreCheck('channel', $channel->id, $provider->stream_url, $playlist?->user_agent, $originalChannelTitle, $ffprobeTimeout);
                    Log::debug("HlsStreamService: Provider {$provider->id} for channel {$channel->id} - Step 2: FFprobe OK.");

                    Log::info("HlsStreamService: Starting FFmpeg for {$providerInfo} on channel {$channel->id} ('{$originalChannelTitle}').");
                    $this->startStreamWithSpeedCheck(
                        type: 'channel',
                        model: $channel,
                        streamUrl: $provider->stream_url,
                        title: $originalChannelTitle,
                        playlistId: $playlist?->id,
                        userAgent: $playlist?->user_agent
                    );

                    $channel->current_stream_provider_id = $provider->id;
                    $channel->stream_status = 'playing';
                    $channel->save();
                    Log::info("HlsStreamService: Channel {$channel->id} state updated: current_stream_provider_id={$provider->id}, stream_status='playing'.");


                    $provider->status = 'online';
                    $provider->last_checked_at = now();
                    $provider->save();
                    Log::info("HlsStreamService: Provider {$provider->id} status updated to 'online'.");

                    MonitorStreamHealthJob::dispatch($channel->id, $provider->id)->onQueue('stream-monitoring');
                    Log::info("HlsStreamService: Successfully started stream for channel {$channel->id} ('{$originalChannelTitle}') with {$providerInfo}.");
                    return $channel;

                } catch (SourceNotResponding $e) {
                    if ($playlist) $this->decrementActiveStreams($playlist->id);
                    Log::warning("HlsStreamService: SourceNotResponding for {$providerInfo} on channel {$channel->id}. Error: {$e->getMessage()}");
                    Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                    $provider->status = 'offline';
                    $provider->last_checked_at = now();
                    $provider->save();
                    Log::info("HlsStreamService: Marked {$providerInfo} as 'offline' for channel {$channel->id}.");
                    // Continue to next provider
                } catch (Exception $e) {
                    if ($playlist) $this->decrementActiveStreams($playlist->id);
                    Log::error("HlsStreamService: Exception while trying {$providerInfo} for channel {$channel->id}. Error: {$e->getMessage()}", [
                        'exception' => $e->getTraceAsString(), // Log full trace for better debugging
                    ]);
                    Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                    $provider->status = 'offline';
                    $provider->last_checked_at = now();
                    $provider->save();
                    Log::info("HlsStreamService: Marked {$providerInfo} as 'offline' for channel {$channel->id} due to generic exception.");
                    // Continue to next provider
                }
            }

            Log::error("HlsStreamService: All providers failed for channel {$channel->id} ('{$originalChannelTitle}'). Setting channel status to 'failed'.");
            $channel->stream_status = 'failed';
            $channel->current_stream_provider_id = null;
            $channel->failed_at = now();
            $channel->save();
            return null;

        } catch (LockTimeoutException $e) {
            Log::warning("HlsStreamService: LockTimeoutException for channel {$channel->id} in startStream. Could not acquire lock '{$lockKey}'.", ['exception' => $e->getMessage()]);
            return null; // Indicate failure to acquire lock
        } finally {
            optional($lock)->release();
            Log::debug("HlsStreamService: Lock '{$lockKey}' released for channel {$channel->id}.");
        }
    }

    public function switchStreamProvider(Channel $channel, ChannelStreamProvider $newProvider): bool
    {
        $originalChannelTitle = strip_tags($channel->title_custom ?? $channel->title);
        $currentActiveProviderId = $channel->current_stream_provider_id; // Get ID before it's potentially changed
        $newProviderInfo = "provider {$newProvider->id} ('{$newProvider->provider_name}', URL: {$newProvider->stream_url})";
        $lockKey = "stream-switch-{$channel->id}";
        $lock = Cache::lock($lockKey, 75); // Lock for 75 seconds (longer for stop + start)

        Log::info("HlsStreamService: Attempting to SWITCH channel {$channel->id} ('{$originalChannelTitle}') from provider {$currentActiveProviderId} to {$newProviderInfo}. Attempting to acquire lock: {$lockKey}");

        try {
            if (!$lock->block(15)) { // Wait up to 15 seconds
                Log::warning("HlsStreamService (switch): Could not acquire lock '{$lockKey}' for channel {$channel->id}. Another switch/start operation may be in progress.");
                return false;
            }
            Log::debug("HlsStreamService (switch): Lock '{$lockKey}' acquired for channel {$channel->id}.");

            // Re-fetch channel to ensure current_stream_provider_id is fresh before stopping
            $channel->refresh();
            $currentActiveProviderId = $channel->current_stream_provider_id; // Update after refresh
            Log::info("HlsStreamService (switch): Stopping current stream for channel {$channel->id} (was provider {$currentActiveProviderId}).");
            $this->stopStream('channel', $channel->id, true);

            $channel->stream_status = 'switching';
            $channel->current_stream_provider_id = $newProvider->id;
            $channel->save();
            Log::info("HlsStreamService (switch): Channel {$channel->id} status set to 'switching', current_stream_provider_id set to {$newProvider->id}.");

            $streamSettings = ProxyService::getStreamSettings();
            $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;
            $playlist = $channel->playlist;

            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . 'provider:' . $newProvider->id;
            if (Redis::exists($badSourceCacheKey)) {
                Log::info("HlsStreamService (switch): Skipping {$newProviderInfo} for channel {$channel->id} as it was recently marked as bad (cache hit).");
                $newProvider->status = 'offline'; // Ensure it's marked
                $newProvider->last_checked_at = now();
                $newProvider->save();
                // Revert channel status if this new provider is immediately skipped
                // $channel->stream_status = 'failed'; // Or previous status? This is complex. Let failover job handle.
                // $channel->current_stream_provider_id = $currentActiveProviderId; // Revert to old
                // $channel->save();
                return false;
            }

            try {
                Log::debug("HlsStreamService (switch): Validating new {$newProviderInfo} for channel {$channel->id} ('{$originalChannelTitle}') - Step 1: HTTP HEAD.");
                $validationResponse = Http::timeout(3)->head($newProvider->stream_url);
                if (!$validationResponse->successful()) {
                    Log::warning("HlsStreamService (switch): New {$newProviderInfo} for channel {$channel->id} ('{$originalChannelTitle}') failed HEAD validation. Status: {$validationResponse->status()}");
                    throw new SourceNotResponding("HEAD request failed with status " . $validationResponse->status());
                }
                Log::debug("HlsStreamService (switch): New {$newProviderInfo} for channel {$channel->id} ('{$originalChannelTitle}') - Step 1: HTTP HEAD OK.");

                Log::debug("HlsStreamService (switch): Validating new {$newProviderInfo} for channel {$channel->id} ('{$originalChannelTitle}') - Step 2: FFprobe pre-check.");
                $this->runPreCheck('channel', $channel->id, $newProvider->stream_url, $playlist?->user_agent, $originalChannelTitle, $ffprobeTimeout);
                Log::debug("HlsStreamService (switch): New {$newProviderInfo} for channel {$channel->id} ('{$originalChannelTitle}') - Step 2: FFprobe OK.");

                Log::info("HlsStreamService (switch): Starting FFmpeg for new {$newProviderInfo} on channel {$channel->id} ('{$originalChannelTitle}').");
                $this->startStreamWithSpeedCheck(
                    type: 'channel',
                    model: $channel,
                    streamUrl: $newProvider->stream_url,
                    title: $originalChannelTitle,
                    playlistId: $playlist?->id,
                    userAgent: $playlist?->user_agent
                );

                $channel->current_stream_provider_id = $newProvider->id;
                $channel->stream_status = 'playing';
                $channel->save();
                Log::info("HlsStreamService (switch): Channel {$channel->id} ('{$originalChannelTitle}') status updated to 'playing' with provider {$newProvider->id}.");

                $newProvider->status = 'online';
                $newProvider->last_checked_at = now();
                $newProvider->save();
                Log::info("HlsStreamService (switch): Provider {$newProvider->id} status updated to 'online'.");

                MonitorStreamHealthJob::dispatch($channel->id, $newProvider->id)->onQueue('stream-monitoring');
                Log::info("HlsStreamService: Successfully SWITCHED channel {$channel->id} ('{$originalChannelTitle}') to {$newProviderInfo}.");
                return true;

            } catch (SourceNotResponding $e) {
                Log::warning("HlsStreamService (switch): SourceNotResponding for new {$newProviderInfo} on channel {$channel->id} ('{$originalChannelTitle}'). Error: {$e->getMessage()}");
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $newProvider->status = 'offline';
                $newProvider->last_checked_at = now();
                $newProvider->save();
                Log::info("HlsStreamService (switch): Marked new {$newProviderInfo} as 'offline' for channel {$channel->id}.");
                // Channel state remains 'switching' with newProvider->id, InitiateFailoverJob will try next or mark 'failed'.
                return false;
            } catch (Exception $e) {
                Log::error("HlsStreamService (switch): Generic Exception for new {$newProviderInfo} on channel {$channel->id} ('{$originalChannelTitle}'). Error: {$e->getMessage()}", ['exception' => $e->getTraceAsString()]);
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $newProvider->status = 'offline';
                $newProvider->last_checked_at = now();
                $newProvider->save();
                Log::info("HlsStreamService (switch): Marked new {$newProviderInfo} as 'offline' for channel {$channel->id} ('{$originalChannelTitle}') due to generic exception.");
                return false;
            }
        } catch (LockTimeoutException $e) {
            Log::warning("HlsStreamService (switch): LockTimeoutException for channel {$channel->id}. Could not acquire lock '{$lockKey}'.", ['exception' => $e->getMessage()]);
            return false; // Indicate failure to acquire lock
        } finally {
            optional($lock)->release();
            Log::debug("HlsStreamService (switch): Lock '{$lockKey}' released for channel {$channel->id}.");
        }
    }


    /**
     * Start a stream and monitor for slow speed.
     */
    private function startStreamWithSpeedCheck(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        ?int $playlistId,
        string|null $userAgent
    ): int {
        $channelIdForLog = ($model instanceof Channel) ? $model->id : 'N/A (episode)';
        Log::debug("HlsStreamService: Preparing to start FFmpeg for {$type} ID {$model->id} (Channel Context ID: {$channelIdForLog}), Title: '{$title}', URL: {$streamUrl}");

        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl);
        Log::info("HlsStreamService: FFmpeg command for {$type} ID {$model->id}: " . escapeshellcmd($cmd) ); # Log the escaped command
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
            Log::critical("HlsStreamService: Failed to launch FFmpeg process for {$type} ID {$model->id} ('{$title}'). Command: " . escapeshellcmd($cmd) . " Please check FFmpeg path and permissions.");
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }
        Log::debug("HlsStreamService: FFmpeg process launched for {$type} ID {$model->id} ('{$title}').");

        fclose($pipes[0]);
        fclose($pipes[1]);

        stream_set_blocking($pipes[2], false);

        $logger = Log::channel('ffmpeg');
        $stderr = $pipes[2];

        $cacheKey = "hls:pid:{$type}:{$model->id}";

        register_shutdown_function(function () use (
            $stderr,
            $process,
            $logger,
            $type,
            $model,
            $title // For logging context
        ) {
            $errorOutput = '';
            while (!feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false) {
                    $errorOutput .= $line;
                }
            }
            if (!empty(trim($errorOutput))) {
                 $logger->error("HlsStreamService: FFmpeg stderr output for {$type} ID {$model->id} ('{$title}'): " . trim($errorOutput));
            }
            fclose($stderr);
            $status = proc_get_status($process);
            proc_close($process);
            if ($status['exitcode'] !== 0 && $status['exitcode'] !== -1 && $status['exitcode'] !== 255) { // 255 can be from SIGTERM
                 $logger->error("HlsStreamService: FFmpeg process for {$type} ID {$model->id} ('{$title}') exited unexpectedly with code {$status['exitcode']}. Stderr: " . trim($errorOutput));
            } elseif ($status['signaled'] && $status['termsig'] !== SIGTERM && $status['termsig'] !== SIGKILL) {
                 $logger->error("HlsStreamService: FFmpeg process for {$type} ID {$model->id} ('{$title}') was terminated by signal {$status['termsig']}. Stderr: " . trim($errorOutput));
            }
        });

        $status = proc_get_status($process);
        $pid = $status['pid'];
        Cache::forever($cacheKey, $pid);
        Log::info("HlsStreamService: FFmpeg process successfully started for {$type} ID {$model->id} ('{$title}') with PID {$pid}. Cached with key {$cacheKey}.");

        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime);
        Log::debug("HlsStreamService: Stored FFmpeg process start time for {$type} ID {$model->id} at {$currentTime}. Key: {$startTimeCacheKey}.");

        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     */
    private function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        $escapedUserAgent = $userAgent ? escapeshellarg($userAgent) : "''";
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent {$escapedUserAgent} " . escapeshellarg($streamUrl);

        Log::info("HlsStreamService [PRE-CHECK]: Executing ffprobe for {$modelType} ID {$modelId} ('{$title}'), URL: {$streamUrl}, Timeout: {$ffprobeTimeout}s.");
        Log::debug("HlsStreamService [PRE-CHECK]: FFprobe command for ('{$title}'): {$cmd}");

        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);

        try {
            $precheckProcess->run();
            if (!$precheckProcess->isSuccessful()) {
                $errorOutput = $precheckProcess->getErrorOutput();
                Log::warning("HlsStreamService [PRE-CHECK]: ffprobe failed for {$modelType} ID {$modelId} ('{$title}'). Exit Code: {$precheckProcess->getExitCode()}. Error: " . trim($errorOutput));
                throw new SourceNotResponding("ffprobe validation failed (Exit Code: {$precheckProcess->getExitCode()}). Output: " . trim($errorOutput));
            }
            Log::info("HlsStreamService [PRE-CHECK]: ffprobe successful for {$modelType} ID {$modelId} ('{$title}').");

            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            // ... (rest of ffprobe parsing logic remains the same)
            $extractedDetails = [];

            if (json_last_error() === JSON_ERROR_NONE && !empty($streamInfo)) {
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
                            Log::debug( // Changed from Log::channel('ffmpeg') to Log::debug for consistency
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
                    Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails));
                    Log::debug("HlsStreamService [PRE-CHECK]: Cached detailed streaminfo for {$modelType} ID {$modelId}. Key: {$detailsCacheKey}");
                }
            } else {
                Log::warning("HlsStreamService [PRE-CHECK]: Could not decode ffprobe JSON output for {$modelType} ID {$modelId} ('{$title}'). JSON Error: " . json_last_error_msg() . ". Output: " . substr($ffprobeJsonOutput, 0, 500));
            }

        } catch (Exception $e) {
            Log::error("HlsStreamService [PRE-CHECK]: Exception during ffprobe for {$modelType} ID {$modelId} ('{$title}'). Error: {$e->getMessage()}", ['exception' => $e]);
            throw new SourceNotResponding("ffprobe process exception: " . $e->getMessage());
        }
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     */
    public function stopStream(string $type, string $id, bool $decrementCount = true): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;
        $logDetails = "type: {$type}, id: {$id}, decrementCount: " . ($decrementCount ? 'yes' : 'no');
        $channelInfo = '';

        $model = null;
        if ($type === 'channel') {
            $model = Channel::find($id);
            if ($model) {
                $channelInfo = "('".strip_tags($model->title_custom ?? $model->title)."')";
                if ($model->currentStreamProvider) {
                    $logDetails .= ", previously_active_provider_id: {$model->current_stream_provider_id} ('{$model->currentStreamProvider->provider_name}')";
                }
            }
        } elseif ($type === 'episode') {
            $model = Episode::find($id);
             if ($model) $channelInfo = "('".strip_tags($model->title)."')";
        }
        Log::info("HlsStreamService: stopStream called for {$type} ID {$id} {$channelInfo}. Details: {$logDetails}.");

        if ($pid && posix_kill($pid, 0) && $this->isFfmpeg($pid)) {
            Log::info("HlsStreamService: Attempting to stop active FFmpeg process PID {$pid} for {$type} {$id} {$channelInfo}.");
            $wasRunning = true;

            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000);
                $attempts++;
            }

            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::warning("HlsStreamService: Force killed FFmpeg process PID {$pid} for {$type} {$id} {$channelInfo} as graceful stop failed.");
            } else {
                Log::info("HlsStreamService: Successfully stopped FFmpeg process PID {$pid} for {$type} {$id} {$channelInfo} gracefully.");
            }
            Cache::forget($cacheKey);
            Log::debug("HlsStreamService: Cleared PID cache key {$cacheKey} for {$type} {$id} {$channelInfo}.");
        } else {
            if ($pid) {
                 Log::warning("HlsStreamService: Process with PID {$pid} for {$type} {$id} {$channelInfo} was found in cache but not running or not an FFmpeg process. Clearing stale PID.");
                 Cache::forget($cacheKey);
            } else {
                 Log::info("HlsStreamService: No active FFmpeg PID found in cache for {$type} {$id} {$channelInfo}. No process to stop.");
            }
        }

        Log::debug("HlsStreamService: Starting cleanup of Redis keys and HLS files for {$type} {$id} {$channelInfo}.");
        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:{$type}_last_seen:{$id}");
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");

        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        if (File::exists($storageDir)) {
            File::deleteDirectory($storageDir);
            Log::info("HlsStreamService: Cleaned up HLS directory: {$storageDir} for {$type} {$id} {$channelInfo}.");
        } else {
            Log::debug("HlsStreamService: HLS directory not found for cleanup (already deleted or never created): {$storageDir} for {$type} {$id} {$channelInfo}.");
        }

        if ($decrementCount && $model && $model->playlist) {
            $this->decrementActiveStreams($model->playlist->id);
            Log::info("HlsStreamService: Decremented active stream count for playlist {$model->playlist->id} due to stop of {$type} {$id} {$channelInfo}.");
        }

        $mappingPattern = "hls:stream_mapping:{$type}:*";
        $mappingKeys = Redis::keys($mappingPattern);
        if (count($mappingKeys) > 0) {
            Log::debug("HlsStreamService: Found old stream mapping keys for pattern {$mappingPattern}. Cleaning up for {$type} {$id} {$channelInfo}...");
            foreach ($mappingKeys as $key) {
                if (Redis::get($key) == $id) {
                    Redis::del($key);
                    Log::debug("HlsStreamService: Deleted stream mapping key: {$key} for {$type} {$id} {$channelInfo}.");
                }
            }
        }
        Log::info("HlsStreamService: Finished cleaning up resources for {$type} {$id} {$channelInfo}. Was running: " . ($wasRunning ? 'yes' : 'no'));
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
        $isRunning = $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
        // Log::debug("HlsStreamService: isRunning check for {$type} {$id}: PID from cache: {$pid}, Running: " . ($isRunning ? 'yes' : 'no'));
        return $isRunning;
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
        Log::warning("HlsStreamService: isFfmpeg check falling back to basic posix_kill for PID {$pid} due to unsupported OS_FAMILY: " . PHP_OS_FAMILY);
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
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts disabled ';

            // Input analysis optimization for faster stream start
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';

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
            $cmd .= '-fps_mode passthrough '; // Add the fps_mode flag here
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

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }
}

[end of app/Services/HlsStreamService.php]
