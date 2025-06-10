<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckProblematicStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // This job typically doesn't need specific parameters for its primary function
        // as it will likely fetch all problematic streams from the database.
    }

    /**
     * Execute the job.
     */
use App\Models\ChannelStreamSource;
use App\Services\HlsStreamService;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckProblematicStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // This job typically doesn't need specific parameters for its primary function
        // as it will likely fetch all problematic streams from the database.
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob started.");

        try {
            $problematicSources = ChannelStreamSource::where('is_enabled', true)
                ->whereIn('status', ['problematic', 'down'])
                ->with('channel.playlist') // Eager load channel and its playlist for user_agent and other details
                ->get();

            if ($problematicSources->isEmpty()) {
                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: No problematic or down enabled stream sources found.");
                return;
            }

            Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Found {$problematicSources->count()} problematic/down enabled sources to check.");
            $ffprobeTimeout = config('proxy.ffmpeg_ffprobe_timeout', 5); // Use a standard timeout for these checks

            foreach ($problematicSources as $source) {
                if (!$source->channel || !$source->channel->playlist) {
                    Log::channel('scheduler_jobs')->warning("CheckProblematicStreamsJob: Skipping source ID {$source->id} due to missing channel or playlist relationship.");
                    continue;
                }

                $channelTitle = $source->channel->title_custom ?? $source->channel->title ?? "Channel ID {$source->channel_id}";
                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Checking source ID {$source->id} (URL: {$source->stream_url}) for Channel '{$channelTitle}'. Current status: {$source->status}.");

                try {
                    // Perform a pre-check. We use 'channel' as type, assuming these sources belong to channels.
                    // The model ID for runPreCheck context is the channel's ID.
                    $hlsStreamService->runPreCheck(
                        'channel',
                        $source->channel_id,
                        $source->stream_url,
                        $source->channel->playlist->user_agent ?? null,
                        $source->provider_name ?? "Source ID {$source->id}",
                        $ffprobeTimeout,
                        $source->custom_headers
                    );

                    // If pre-check is successful
                    $oldStatus = $source->status;
                    $source->status = 'active';
                    $source->consecutive_failures = 0;
                    $source->last_checked_at = now();
                    // $source->last_failed_at = null; // Optionally clear last_failed_at
                    $source->save();
                    Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Source ID {$source->id} for Channel '{$channelTitle}' recovered. Status changed from '{$oldStatus}' to 'active'.");

                    // Optional Switch-Back Logic
                    if (config('failover.auto_switch_back_on_recovery', false)) {
                        $activeSourceIdRedisKey = "hls:active_source:channel:{$source->channel_id}";
                        $currentActiveSourceId = Redis::get($activeSourceIdRedisKey);

                        if ($currentActiveSourceId && (int)$currentActiveSourceId !== $source->id) {
                            $currentActiveSource = ChannelStreamSource::find((int)$currentActiveSourceId);
                            if ($currentActiveSource && $source->priority < $currentActiveSource->priority) {
                                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Recovered source ID {$source->id} has higher priority than active source ID {$currentActiveSourceId} for Channel '{$channelTitle}'. Attempting switch-back.");
                                // Dispatch HandleStreamFailoverJob, passing the current active source as the "failed" one to trigger a switch.
                                HandleStreamFailoverJob::dispatch($source->channel_id, (int)$currentActiveSourceId);
                            }
                        } elseif (!$currentActiveSourceId && $source->channel->enabled) { // If no stream is active for this channel, and channel is enabled.
                             Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: No active stream for channel {$channelTitle} and recovered source ID {$source->id} is available. Attempting to start it.");
                             // This effectively asks to start with this source, potentially.
                             // The HandleStreamFailoverJob with null failedStreamSourceId will try to pick the best available (which should be this one).
                             HandleStreamFailoverJob::dispatch($source->channel_id, null);
                        }
                    }

                } catch (Throwable $e) {
                    // Pre-check failed
                    $source->last_checked_at = now();
                    // We might not increment consecutive_failures here as MonitorActiveStreamJob handles active streams.
                    // If it's 'down', it remains 'down'. If 'problematic', it remains 'problematic'.
                    // The main purpose here is to see if it has recovered.
                    $source->saveQuietly(); // Just update last_checked_at
                    Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Source ID {$source->id} for Channel '{$channelTitle}' still failing pre-check. Reason: {$e->getMessage()}");
                }
            }
            Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob finished.");

        } catch (Throwable $e) {
            Log::channel('scheduler_jobs')->error("CheckProblematicStreamsJob: Exception: {$e->getMessage()} \nStack: {$e->getTraceAsString()}");
        }
    }
}
