<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelStreamProvider;
use App\Services\HlsStreamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Facades\Cache;
use App\Jobs\MonitorStreamHealthJob;
use Illuminate\Contracts\Cache\LockTimeoutException;

class InitiateFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180; // Allow time for stopping and starting streams
    public int $backoff = 60; // Delay before retrying a failed job execution

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $channel_id,
        public ?int $failed_provider_id = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        $baseLogPrefix = "InitiateFailoverJob: [channel_id: {$this->channel_id}, failed_provider_id: {$this->failed_provider_id}, attempt: {$this->attempts()}]";
        Log::info("{$baseLogPrefix} Starting failover process.");

        $lockKey = "failover-job-{$this->channel_id}";
        $lock = Cache::lock($lockKey, $this->timeout - 10); // Lock for slightly less than job timeout

        try {
            if (!$lock->block(5)) { // Wait up to 5 seconds
                Log::warning("{$baseLogPrefix} Could not acquire lock '{$lockKey}'. Another failover job may be active. Releasing job.");
                $this->release(60); // Release back to queue with a delay
                return;
            }
            Log::debug("{$baseLogPrefix} Lock '{$lockKey}' acquired.");

            $channel = Channel::with(['streamProviders' => function ($query) {
                $query->where('is_active', true)->orderBy('priority');
            }])->find($this->channel_id);

            if (!$channel) {
                Log::error("{$baseLogPrefix} Channel not found. Job aborting.");
                return;
            }

            $channelLogName = "channel {$channel->id} ('" . strip_tags($channel->title_custom ?? $channel->title) . "')";
            $logPrefix = "InitiateFailoverJob: [{$channelLogName}, failed_provider_id: {$this->failed_provider_id}, attempt: {$this->attempts()}]"; // Updated prefix

            // If the channel is already playing with a different provider than the one that failed (if any),
            // and that current provider is healthy (or at least not the one that triggered this failover),
            // the failover might have already occurred or is no longer needed for this specific trigger.
            if ($channel->stream_status === 'playing' && $channel->current_stream_provider_id && $channel->current_stream_provider_id !== $this->failed_provider_id) {
                Log::info("{$logPrefix} Channel is already 'playing' with provider {$channel->current_stream_provider_id}. Failover for provider {$this->failed_provider_id} might be obsolete. Job will ensure current stream is monitored.");
                MonitorStreamHealthJob::dispatch($channel->id, $channel->current_stream_provider_id)->onQueue('stream-monitoring');
                return;
            }

            if ($channel->streamProviders->isEmpty()) {
                Log::warning("{$logPrefix} No active stream providers found. Setting channel status to 'failed'.");
                $channel->stream_status = 'failed';
                $channel->current_stream_provider_id = null;
                $channel->save();
                Log::info("{$logPrefix} Job finished: No active providers.");
                return;
            }

            Log::info("{$logPrefix} Found {$channel->streamProviders->count()} active providers. Current provider on channel: {$channel->current_stream_provider_id}.");

            $eligibleProviders = $channel->streamProviders
                ->when($this->failed_provider_id, function ($collection) {
                    return $collection->filter(function ($provider) {
                        return $provider->id !== $this->failed_provider_id;
                    });
                });

            if ($eligibleProviders->isEmpty()) {
                Log::warning("{$logPrefix} No eligible providers to failover to after excluding failed_provider_id: {$this->failed_provider_id}. Setting channel status to 'failed'.");
                $channel->stream_status = 'failed';
                $channel->save();
                Log::info("{$logPrefix} Job finished: No eligible providers left.");
                return;
            }

            $currentProviderIdOnChannelRecord = $channel->current_stream_provider_id;
            $switched = false;

            foreach ($eligibleProviders as $newProvider) {
                $providerLogName = "provider {$newProvider->id} ('{$newProvider->provider_name}', URL: {$newProvider->stream_url})";

                if ($newProvider->id === $currentProviderIdOnChannelRecord && $newProvider->id === $this->failed_provider_id) {
                    Log::warning("{$logPrefix} Attempting to failover to the same provider ({$newProvider->id}) that was marked as failed. This implies it was the active stream and failed. Will re-attempt this provider.");
                } elseif ($newProvider->id === $currentProviderIdOnChannelRecord) {
                     Log::info("{$logPrefix} Next eligible provider {$newProvider->id} is the same as current active provider recorded. This is unusual if failover was triggered by its failure, but proceeding with switch attempt.");
                }

                Log::info("{$logPrefix} Attempting to switch to {$providerLogName}.");

                if ($newProvider->status === 'offline' && $newProvider->id !== $this->failed_provider_id && $newProvider->id !== $currentProviderIdOnChannelRecord) {
                     Log::info("{$logPrefix} Skipping {$providerLogName} as its status is 'offline' and it's not the one that just failed or was current. It might be checked by CheckProblematicStreamJob.");
                     continue;
                }

                try {
                    // switchStreamProvider itself now contains a lock.
                    $success = $hlsStreamService->switchStreamProvider($channel, $newProvider);
                    if ($success) {
                        Log::info("{$logPrefix} Successfully switched to new {$providerLogName}.");
                        $switched = true;
                        break;
                    } else {
                        Log::warning("{$logPrefix} Failed to switch to {$providerLogName}. HlsStreamService::switchStreamProvider returned false. Provider likely marked offline. Trying next provider.");
                    }
                } catch (Throwable $e) {
                    Log::error("{$logPrefix} Exception while trying to switch to {$providerLogName}. Error: {$e->getMessage()}", ['exception_trace' => $e->getTraceAsString()]);
                    if ($newProvider->status !== 'offline') { // Ensure it's marked offline on any error during switch
                        $newProvider->status = 'offline';
                        $newProvider->last_checked_at = now();
                        $newProvider->save();
                        Log::warning("{$logPrefix} Marked {$providerLogName} as offline due to exception during switch attempt: {$e->getMessage()}");
                    }
                }
            }

            if (!$switched) {
                Log::critical("{$logPrefix} All eligible stream providers failed. Setting channel stream_status to 'failed'. No working provider found.");
                $channel->stream_status = 'failed';
                // $channel->current_stream_provider_id = null; // Keep last attempted for context or nullify? For now, keep.
                $channel->save();
            }
            // Refresh channel for final status log
            $channel->refresh();
            Log::info("{$logPrefix} Finished. Switched: " . ($switched ? "yes (to provider {$channel->current_stream_provider_id})" : "no") . ". Final channel status: {$channel->stream_status}, current provider: {$channel->current_stream_provider_id}.");

        } catch (LockTimeoutException $e) {
            Log::warning("{$baseLogPrefix} LockTimeoutException. Could not acquire lock '{$lockKey}'. Releasing job.", ['exception' => $e->getMessage()]);
            $this->release(60); // Release back to queue
        } finally {
            optional($lock)->release();
            Log::debug("{$baseLogPrefix} Lock '{$lockKey}' released.");
        }
    }

     /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $channelLogName = "channel {$this->channel_id}";
        $channel = Channel::find($this->channel_id); // Attempt to get channel for more context
        if ($channel) {
            $channelLogName = "channel {$channel->id} ('" . strip_tags($channel->title_custom ?? $channel->title) . "')";
            // If the job itself fails critically, ensure channel is marked as failed.
            if ($channel->stream_status !== 'failed') {
                 $channel->stream_status = 'failed';
                 // $channel->current_stream_provider_id = null; // Consider if nullifying is best here
                 $channel->save();
                 Log::error("InitiateFailoverJob: {$channelLogName} stream_status set to 'failed' due to unhandled job exception in InitiateFailoverJob::failed().");
            }
        }

        Log::critical("InitiateFailoverJob: CRITICAL JOB FAILURE for {$channelLogName} (Failed Provider ID was: {$this->failed_provider_id}). Error: {$exception->getMessage()}", [
            'channel_id' => $this->channel_id,
            'failed_provider_id' => $this->failed_provider_id,
            'exception_message' => $exception->getMessage(),
            'exception_trace' => $exception->getTraceAsString(),
        ]);
    }
}
