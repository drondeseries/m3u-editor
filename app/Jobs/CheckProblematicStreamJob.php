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

class CheckProblematicStreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;
    public int $backoff = 60; // Delay in seconds before retrying a failed job execution

    protected const RELEASE_DELAY_SECONDS = 300; // 5 minutes for self-release if still offline

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $stream_provider_id
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseLogPrefix = "CheckProblematicStreamJob: [stream_provider_id: {$this->stream_provider_id}, attempt: {$this->attempts()}/{$this->tries}]";
        Log::info("{$baseLogPrefix} Starting health re-check.");

        $streamProvider = ChannelStreamProvider::with('channel', 'channel.currentStreamProvider')->find($this->stream_provider_id);

        if (!$streamProvider) {
            Log::error("{$baseLogPrefix} ChannelStreamProvider not found. Job aborting.");
            return;
        }

        $channel = $streamProvider->channel;
        // Construct a detailed name for logging early, check for null channel first
        $channelNameForLog = "channel " . ($channel ? $channel->id . " ('" . strip_tags($channel->title_custom ?? $channel->title) . "')" : "UNKNOWN_CHANNEL");
        $providerLogName = "provider {$streamProvider->id} ('{$streamProvider->provider_name}', URL: {$streamProvider->stream_url}) for {$channelNameForLog}";
        $logPrefix = "CheckProblematicStreamJob: [{$providerLogName}, attempt: {$this->attempts()}]";


        if (!$channel) {
            Log::error("{$logPrefix} Channel not found for this provider. Marking provider as 'offline'.");
            $streamProvider->status = 'offline';
            $streamProvider->last_checked_at = now();
            $streamProvider->save();
            Log::info("{$logPrefix} Job finished due to missing channel link.");
            return;
        }

        Log::debug("{$logPrefix} Processing health re-check. Current provider status: {$streamProvider->status}");

        $streamProvider->last_checked_at = now();
        $newStatus = 'offline';
        $exceptionMessage = null;

        try {
            Log::debug("{$logPrefix} Performing HTTP HEAD request to {$streamProvider->stream_url}.");
            $response = Http::timeout(5)->head($streamProvider->stream_url);

            if ($response->successful()) {
                $newStatus = 'online';
                Log::info("{$logPrefix} HEAD request successful. Provider determined to be 'online'.");
            } else {
                Log::warning("{$logPrefix} HEAD request failed. HTTP Status: {$response->status()}. Provider determined to be 'offline'.");
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

        if ($newStatus === 'online') {
            Log::info("{$logPrefix} Provider has recovered and is now 'online'.");

            if ($channel->current_stream_provider_id !== $streamProvider->id) {
                $currentActiveProvider = $channel->currentStreamProvider;
                if ($currentActiveProvider && $streamProvider->priority < $currentActiveProvider->priority) {
                    Log::info("{$logPrefix} Recovered provider (priority {$streamProvider->priority}) has higher priority than current active provider {$currentActiveProvider->id} ('{$currentActiveProvider->provider_name}', priority {$currentActiveProvider->priority}). Potential switch-back candidate for {$channelNameForLog}.");
                    // Example: InitiateSwitchBackJob::dispatch($channel->id, $streamProvider->id)->onQueue('stream-operations');
                } elseif (!$currentActiveProvider && $channel->stream_status === 'failed') {
                     Log::info("{$logPrefix} Recovered provider is online. {$channelNameForLog} was 'failed' and has no active provider. This provider is a candidate for activation. An InitiateFailoverJob (without failed_provider_id) could be dispatched or next client request might trigger startStream.");
                     // InitiateFailoverJob::dispatch($channel->id, null)->onQueue('stream-operations');
                } else if (!$currentActiveProvider) {
                    Log::info("{$logPrefix} Recovered provider is online. {$channelNameForLog} has no current_stream_provider_id set. Current channel status: {$channel->stream_status}.");
                } else {
                     Log::info("{$logPrefix} Recovered provider is online, but current active provider {$currentActiveProvider->id} for {$channelNameForLog} has same or higher priority, or channel is not in a failed state.");
                }
            } else {
                 if ($channel->stream_status !== 'playing') {
                     $channel->stream_status = 'playing';
                     $channel->save();
                     Log::info("{$logPrefix} Recovered provider is the active provider for {$channelNameForLog}. Channel status updated to 'playing'.");
                 } else {
                    Log::info("{$logPrefix} Recovered provider is already the active provider for {$channelNameForLog} and channel status is 'playing'. No change needed to channel status.");
                 }
            }
        } elseif (in_array($newStatus, ['offline', 'buffering'])) {
            $releaseDelay = self::RELEASE_DELAY_SECONDS * $this->attempts(); // Simple exponential backoff based on attempts
            Log::info("{$logPrefix} Provider is still '{$newStatus}'. Re-scheduling job with delay of {$releaseDelay} seconds if attempts remaining ({$this->attempts()}/{$this->tries}).");
            if ($this->attempts() < $this->tries) {
                $this->release($releaseDelay);
            } else {
                Log::warning("{$logPrefix} Provider is still '{$newStatus}' after max attempts ({$this->tries}). Job will not be rescheduled further by release().");
            }
        }

        Log::info("{$logPrefix} Finished processing. Final provider status: {$newStatus}.");
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $providerInfoForLog = "stream_provider_id: {$this->stream_provider_id}";
        try {
            $streamProvider = ChannelStreamProvider::with('channel')->find($this->stream_provider_id);
            if ($streamProvider) {
                $providerNamePart = "'{$streamProvider->provider_name}'";
                $channelNamePart = $streamProvider->channel ? "'" . strip_tags($streamProvider->channel->title_custom ?? $streamProvider->channel->title) . "'" : "unknown channel";
                $providerInfoForLog = "provider {$streamProvider->id} ({$providerNamePart}, URL: {$streamProvider->stream_url}) for channel " . ($streamProvider->channel_id ?? 'N/A') . " ({$channelNamePart})";
            }
        } catch (Throwable $e) {
            // Ignore error during logging enhancement in failed method
        }

        Log::critical("CheckProblematicStreamJob: CRITICAL JOB FAILURE for {$providerInfoForLog}. Error: {$exception->getMessage()}", [
            'stream_provider_id' => $this->stream_provider_id,
            'attempt' => $this->attempts(),
            'exception_message' => $exception->getMessage(),
            'exception_trace' => $exception->getTraceAsString(),
        ]);
    }
}
