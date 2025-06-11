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

class MonitorStreamHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; // Allow up to 3 attempts for the job
    public int $timeout = 120; // Job can run for 120 seconds

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
        Log::info("MonitorStreamHealthJob started for channel_id: {$this->channel_id}, stream_provider_id: {$this->stream_provider_id}");

        $streamProvider = ChannelStreamProvider::find($this->stream_provider_id);

        if (!$streamProvider) {
            Log::error("MonitorStreamHealthJob: ChannelStreamProvider with id {$this->stream_provider_id} not found.");
            return;
        }

        if ($streamProvider->channel_id !== $this->channel_id) {
            Log::error("MonitorStreamHealthJob: Stream provider {$this->stream_provider_id} does not belong to channel {$this->channel_id}.");
            return;
        }

        $channel = Channel::find($this->channel_id);
        if (!$channel) {
            Log::error("MonitorStreamHealthJob: Channel with id {$this->channel_id} not found for stream provider {$this->stream_provider_id}.");
            $streamProvider->last_checked_at = now();
            $streamProvider->status = 'offline'; // Mark as offline if channel is missing
            $streamProvider->save();
            return;
        }

        $streamProvider->last_checked_at = now();
        $newStatus = 'offline'; // Default to offline

        try {
            // Step 1: HTTP HEAD request to the M3U8 URL
            $response = Http::timeout(5)->head($streamProvider->stream_url);

            if ($response->successful()) {
                $newStatus = 'online';

                // Step 2: Fetch a segment URL from the manifest (simplified)
                // In a real scenario, parse the M3U8 content. For now, we assume the main URL might contain segments or is a direct stream.
                // This is a very basic check. A robust solution would parse the M3U8, find segment URLs, and test one.
                $segmentResponse = Http::timeout(10)->get($streamProvider->stream_url);
                if (!$segmentResponse->successful() || empty($segmentResponse->body())) {
                     // If fetching segments (or the stream itself if not HLS/DASH) fails after initial HEAD success
                    $newStatus = 'offline';
                    Log::warning("MonitorStreamHealthJob: Stream for provider {$this->stream_provider_id} failed segment check after successful HEAD. URL: {$streamProvider->stream_url}");
                } else {
                    // Step 3: Simulate Buffering/Stall Detection (10% chance if online)
                    if (rand(1, 100) <= 10) {
                        $newStatus = 'buffering';
                        Log::info("MonitorStreamHealthJob: Simulated buffering for stream provider {$this->stream_provider_id}.");
                    }
                }
            } else {
                Log::warning("MonitorStreamHealthJob: HEAD request failed for stream provider {$this->stream_provider_id}. Status: {$response->status()}. URL: {$streamProvider->stream_url}");
                $newStatus = 'offline';
            }
        } catch (Throwable $e) {
            Log::error("MonitorStreamHealthJob: Exception during health check for stream provider {$this->stream_provider_id}. Error: {$e->getMessage()}");
            $newStatus = 'offline';
        }

        $oldStatus = $streamProvider->status;
        $streamProvider->status = $newStatus;
        $streamProvider->save();

        Log::info("MonitorStreamHealthJob: Status for stream provider {$this->stream_provider_id} updated to {$newStatus}. (Old status: {$oldStatus})");

        // Logic for handling problematic streams
        if (in_array($newStatus, ['offline', 'buffering'])) {
            if ($channel->current_stream_provider_id === $streamProvider->id) {
                Log::error("MonitorStreamHealthJob: Currently active stream provider {$streamProvider->id} for channel {$this->channel_id} is {$newStatus}. Triggering failover process.");
                InitiateFailoverJob::dispatch($this->channel_id, $this->stream_provider_id)->onQueue('stream-operations');
                // Channel status will be updated by InitiateFailoverJob or subsequent monitoring
                // For example, InitiateFailoverJob might set it to 'switching' or 'failed'
                // $channel->stream_status = 'switching';
                // $channel->save();
                Log::info("MonitorStreamHealthJob: Dispatched InitiateFailoverJob for channel {$this->channel_id} due to provider {$streamProvider->id} status: {$newStatus}.");
            }
        } elseif ($newStatus === 'online') {
            if ($oldStatus && in_array($oldStatus, ['offline', 'buffering'])) {
                 Log::info("MonitorStreamHealthJob: Stream provider {$streamProvider->id} for channel {$this->channel_id} recovered. Old status: {$oldStatus}, New status: {$newStatus}.");
            }

            if ($channel->current_stream_provider_id !== $streamProvider->id) {
                $currentProvider = $channel->currentStreamProvider;
                if ($currentProvider && $streamProvider->priority < $currentProvider->priority) { // Lower number means higher priority
                    Log::info("MonitorStreamHealthJob: Stream provider {$streamProvider->id} (priority {$streamProvider->priority}) for channel {$this->channel_id} is online and has higher priority than current active provider {$channel->current_stream_provider_id} (priority {$currentProvider->priority}).");
                    // Future: Logic to switch back to higher priority stream could be triggered here or by a separate job.
                } elseif (!$currentProvider) {
                     Log::info("MonitorStreamHealthJob: Stream provider {$streamProvider->id} for channel {$this->channel_id} is online. Channel has no current active provider. This could be a candidate for activation.");
                     // This could be a case where the channel was fully offline and this is the first provider to come back online.
                     // A separate mechanism might be needed to make it active, or InitiateFailoverJob could handle this.
                }
            } else {
                // Stream is online and is the current provider, ensure channel status reflects this
                if ($channel->stream_status !== 'playing') {
                    $channel->stream_status = 'playing';
                    $channel->save();
                    Log::info("MonitorStreamHealthJob: Channel {$this->channel_id} stream_status set to 'playing' as current provider {$streamProvider->id} is online.");
                }
            }
        }
        Log::info("MonitorStreamHealthJob finished for channel_id: {$this->channel_id}, stream_provider_id: {$this->stream_provider_id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("MonitorStreamHealthJob FAILED for channel_id: {$this->channel_id}, stream_provider_id: {$this->stream_provider_id}. Error: {$exception->getMessage()}");

        $streamProvider = ChannelStreamProvider::find($this->stream_provider_id);
        if ($streamProvider) {
            $streamProvider->status = 'offline'; // Mark as offline on job failure
            $streamProvider->last_checked_at = now();
            $streamProvider->save();
            Log::info("MonitorStreamHealthJob: Marked provider {$this->stream_provider_id} as 'offline' due to job failure.");

            $channel = Channel::find($this->channel_id);
            if ($channel && $channel->current_stream_provider_id === $streamProvider->id) {
                 Log::error("MonitorStreamHealthJob: Currently active stream provider {$streamProvider->id} for channel {$this->channel_id} failed its job. Triggering failover.");
                 InitiateFailoverJob::dispatch($this->channel_id, $this->stream_provider_id)->onQueue('stream-operations');
                 // $channel->stream_status = 'failed'; // InitiateFailoverJob should handle this
                 // $channel->save();
                 Log::info("MonitorStreamHealthJob: Dispatched InitiateFailoverJob for channel {$this->channel_id} due to job failure of current provider {$streamProvider->id}.");
            }
        }
    }
}
