<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
// Consider if ShouldBeUnique is needed:
// use Illuminate\Contracts\Queue\ShouldBeUnique;

class MonitorActiveStreamJob implements ShouldQueue //, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public int $streamSourceId;
    public ?int $originalRequesterId;

    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param int $streamSourceId
     * @param int|null $originalRequesterId
     */
    public function __construct(int $channelId, int $streamSourceId, ?int $originalRequesterId = null)
    {
        $this->channelId = $channelId;
        $this->streamSourceId = $streamSourceId;
        $this->originalRequesterId = $originalRequesterId;
    }

    /**
     * Execute the job.
     */
use App\Models\Channel;
use App\Models\ChannelStreamSource;
use App\Services\HlsStreamService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache; // Added for manifest state
use Throwable; // Added for broad exception catching

class MonitorActiveStreamJob implements ShouldQueue //, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public int $streamSourceId;
    public ?int $originalRequesterId;
    // Optional: Add properties for retry counts, if not using Laravel's built-in retry logic extensively
    // public int $currentRetryCount = 0;


    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param int $streamSourceId
     * @param int|null $originalRequesterId
     */
    public function __construct(int $channelId, int $streamSourceId, ?int $originalRequesterId = null)
    {
        $this->channelId = $channelId;
        $this->streamSourceId = $streamSourceId;
        $this->originalRequesterId = $originalRequesterId;
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('monitor_stream')->info("MonitorActiveStreamJob started for Channel ID: {$this->channelId}, Stream Source ID: {$this->streamSourceId}");

        try {
            $channel = Channel::find($this->channelId);
            if (!$channel) {
                Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Channel ID {$this->channelId} not found. Terminating job.");
                return;
            }

            $streamSource = ChannelStreamSource::find($this->streamSourceId);
            if (!$streamSource) {
                Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Stream Source ID {$this->streamSourceId} for Channel ID {$this->channelId} not found. Terminating job.");
                return;
            }

            // Ensure this stream source is still the active one.
            // Assuming 'channel' type for now, adjust if episodes are monitored similarly.
            $activeSourceIdRedisKey = "hls:active_source:channel:{$this->channelId}";
            $currentActiveSourceId = Redis::get($activeSourceIdRedisKey);

            if ((int)$currentActiveSourceId !== $this->streamSourceId) {
                Log::channel('monitor_stream')->info("MonitorActiveStreamJob: Stream Source ID {$this->streamSourceId} is no longer the active source for Channel ID {$this->channelId} (Active is {$currentActiveSourceId}). Terminating job.");
                // Potentially clean up old manifest state if any for this non-active source
                Redis::del("hls:manifest_state:{$this->streamSourceId}");
                return;
            }

            $healthCheckResult = $hlsStreamService->performHealthCheck($streamSource);
            $status = $healthCheckResult['status'] ?? 'unknown_error';

            if ($status === 'http_error' || $status === 'connection_error' || $status === 'manifest_error') {
                $streamSource->increment('consecutive_failures');
                $streamSource->last_failed_at = now();
                $maxFailures = config('failover.max_consecutive_failures', 3);
                $retryDelay = config('failover.monitoring_retry_delay', 5); // seconds

                Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Health check failed for Source ID {$this->streamSourceId} (Channel {$this->channelId}). Status: {$status}, Failures: {$streamSource->consecutive_failures}. Message: {$healthCheckResult['message'] ?? 'N/A'}");

                if ($streamSource->consecutive_failures >= $maxFailures) {
                    $streamSource->status = 'down';
                    Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Source ID {$this->streamSourceId} (Channel {$this->channelId}) reached max failures ({$streamSource->consecutive_failures}). Setting status to 'down' and dispatching HandleStreamFailoverJob.");
                    HandleStreamFailoverJob::dispatch($this->channelId, $this->streamSourceId);
                } else {
                    $streamSource->status = 'problematic'; // Mark as problematic on any failure
                    Log::channel('monitor_stream')->info("MonitorActiveStreamJob: Source ID {$this->streamSourceId} (Channel {$this->channelId}) is problematic. Rescheduling check in {$retryDelay}s.");
                    self::dispatch($this->channelId, $this->streamSourceId, $this->originalRequesterId)->delay(now()->addSeconds($retryDelay));
                }
                $streamSource->save();
                return;
            }

            if ($status === 'ok') {
                $manifestStateKey = "hls:manifest_state:{$this->streamSourceId}";
                $previousManifestStateJson = Redis::get($manifestStateKey);
                $previousManifestState = $previousManifestStateJson ? json_decode($previousManifestStateJson, true) : null;

                $currentMediaSequence = $healthCheckResult['media_sequence'] ?? null;
                $monitoringInterval = config('failover.monitoring_interval', 7); // seconds
                $stallThreshold = config('failover.stall_threshold_checks', 3); // Number of consecutive same sequence checks to trigger failover

                if ($previousManifestState && isset($previousManifestState['media_sequence']) && $currentMediaSequence !== null && (int)$previousManifestState['media_sequence'] === (int)$currentMediaSequence) {
                    // Media sequence hasn't changed. This could indicate a stall.
                    $stallChecks = ($previousManifestState['stall_checks'] ?? 0) + 1;
                    Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Media sequence {$currentMediaSequence} for Source ID {$this->streamSourceId} (Channel {$this->channelId}) has not changed. Stall checks: {$stallChecks}.");

                    if ($stallChecks >= $stallThreshold) {
                        $streamSource->increment('consecutive_failures'); // Use same counter or a dedicated one for stalls
                        $streamSource->last_failed_at = now();
                        $streamSource->status = 'problematic'; // Or 'stalled' if you add that status
                        $streamSource->save();
                        Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Source ID {$this->streamSourceId} (Channel {$this->channelId}) detected as stalled after {$stallChecks} checks. Dispatching HandleStreamFailoverJob.");
                        HandleStreamFailoverJob::dispatch($this->channelId, $this->streamSourceId);
                        Redis::del($manifestStateKey); // Clear stall tracking on failover
                        return;
                    } else {
                        // Not yet at stall threshold, update stall checks and reschedule
                        Redis::setex($manifestStateKey, $monitoringInterval * ($stallThreshold + 2), json_encode(['media_sequence' => $currentMediaSequence, 'stall_checks' => $stallChecks, 'last_checked_at' => now()->toIso8601String()]));
                        $streamSource->status = 'active'; // Still active, but being watched for stall
                        $streamSource->last_checked_at = now();
                        $streamSource->save();
                        self::dispatch($this->channelId, $this->streamSourceId, $this->originalRequesterId)->delay(now()->addSeconds($monitoringInterval));
                        return;
                    }
                } else {
                    // Media sequence changed or it's the first check, or currentMediaSequence is null (treat as healthy for now if segments exist)
                    if ($currentMediaSequence === null && ($healthCheckResult['segment_count'] ?? 0) === 0) {
                        // If no sequence and no segments, this is likely an issue.
                        Log::channel('monitor_stream')->warning("MonitorActiveStreamJob: Source ID {$this->streamSourceId} (Channel {$this->channelId}) has no media sequence and no segments. Treating as problematic.");
                        // This logic is similar to the http_error/connection_error block
                        $streamSource->increment('consecutive_failures');
                        $streamSource->last_failed_at = now();
                        $maxFailures = config('failover.max_consecutive_failures', 3);
                        $retryDelay = config('failover.monitoring_retry_delay', 5);
                        if ($streamSource->consecutive_failures >= $maxFailures) {
                            $streamSource->status = 'down';
                            HandleStreamFailoverJob::dispatch($this->channelId, $this->streamSourceId);
                        } else {
                            $streamSource->status = 'problematic';
                            self::dispatch($this->channelId, $this->streamSourceId, $this->originalRequesterId)->delay(now()->addSeconds($retryDelay));
                        }
                        $streamSource->save();
                        return;
                    }

                    // Healthy state or first check
                    Log::channel('monitor_stream')->info("MonitorActiveStreamJob: Health check OK for Source ID {$this->streamSourceId} (Channel {$this->channelId}). Media Sequence: {$currentMediaSequence}.");
                    $streamSource->consecutive_failures = 0;
                    $streamSource->status = 'active';
                    $streamSource->last_checked_at = now();
                    $streamSource->save();
                    Redis::setex($manifestStateKey, $monitoringInterval * ($stallThreshold + 2), json_encode(['media_sequence' => $currentMediaSequence, 'stall_checks' => 0, 'last_checked_at' => now()->toIso8601String()]));
                    self::dispatch($this->channelId, $this->streamSourceId, $this->originalRequesterId)->delay(now()->addSeconds($monitoringInterval));
                    return;
                }
            } else {
                 Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Unhandled health check status '{$status}' for Source ID {$this->streamSourceId} (Channel {$this->channelId}). Health check result: " . json_encode($healthCheckResult));
                 // Potentially treat as a failure and reschedule or failover
                 $streamSource->increment('consecutive_failures');
                 $streamSource->last_failed_at = now();
                 $streamSource->status = 'problematic';
                 $streamSource->save();
                 $retryDelay = config('failover.monitoring_retry_delay', 15); // Longer delay for unknown issues
                 self::dispatch($this->channelId, $this->streamSourceId, $this->originalRequesterId)->delay(now()->addSeconds($retryDelay));
            }

        } catch (Throwable $e) {
            Log::channel('monitor_stream')->error("MonitorActiveStreamJob: Exception for Channel ID {$this->channelId}, Stream Source ID {$this->streamSourceId}: {$e->getMessage()}");
            // Attempt to release the job back to the queue with a delay for transient issues
            // Customize retry logic as needed, possibly with backoff
            if ($this->attempts() < config('failover.monitor_job_max_attempts', 3)) {
                $this->release(config('failover.monitor_job_retry_delay', 60));
            } else {
                // Max attempts reached, log critical error. Consider marking source as problematic.
                Log::channel('monitor_stream')->critical("MonitorActiveStreamJob: Max attempts reached for Channel ID {$this->channelId}, Stream Source ID {$this->streamSourceId}. Error: {$e->getMessage()}");
                $streamSource = ChannelStreamSource::find($this->streamSourceId);
                if ($streamSource) {
                    $streamSource->status = 'problematic'; // Or 'unknown_error'
                    $streamSource->notes = ($streamSource->notes ?? '') . "\nMax job attempts reached: " . $e->getMessage();
                    $streamSource->save();
                }
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
    //     return 'monitor_stream_' . $this->channelId . '_' . $this->streamSourceId;
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
