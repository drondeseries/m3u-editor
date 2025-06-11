<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config; // Added for config access
use Throwable; // Added for broad exception catching
// Consider if ShouldBeUnique is needed:
// use Illuminate\Contracts\Queue\ShouldBeUnique;

class MonitorActiveStreamJob implements ShouldQueue //, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public string $monitoringUrl;
    public string $streamType; // e.g., 'channel', 'episode'
    public ?int $userId;

    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param string $monitoringUrl
     * @param string $streamType
     * @param int|null $userId
     */
    public function __construct(int $channelId, string $monitoringUrl, string $streamType, ?int $userId = null)
    {
        $this->channelId = $channelId;
        $this->monitoringUrl = $monitoringUrl;
        $this->streamType = $streamType;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('monitor_stream')->info("MonitorActiveStreamJob started for {$this->streamType} ID: {$this->channelId}, Monitoring URL: {$this->monitoringUrl}");

        try {
            $model = null;
            if ($this->streamType === 'channel') {
                $model = Channel::with('playlist')->find($this->channelId);
            } elseif ($this->streamType === 'episode') {
                $model = Episode::with('playlist')->find($this->channelId);
            }

            if (!$model || !$model->playlist) {
                Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Model or playlist not found for {$this->streamType} ID {$this->channelId}. Terminating job.");
                return;
            }

            $playlistUserAgent = isset($model->playlist->user_agent) ? $model->playlist->user_agent : null;

            $activeUrlRedisKey = "hls:active_url:{$this->streamType}:{$this->channelId}";
            $currentActiveUrl = Redis::get($activeUrlRedisKey);

            if ($currentActiveUrl !== $this->monitoringUrl) {
                Log::channel('monitor_stream')->info("MonitorActiveStreamJob: Monitoring URL {$this->monitoringUrl} is no longer the active URL for {$this->streamType} ID {$this->channelId} (Active is {$currentActiveUrl}). Terminating job.");
                Redis::del("hls:manifest_state:" . md5($this->monitoringUrl)); // Clean up old manifest state
                Redis::del("hls:url_failures:" . md5($this->monitoringUrl));
                Redis::del("hls:url_stalls:" . md5($this->monitoringUrl));
                return;
            }

            // For URL monitoring, custom headers for performHealthCheck would typically be null unless they are globally defined for the channel/episode
            $healthCheckResult = $hlsStreamService->performHealthCheck($this->monitoringUrl, null, $playlistUserAgent);
            $status = isset($healthCheckResult['status']) ? $healthCheckResult['status'] : 'unknown_error';

            $failureCountKey = "hls:url_failures:" . md5($this->monitoringUrl);
            $stallCountKey = "hls:url_stalls:" . md5($this->monitoringUrl);
            $manifestStateKey = "hls:manifest_state:" . md5($this->monitoringUrl);

            if ($status === 'http_error' || $status === 'connection_error' || $status === 'manifest_error') {
                $currentFailures = Redis::incr($failureCountKey);
                Redis::expire($failureCountKey, Config::get('failover.redis_counter_ttl', 3600)); // TTL for counter

                $maxFailures = Config::get('failover.max_consecutive_url_failures', 3);
                $retryDelay = Config::get('failover.monitoring_retry_delay', 5);

                $logMessageContent = isset($healthCheckResult['message']) ? $healthCheckResult['message'] : 'N/A';
                Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Health check failed for URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}). Status: {$status}, Failures: {$currentFailures}. Message: {$logMessageContent}");

                if ($currentFailures >= $maxFailures) {
                    Log::channel('monitor_stream')->error("MonitorActiveStreamJob: URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}) reached max failures ({$currentFailures}). Dispatching HandleStreamFailoverJob.");
                    HandleStreamFailoverJob::dispatch($this->channelId, $this->monitoringUrl, $this->streamType, $this->userId);
                    Redis::sadd("hls:problematic_urls", json_encode(['channel_id' => $this->channelId, 'type' => $this->streamType, 'url' => $this->monitoringUrl, 'user_agent' => $playlistUserAgent, 'failed_at' => now()->timestamp]));
                    Redis::del($failureCountKey, $stallCountKey, $manifestStateKey);
                } else {
                    Log::channel('monitor_stream')->info("MonitorActiveStreamJob: URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}) is problematic. Rescheduling check in {$retryDelay}s.");
                    self::dispatch($this->channelId, $this->monitoringUrl, $this->streamType, $this->userId)->delay(now()->addSeconds($retryDelay));
                }
                return;
            }

            if ($status === 'ok') {
                $previousManifestStateJson = Redis::get($manifestStateKey);
                $previousManifestState = $previousManifestStateJson ? json_decode($previousManifestStateJson, true) : null;
                $currentMediaSequence = isset($healthCheckResult['media_sequence']) ? $healthCheckResult['media_sequence'] : null;
                $monitoringInterval = Config::get('failover.monitoring_interval', 7);
                $maxStallCounts = Config::get('failover.max_stall_counts', 3);

                if ($previousManifestState && isset($previousManifestState['media_sequence']) && $currentMediaSequence !== null && (int)$previousManifestState['media_sequence'] === (int)$currentMediaSequence) {
                    $currentStalls = Redis::incr($stallCountKey);
                    Redis::expire($stallCountKey, Config::get('failover.redis_counter_ttl', 3600));
                    Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Media sequence {$currentMediaSequence} for URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}) has not changed. Stall counts: {$currentStalls}.");

                    if ($currentStalls >= $maxStallCounts) {
                        Log::channel('monitor_stream')->error("MonitorActiveStreamJob: URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}) detected as stalled after {$currentStalls} checks. Dispatching HandleStreamFailoverJob.");
                        HandleStreamFailoverJob::dispatch($this->channelId, $this->monitoringUrl, $this->streamType, $this->userId);
                        Redis::sadd("hls:problematic_urls", json_encode(['channel_id' => $this->channelId, 'type' => $this->streamType, 'url' => $this->monitoringUrl, 'user_agent' => $playlistUserAgent, 'failed_at' => now()->timestamp, 'reason' => 'stalled']));
                        Redis::del($failureCountKey, $stallCountKey, $manifestStateKey);
                        return;
                    }
                     // Update manifest state with new stall count and last checked time
                    Redis::setex($manifestStateKey, $monitoringInterval * ($maxStallCounts + 2), json_encode(['media_sequence' => $currentMediaSequence, 'last_checked_at' => now()->toIso8601String()]));
                } else {
                    // Healthy or first check, or sequence changed
                    Redis::del($failureCountKey, $stallCountKey); // Clear failure and stall counts on healthy check
                    // Store new manifest state
                    Redis::setex($manifestStateKey, $monitoringInterval * ($maxStallCounts + 2), json_encode(['media_sequence' => $currentMediaSequence, 'last_checked_at' => now()->toIso8601String()]));
                    Log::channel('monitor_stream')->info("MonitorActiveStreamJob: Health check OK for URL {$this->monitoringUrl} ({$this->streamType} ID {$this->channelId}). Media Sequence: {$currentMediaSequence}. Resetting failure/stall counts.");
                }
                // Reschedule for next interval
                self::dispatch($this->channelId, $this->monitoringUrl, $this->streamType, $this->userId)->delay(now()->addSeconds($monitoringInterval));
                return;
            } else {
                 Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Unhandled health check status '{$status}' for URL {$this->monitoringUrl}. Result: " . json_encode($healthCheckResult));
                 $retryDelay = Config::get('failover.monitoring_retry_delay', 15);
                 self::dispatch($this->channelId, $this->monitoringUrl, $this->streamType, $this->userId)->delay(now()->addSeconds($retryDelay));
            }

        } catch (Throwable $e) {
            Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Exception for {$this->streamType} ID {$this->channelId}, URL {$this->monitoringUrl}: {$e->getMessage()}");
            if ($this->attempts() < Config::get('failover.monitor_job_max_attempts', 3)) {
                $this->release(Config::get('failover.monitor_job_retry_delay', 60));
            } else {
                Log::channel('monitor_stream')->critical("MonitorActiveStreamJob: Max attempts reached for {$this->streamType} ID {$this->channelId}, URL {$this->monitoringUrl}. Error: {$e->getMessage()}");
                // Potentially add to problematic URLs here too if it repeatedly fails with exceptions
                // Redis::sadd("hls:problematic_urls", json_encode(['channel_id' => $this->channelId, 'type' => $this->streamType, 'url' => $this->monitoringUrl, 'user_agent' => $playlistUserAgent ?? null, 'failed_at' => now()->timestamp, 'reason' => 'job_exception']));
            }
        }
    }

    /**
     * The unique ID of the job.
     *
     * @return string
     */
    // public function uniqueId(): string
    // {
    //     // Generate a unique ID to prevent duplicate jobs if using ShouldBeUnique
    //     return 'monitor_stream_' . $this->channelId . '_' . md5($this->monitoringUrl) . '_' . $this->streamType;
    // }

    /**
     * Get the cache driver for the unique job lock.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    // public function uniqueVia()
    // {
    //     // Specify the cache store to use for ShouldBeUnique
    //     return Cache::store('redis'); // Or your configured cache store
    // }
}
