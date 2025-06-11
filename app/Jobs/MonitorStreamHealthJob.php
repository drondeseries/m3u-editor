<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelStreamProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Facades\Cache;
use App\Jobs\InitiateFailoverJob;
use Illuminate\Contracts\Cache\LockTimeoutException;

class MonitorStreamHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $channel_id,
        public int $stream_provider_id
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseLogPrefix = "MonitorStreamHealthJob: [channel_id: {$this->channel_id}, stream_provider_id: {$this->stream_provider_id}, attempt: {$this->attempts()}]";
        Log::info("{$baseLogPrefix} Starting health check.");

        $streamProvider = ChannelStreamProvider::find($this->stream_provider_id);

        if (!$streamProvider) {
            Log::error("{$baseLogPrefix} ChannelStreamProvider not found. Job aborting.");
            return;
        }

        $providerLogName = "provider {$streamProvider->id} ('{$streamProvider->provider_name}', URL: {$streamProvider->stream_url}) for channel {$this->channel_id}";
        $logPrefix = "MonitorStreamHealthJob: [{$providerLogName}, attempt: {$this->attempts()}]";


        if ($streamProvider->channel_id !== $this->channel_id) {
            Log::error("{$logPrefix} Stream provider's channel_id {$streamProvider->channel_id} does not match job's channel_id {$this->channel_id}. Aborting.");
            return;
        }

        $channel = Channel::find($this->channel_id);
        if (!$channel) {
            Log::error("{$logPrefix} Channel with id {$this->channel_id} not found. Marking provider {$streamProvider->id} as 'offline'.");
            $streamProvider->last_checked_at = now();
            $streamProvider->status = 'offline';
            $streamProvider->save();
            Log::info("{$logPrefix} Job finished due to missing channel.");
            return;
        }

        // Update logPrefix with full channel info
        $channelNameForLog = strip_tags($channel->title_custom ?? $channel->title);
        $providerLogName = "provider {$streamProvider->id} ('{$streamProvider->provider_name}', URL: {$streamProvider->stream_url}) for channel {$channel->id} ('{$channelNameForLog}')";
        $logPrefix = "MonitorStreamHealthJob: [{$providerLogName}, attempt: {$this->attempts()}]";
        Log::debug("{$logPrefix} Processing health check.");

        $streamProvider->last_checked_at = now();
        $newStatus = 'offline';
        $exceptionMessage = null;

        try {
            Log::debug("{$logPrefix} Step 1: Performing HTTP HEAD request to {$streamProvider->stream_url}.");
            $response = Http::timeout(5)->head($streamProvider->stream_url);

            if ($response->successful()) {
                Log::debug("{$logPrefix} HEAD request successful.");
                $newStatus = 'online';

                Log::debug("{$logPrefix} Step 2: Performing segment fetch check (simplified) from {$streamProvider->stream_url}.");
                $segmentResponse = Http::timeout(10)->get($streamProvider->stream_url);
                if (!$segmentResponse->successful() || empty($segmentResponse->body())) {
                    $newStatus = 'offline';
                    Log::warning("{$logPrefix} Segment fetch check failed. HTTP Status: {$segmentResponse->status()}, Empty body: " . (empty($segmentResponse->body()) ? 'yes' : 'no'));
                } else {
                    Log::debug("{$logPrefix} Segment fetch check successful.");
                    $simulateBufferingChance = config('streams.simulate_buffering_chance', 0.1); // Example: Get from config
                    if ($simulateBufferingChance > 0 && rand(1, 100) <= ($simulateBufferingChance * 100)) {
                        $newStatus = 'buffering';
                        Log::info("{$logPrefix} Simulated 'buffering' status based on configuration (chance: {$simulateBufferingChance}).");
                    }
                }
            } else {
                Log::warning("{$logPrefix} HEAD request failed. HTTP Status: {$response->status()}.");
                $newStatus = 'offline';
            }
        } catch (Throwable $e) {
            $exceptionMessage = $e->getMessage();
            Log::error("{$logPrefix} Exception during health check: {$exceptionMessage}", ['exception_trace' => $e->getTraceAsString()]);
            $newStatus = 'offline';
        }

        $oldStatus = $streamProvider->status;
        if ($oldStatus !== $newStatus || $exceptionMessage) {
            Log::info("{$logPrefix} Status changing from '{$oldStatus}' to '{$newStatus}'.", $exceptionMessage ? ['error' => $exceptionMessage] : []);
        }
        $streamProvider->status = $newStatus;
        $streamProvider->save();

        if (in_array($newStatus, ['offline', 'buffering'])) {
            // Lock before potentially dispatching failover to prevent multiple dispatches for the same channel problem
            $lockKey = "monitor-dispatch-failover-{$this->channel_id}";
            $lock = Cache::lock($lockKey, 30); // Lock for 30 seconds

            try {
                // Attempt to acquire the lock without blocking. If lock is not acquired, another process is likely handling it.
                if ($lock->get()) {
                    Log::debug("{$logPrefix} Lock '{$lockKey}' acquired for deciding failover dispatch.");
                    // Re-fetch channel to ensure current state before dispatching, as it might have changed
                    $channel->refresh();

                    if ($channel->current_stream_provider_id === $streamProvider->id && !in_array($channel->stream_status, ['switching', 'failed'])) {
                        Log::error("{$logPrefix} Currently active provider is now {$newStatus}. Dispatching InitiateFailoverJob.");
                        InitiateFailoverJob::dispatch($this->channel_id, $this->stream_provider_id)->onQueue('stream-operations');

                        // Set channel status to 'switching' to prevent other monitors from re-triggering immediately
                        // and to inform the HLS controller that a change is underway.
                        $channel->stream_status = 'switching';
                        $channel->save();
                        Log::info("{$logPrefix} Channel {$channel->id} status set to 'switching' due to provider {$streamProvider->id} failure.");
                    } else {
                        Log::info("{$logPrefix} Provider is {$newStatus}, but it's either not the current active provider for channel {$channel->id} (current provider: {$channel->current_stream_provider_id}, current channel status: {$channel->stream_status}), or failover/failed state already set. No InitiateFailoverJob dispatched by this instance.");
                    }
                } else {
                    Log::warning("{$logPrefix} Could not acquire lock '{$lockKey}' to dispatch failover. Another monitor job instance for channel {$this->channel_id} might be processing this event, or a failover is already in progress.");
                }
            } catch (LockTimeoutException $e) {
                 // This should ideally not be reached if using $lock->get() as it's non-blocking.
                 Log::error("{$logPrefix} LockTimeoutException while trying to acquire '{$lockKey}'. This is unexpected with a non-blocking lock attempt.", ['exception' => $e->getMessage()]);
            } finally {
                optional($lock)->release();
                Log::debug("{$logPrefix} Lock '{$lockKey}' released after failover decision.");
            }

        } elseif ($newStatus === 'online') {
            if ($oldStatus && in_array($oldStatus, ['offline', 'buffering'])) {
                 Log::info("{$logPrefix} Provider has recovered (was '{$oldStatus}', now '{$newStatus}').");
            }

            if ($channel->current_stream_provider_id === $streamProvider->id) {
                // This is the active provider and it's online
                if ($channel->stream_status !== 'playing') {
                    $channel->stream_status = 'playing';
                    $channel->save();
                    Log::info("{$logPrefix} Active provider is online. Channel {$channel->id} status ensured/updated to 'playing'.");
                }
            } else {
                // This online provider is NOT the current active one for the channel
                $currentActiveProvider = $channel->currentStreamProvider; // Fetched via $channel relationship
                if ($currentActiveProvider && $streamProvider->priority < $currentActiveProvider->priority) {
                    Log::info("{$logPrefix} (Backup) Provider is online and has higher priority (P{$streamProvider->priority}) than current active provider {$currentActiveProvider->id} ('{$currentActiveProvider->provider_name}', P{$currentActiveProvider->priority}) for channel {$channel->id}. Potential switch-back candidate.");
                } elseif (!$currentActiveProvider && $channel->stream_status !== 'playing') {
                     Log::info("{$logPrefix} (Backup) Provider is online. Channel {$channel->id} has no current active provider (status: {$channel->stream_status}). This provider could be a candidate for activation if channel is 'failed'. An InitiateFailoverJob (without failed_provider_id) or next client request could trigger this.");
                }
            }
        }
        Log::info("{$logPrefix} Health check finished. Final provider status: {$newStatus}.");
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        // Try to get more info for logging
        $providerName = "ID {$this->stream_provider_id}";
        $channelName = "ID {$this->channel_id}";
        try {
            $streamProvider = ChannelStreamProvider::find($this->stream_provider_id);
            if ($streamProvider) {
                $providerName = "{$streamProvider->id} ('{$streamProvider->provider_name}')";
                if ($streamProvider->channel) {
                    $channelName = "{$streamProvider->channel->id} ('" . strip_tags($streamProvider->channel->title_custom ?? $streamProvider->channel->title) . "')";
                }
            }
        } catch (Throwable $e) {
            // Ignore error during logging enhancement
        }

        $logPrefix = "MonitorStreamHealthJob FAILED: [channel {$channelName}, provider {$providerName}, attempt: {$this->attempts()}]";
        Log::error("{$logPrefix} Error: {$exception->getMessage()}", [
            'exception' => $exception // Log the full exception object for better inspection
        ]);

        $streamProvider = ChannelStreamProvider::find($this->stream_provider_id); // Re-fetch, might have changed
        if ($streamProvider) {
            $streamProvider->status = 'offline';
            $streamProvider->last_checked_at = now();
            $streamProvider->save();
            Log::warning("{$logPrefix} Marked provider {$this->stream_provider_id} as 'offline' due to job failure.");

            $channel = Channel::find($this->channel_id);
            if ($channel && $channel->current_stream_provider_id === $streamProvider->id) {
                 Log::error("{$logPrefix} Currently active provider's monitoring job failed. Dispatching InitiateFailoverJob.");
                 // Use a lock here as well to prevent race conditions if multiple failed attempts trigger this quickly
                $lockKey = "monitor-dispatch-failover-{$this->channel_id}";
                $lock = Cache::lock($lockKey, 30);
                try {
                    if ($lock->get()) {
                        $channel->refresh(); // Get latest status
                        if ($channel->current_stream_provider_id === $streamProvider->id && !in_array($channel->stream_status, ['switching', 'failed'])) {
                           InitiateFailoverJob::dispatch($this->channel_id, $this->stream_provider_id)->onQueue('stream-operations');
                           $channel->stream_status = 'switching'; // Indicate failover is being attempted
                           $channel->save();
                           Log::info("{$logPrefix} Dispatched InitiateFailoverJob and set channel status to 'switching'.");
                        } else {
                            Log::info("{$logPrefix} Failover not dispatched from failed job as channel status is '{$channel->stream_status}' or provider changed.");
                        }
                    } else {
                        Log::warning("{$logPrefix} Could not acquire lock '{$lockKey}' in failed() method. Failover dispatch skipped.");
                    }
                } finally {
                    optional($lock)->release();
                }
            }
        } else {
            Log::error("{$logPrefix} ChannelStreamProvider with id {$this->stream_provider_id} not found during failure handling.");
        }
    }
}
