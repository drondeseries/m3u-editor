<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HandleStreamFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public ?int $failedStreamSourceId;

    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param int|null $failedStreamSourceId
     */
    public function __construct(int $channelId, ?int $failedStreamSourceId = null)
    {
        $this->channelId = $channelId;
        $this->failedStreamSourceId = $failedStreamSourceId;
    }

    /**
     * Execute the job.
     */
use App\Models\Channel;
use App\Models\ChannelStreamSource;
use App\Services\HlsStreamService;
use App\Services\ProxyService; // For BAD_SOURCE_CACHE_PREFIX
use App\Events\StreamParametersChanged;
use App\Events\StreamUnavailableEvent;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HandleStreamFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public ?int $failedStreamSourceId;

    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param int|null $failedStreamSourceId
     */
    public function __construct(int $channelId, ?int $failedStreamSourceId = null)
    {
        $this->channelId = $channelId;
        $this->failedStreamSourceId = $failedStreamSourceId;
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('failover')->info("HandleStreamFailoverJob started for Channel ID: {$this->channelId}. Failed Stream Source ID: {$this->failedStreamSourceId}.");

        try {
            $channel = Channel::with('playlist')->find($this->channelId); // Eager load playlist
            if (!$channel) {
                Log::channel('failover')->error("HandleStreamFailoverJob: Channel ID {$this->channelId} not found. Terminating job.");
                return;
            }

            if (!$channel->playlist) {
                Log::channel('failover')->error("HandleStreamFailoverJob: Playlist not found for Channel ID {$this->channelId}. Terminating job.");
                return;
            }

            // Get all enabled stream sources for this channel, ordered by priority
            $streamSources = $channel->streamSources()
                                    ->where('is_enabled', true)
                                    // ->where('status', '!=', 'down') // Optionally filter out known 'down' sources early
                                    ->orderBy('priority')
                                    ->get();

            if ($streamSources->isEmpty()) {
                Log::channel('failover')->error("HandleStreamFailoverJob: No enabled stream sources found for Channel ID {$this->channelId}. Broadcasting StreamUnavailableEvent.");
                StreamUnavailableEvent::dispatch($this->channelId, null, 'channel', 'All stream sources are currently unavailable or disabled.');
                return;
            }

            $nextSourceToTry = null;
            $ffprobeTimeout = config('proxy.ffmpeg_ffprobe_timeout', 5);

            foreach ($streamSources as $source) {
                if ($source->status === 'down') {
                    Log::channel('failover')->info("HandleStreamFailoverJob: Skipping source ID {$source->id} for Channel {$this->channelId} because its status is 'down'.");
                    continue;
                }

                // If this source is the one that just failed, and we have more than one source, skip it for now.
                // Allow retrying the failed source if it's the only one available.
                if ($source->id === $this->failedStreamSourceId && $streamSources->count() > 1) {
                     // Check if enough time has passed to retry the failed source
                    $retryFailedSourceAfter = config('failover.retry_failed_source_after_minutes', 15);
                    if ($source->last_failed_at && $source->last_failed_at->addMinutes($retryFailedSourceAfter)->isFuture()) {
                        Log::channel('failover')->info("HandleStreamFailoverJob: Skipping recently failed source ID {$source->id} for Channel {$this->channelId}. Will retry after {$retryFailedSourceAfter} minutes.");
                        continue;
                    }
                }

                // Clear any temporary bad source cache for this source before pre-check
                $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $source->id . ':' . $channel->playlist->id;
                Redis::del($badSourceCacheKey);

                Log::channel('failover')->info("HandleStreamFailoverJob: Performing pre-check for source ID {$source->id} (URL: {$source->stream_url}) for Channel {$this->channelId}.");
                try {
                    // Assuming 'channel' type for HLS streams. Adjust if episodes have separate logic.
                    $hlsStreamService->runPreCheck(
                        'channel',
                        $channel->id,
                        $source->stream_url,
                        $channel->playlist->user_agent ?? null,
                        $source->provider_name ?? "Source ID {$source->id}",
                        $ffprobeTimeout,
                        $source->custom_headers
                    );
                    $nextSourceToTry = $source;
                    Log::channel('failover')->info("HandleStreamFailoverJob: Pre-check PASSED for source ID {$source->id} for Channel {$this->channelId}.");
                    break; // Found a working source
                } catch (Throwable $e) {
                    Log::channel('failover')->warning("HandleStreamFailoverJob: Pre-check FAILED for source ID {$source->id} for Channel {$this->channelId}. Reason: {$e->getMessage()}");
                    // Mark as problematic or update failure count if needed, though Monitor job primarily handles this.
                    // For now, just cache it as a bad source temporarily to avoid hammering during this job's run if other sources also fail precheck.
                    Redis::setex($badSourceCacheKey, config('failover.temp_bad_source_ttl_seconds', 60), $e->getMessage());
                    $source->increment('consecutive_failures');
                    $source->status = 'problematic';
                    $source->last_failed_at = now();
                    $source->saveQuietly(); // Save without triggering events, as monitor job handles detailed status.
                }
            }

            if ($nextSourceToTry) {
                Log::channel('failover')->info("HandleStreamFailoverJob: Attempting to switch to source ID {$nextSourceToTry->id} for Channel {$this->channelId}.");
                $switchSuccess = $hlsStreamService->switchStreamSource('channel', $channel, $nextSourceToTry, $this->failedStreamSourceId);

                if ($switchSuccess) {
                    Log::channel('failover')->info("HandleStreamFailoverJob: Successfully switched Channel {$this->channelId} to source ID {$nextSourceToTry->id}. Broadcasting StreamParametersChanged.");
                    StreamParametersChanged::dispatch($this->channelId, null, 'channel'); // Assuming 'channel' type
                } else {
                    Log::channel('failover')->critical("HandleStreamFailoverJob: Failed to switch Channel {$this->channelId} to source ID {$nextSourceToTry->id}. This might indicate a deeper issue or that the source failed immediately after pre-check.");
                    // If switchStreamSource fails, it means the source chosen after pre-check couldn't be started by ffmpeg.
                    // We might want to try the *next* available source if any, or give up.
                    // For now, we'll give up and let the next run of CheckProblematicStreamsJob or manual intervention handle it.
                    // Dispatching StreamUnavailableEvent as a precaution if this was the last resort.
                    if ($streamSources->last()->id === $nextSourceToTry->id) { // If this was the last source in the list
                         StreamUnavailableEvent::dispatch($this->channelId, null, 'channel', "Failed to switch to the last available source. Channel may be down.");
                    }
                }
            } else {
                Log::channel('failover')->error("HandleStreamFailoverJob: No suitable stream source found for Channel ID {$this->channelId} after pre-checks. Broadcasting StreamUnavailableEvent.");
                StreamUnavailableEvent::dispatch($this->channelId, null, 'channel', 'All available stream sources failed pre-checks.');
            }

        } catch (Throwable $e) {
            Log::channel('failover')->error("HandleStreamFailoverJob: Exception for Channel ID {$this->channelId}: {$e->getMessage()} \nStack: {$e->getTraceAsString()}");
            // Release job back to queue for transient issues
            if ($this->attempts() < config('failover.failover_job_max_attempts', 2)) { // Fewer retries for failover itself
                $this->release(config('failover.failover_job_retry_delay', 30));
            } else {
                 Log::channel('failover')->critical("HandleStreamFailoverJob: Max attempts reached for Channel ID {$this->channelId}. Error: {$e->getMessage()}");
            }
        }
    }
}
