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

class InitiateFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180; // Allow time for stopping and starting streams

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
        $channelLogName = "channel {$this->channel_id}"; // Initial log name
        Log::info("InitiateFailoverJob: Starting for {$channelLogName}, failed_provider_id: {$this->failed_provider_id}.");

        $channel = Channel::with(['streamProviders' => function ($query) {
            $query->where('is_active', true)->orderBy('priority');
        }])->find($this->channel_id);

        if (!$channel) {
            Log::error("InitiateFailoverJob: Channel with id {$this->channel_id} not found. Job aborting.");
            return;
        }
        // Update channelLogName to include title if available
        $channelLogName = "channel {$channel->id} ('" . strip_tags($channel->title_custom ?? $channel->title) . "')";

        if ($channel->streamProviders->isEmpty()) {
            Log::warning("InitiateFailoverJob: No active stream providers found for {$channelLogName}. Setting channel status to 'failed'.");
            $channel->stream_status = 'failed';
            $channel->current_stream_provider_id = null;
            $channel->save();
            Log::info("InitiateFailoverJob: Finished for {$channelLogName}. No active providers.");
            return;
        }

        Log::info("InitiateFailoverJob: Found {$channel->streamProviders->count()} active providers for {$channelLogName}. Current provider on channel: {$channel->current_stream_provider_id}, Explicitly failed provider: {$this->failed_provider_id}.");

        $eligibleProviders = $channel->streamProviders
            ->when($this->failed_provider_id, function ($collection) {
                // Ensure we don't retry the provider that just triggered this failover, if it's passed.
                return $collection->filter(function ($provider) {
                    return $provider->id !== $this->failed_provider_id;
                });
            });

        if ($eligibleProviders->isEmpty()) {
            Log::warning("InitiateFailoverJob: No eligible providers to failover to for {$channelLogName} after excluding failed_provider_id: {$this->failed_provider_id}. Setting channel status to 'failed'.");
            $channel->stream_status = 'failed';
            // Not changing current_stream_provider_id here, to keep context of last attempt if any.
            $channel->save();
            Log::info("InitiateFailoverJob: Finished for {$channelLogName}. No eligible providers left.");
            return;
        }

        $currentProviderIdOnChannelRecord = $channel->current_stream_provider_id;
        $switched = false;

        foreach ($eligibleProviders as $newProvider) {
            $providerLogName = "provider {$newProvider->id} ('{$newProvider->provider_name}', URL: {$newProvider->stream_url})";

            if ($newProvider->id === $currentProviderIdOnChannelRecord && $newProvider->id === $this->failed_provider_id) {
                // This case means the currently set provider on the channel is the one that failed.
                // We are trying it again only if it's the *only* one left in eligibleProviders.
                // However, the filter ->when($this->failed_provider_id, ...) should prevent this unless eligibleProviders has only this one.
                Log::warning("InitiateFailoverJob: Attempting to failover to the same provider ({$newProvider->id}) that was marked as failed for {$channelLogName}. This implies it might be the only/last option or a direct re-check was intended.");
            } elseif ($newProvider->id === $currentProviderIdOnChannelRecord) {
                 Log::info("InitiateFailoverJob: Next eligible provider {$newProvider->id} is the same as current active provider recorded on {$channelLogName}. This is unusual if failover was triggered by its failure, but proceeding with switch attempt.");
            }

            Log::info("InitiateFailoverJob: Attempting to switch {$channelLogName} to {$providerLogName}.");

            // Skip if provider is marked offline and it's NOT the one that specifically triggered this job (failed_provider_id)
            // and also not the one currently set on the channel (currentProviderIdOnChannelRecord).
            // This allows a re-attempt on the current or just-failed provider.
            if ($newProvider->status === 'offline' && $newProvider->id !== $this->failed_provider_id && $newProvider->id !== $currentProviderIdOnChannelRecord) {
                 Log::info("InitiateFailoverJob: Skipping {$providerLogName} for {$channelLogName} as its status is 'offline' and it's not the immediately problematic one. It might be checked by CheckProblematicStreamJob.");
                 continue;
            }

            try {
                $success = $hlsStreamService->switchStreamProvider($channel, $newProvider);
                if ($success) {
                    Log::info("InitiateFailoverJob: Successfully switched {$channelLogName} to new {$providerLogName}.");
                    $switched = true;
                    break;
                } else {
                    Log::warning("InitiateFailoverJob: Failed to switch {$channelLogName} to {$providerLogName}. HlsStreamService::switchStreamProvider returned false. Provider likely marked offline. Trying next provider.");
                }
            } catch (Throwable $e) {
                Log::error("InitiateFailoverJob: Exception while trying to switch {$channelLogName} to {$providerLogName}. Error: {$e->getMessage()}", ['exception' => $e]);
                // Ensure provider is marked offline if switchStreamProvider didn't catch this specific exception type for status update
                if ($newProvider->status !== 'offline') {
                    $newProvider->status = 'offline';
                    $newProvider->last_checked_at = now();
                    $newProvider->save();
                    Log::warning("InitiateFailoverJob: Marked {$providerLogName} as offline for {$channelLogName} due to exception during switch attempt: {$e->getMessage()}");
                }
            }
        }

        if (!$switched) {
            Log::critical("InitiateFailoverJob: All eligible stream providers failed for {$channelLogName}. Setting channel stream_status to 'failed'. No working provider found.");
            $channel->stream_status = 'failed';
            // $channel->current_stream_provider_id = null; // Keep last failed provider for context
            $channel->save();
        }
        Log::info("InitiateFailoverJob: Finished for {$channelLogName}. Switched: " . ($switched ? "yes (to provider {$channel->current_stream_provider_id})" : "no") . ". Final channel status: {$channel->stream_status}, current provider on channel: {$channel->current_stream_provider_id}.");
    }

     /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $channelLogName = "channel {$this->channel_id}";
        // Attempt to load channel title for richer logging
        $channel = Channel::find($this->channel_id);
        if ($channel) {
            $channelLogName = "channel {$channel->id} ('" . strip_tags($channel->title_custom ?? $channel->title) . "')";
        }

        Log::critical("InitiateFailoverJob: CRITICAL JOB FAILURE for {$channelLogName} (Failed Provider ID: {$this->failed_provider_id}). Error: {$exception->getMessage()}", [
            'channel_id' => $this->channel_id,
            'failed_provider_id' => $this->failed_provider_id,
            'exception' => $exception
        ]);

        if ($channel) {
            // If the job itself fails, it's a severe issue. Mark channel as failed.
            if ($channel->stream_status !== 'failed') {
                $channel->stream_status = 'failed';
                $channel->save();
                Log::error("InitiateFailoverJob: {$channelLogName} stream_status set to 'failed' due to unhandled job exception.");
            }
        }
    }
}
