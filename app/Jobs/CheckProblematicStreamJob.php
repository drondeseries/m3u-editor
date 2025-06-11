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

    public int $tries = 5; // Allow more attempts for problematic streams
    public int $timeout = 60; // Shorter timeout as it's a re-check
    public int $backoff = 60; // Delay in seconds before retrying

    protected const RELEASE_DELAY_SECONDS = 300; // 5 minutes

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
        Log::info("CheckProblematicStreamJob: Starting for stream_provider_id: {$this->stream_provider_id}. Attempt: {$this->attempts()}/{$this->tries}");

        $streamProvider = ChannelStreamProvider::with('channel')->find($this->stream_provider_id);

        if (!$streamProvider) {
            Log::error("CheckProblematicStreamJob: ChannelStreamProvider with id {$this->stream_provider_id} not found. Job aborting.");
            return;
        }

        $channel = $streamProvider->channel;
        if (!$channel) {
            // This case should ideally not happen if DB integrity is maintained.
            Log::error("CheckProblematicStreamJob: Channel not found for stream provider {$this->stream_provider_id} (URL: {$streamProvider->stream_url}). Marking provider as 'offline'.");
            $streamProvider->status = 'offline';
            $streamProvider->last_checked_at = now();
            $streamProvider->save();
            return;
        }

        $providerLogName = "provider {$streamProvider->id} ('{$streamProvider->provider_name}', URL: {$streamProvider->stream_url}) for channel {$channel->id} ('" . strip_tags($channel->title_custom ?? $channel->title) . "')";
        Log::debug("CheckProblematicStreamJob: Processing {$providerLogName}.");

        $streamProvider->last_checked_at = now();
        $newStatus = 'offline';
        $exceptionMessage = null;

        try {
            Log::debug("CheckProblematicStreamJob: [{$providerLogName}] Performing HTTP HEAD request.");
            $response = Http::timeout(5)->head($streamProvider->stream_url);

            if ($response->successful()) {
                $newStatus = 'online';
                Log::info("CheckProblematicStreamJob: [{$providerLogName}] HEAD request successful. Status set to 'online'.");
            } else {
                Log::warning("CheckProblematicStreamJob: [{$providerLogName}] HEAD request failed. HTTP Status: {$response->status()}. Status set to 'offline'.");
                $newStatus = 'offline';
            }
        } catch (Throwable $e) {
            $exceptionMessage = $e->getMessage();
            Log::error("CheckProblematicStreamJob: [{$providerLogName}] Exception during health check: {$exceptionMessage}", ['exception' => $e]);
            $newStatus = 'offline';
        }

        $oldStatus = $streamProvider->status;
        if ($oldStatus !== $newStatus || $exceptionMessage) {
            Log::info("CheckProblematicStreamJob: [{$providerLogName}] Status changing from '{$oldStatus}' to '{$newStatus}'.", $exceptionMessage ? ['error' => $exceptionMessage] : []);
        }
        $streamProvider->status = $newStatus;
        $streamProvider->save();

        if ($newStatus === 'online') {
            Log::info("CheckProblematicStreamJob: {$providerLogName} has recovered and is now 'online'.");

            if ($channel->current_stream_provider_id !== $streamProvider->id) {
                $currentActiveProvider = $channel->currentStreamProvider; // Eager loaded via with('channel.currentStreamProvider') might be better if needed often
                if ($currentActiveProvider && $streamProvider->priority < $currentActiveProvider->priority) { // Lower number means higher priority
                    Log::info("CheckProblematicStreamJob: Recovered {$providerLogName} (priority {$streamProvider->priority}) has higher priority than current active provider {$currentActiveProvider->id} ('{$currentActiveProvider->provider_name}', priority {$currentActiveProvider->priority}) for channel {$channel->id}. Potential switch-back candidate.");
                    // Future: Could dispatch a job like PossibleSwitchBackToPrimaryJob::dispatch($channel->id, $streamProvider->id);
                } elseif (!$currentActiveProvider && $channel->stream_status === 'failed') {
                     Log::info("CheckProblematicStreamJob: Recovered {$providerLogName} is online. Channel {$channel->id} was 'failed' and has no active provider. This provider is a candidate for activation. An InitiateFailoverJob might be needed or next client request could trigger startStream.");
                     // If channel is 'failed', we might want to proactively try to make it play again.
                     // InitiateFailoverJob::dispatch($channel->id)->onQueue('stream-operations'); // General failover check for the channel
                } else if (!$currentActiveProvider) {
                    Log::info("CheckProblematicStreamJob: Recovered {$providerLogName} is online. Channel {$channel->id} has no current_stream_provider_id set. Current channel status: {$channel->stream_status}.");
                }
            } else {
                 // Stream is online and is the current provider, ensure channel status reflects this
                 if ($channel->stream_status !== 'playing') {
                     $channel->stream_status = 'playing';
                     $channel->save();
                     Log::info("CheckProblematicStreamJob: Recovered {$providerLogName} is the active provider for channel {$channel->id}. Channel status updated to 'playing'.");
                 } else {
                    Log::info("CheckProblematicStreamJob: Recovered {$providerLogName} is already the active provider for channel {$channel->id} and channel status is 'playing'. No change needed.");
                 }
            }
        } elseif (in_array($newStatus, ['offline', 'buffering'])) {
            Log::info("CheckProblematicStreamJob: {$providerLogName} is still '{$newStatus}'. Re-scheduling job with delay of " . self::RELEASE_DELAY_SECONDS . " seconds. Attempt {$this->attempts()}/{$this->tries}.");
            $this->release(self::RELEASE_DELAY_SECONDS);
        }

        Log::info("CheckProblematicStreamJob: Finished for stream_provider_id: {$this->stream_provider_id}. Final provider status: {$newStatus}.");
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $logContext = [
            'stream_provider_id' => $this->stream_provider_id,
            'exception_message' => $exception->getMessage(),
            'exception_trace' => $exception->getTraceAsString(),
        ];
        Log::error("CheckProblematicStreamJob: CRITICAL JOB FAILURE for stream_provider_id: {$this->stream_provider_id}. Error: {$exception->getMessage()}", $logContext);

        // Optional: Update provider status to 'offline' or a specific error status on final failure.
        // $streamProvider = ChannelStreamProvider::find($this->stream_provider_id);
        // if ($streamProvider) {
        //     $streamProvider->status = 'error_checking'; // Example of a specific error status
        //     $streamProvider->last_checked_at = now();
        //     $streamProvider->save();
        //     Log::warning("CheckProblematicStreamJob: Marked provider {$this->stream_provider_id} as 'error_checking' due to job failure after all retries.");
        // }
    }
}
