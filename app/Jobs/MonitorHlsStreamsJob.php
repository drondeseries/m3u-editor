<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\HlsStreamService;
use App\Settings\GeneralSettings;
use App\Models\Channel;
use App\Models\Episode;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MonitorHlsStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected HlsStreamService $hlsStreamService;
    protected GeneralSettings $settings;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        // Dependencies will be resolved by the service container when the job is handled.
    }

    /**
     * Execute the job.
     *
     * @param HlsStreamService $hlsStreamService
     * @param GeneralSettings $settings
     * @return void
     */
    public function handle(HlsStreamService $hlsStreamService, GeneralSettings $settings): void
    {
        $this->hlsStreamService = $hlsStreamService; // Store for use in helper or if needed elsewhere
        $this->settings = $settings;

        if (!$settings->live_failover_enabled) {
            Log::channel('ffmpeg')->info('HLS Monitor Job: Live failover is disabled in settings. Job will not run or re-dispatch.');
            return;
        }

        Log::channel('ffmpeg')->info('HLS Monitor Job: Starting stream check cycle (Live failover enabled).');

        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        $activeEpisodeIds = Redis::smembers('hls:active_episode_ids');

        $this->processStreamIds($activeChannelIds, 'channel');
        $this->processStreamIds($activeEpisodeIds, 'episode');

        Log::channel('ffmpeg')->info('HLS Monitor Job: Stream check cycle finished.');

        // Re-dispatch self for the next cycle
        if ($this->settings->live_failover_enabled) { // Check again in case it was disabled during job execution
            $delaySeconds = $this->settings->live_failover_monitor_interval_seconds > 0 ? $this->settings->live_failover_monitor_interval_seconds : 15;
            Log::channel('ffmpeg')->info("HLS Monitor Job: Re-dispatching self for next cycle in {$delaySeconds} seconds.");
            self::dispatch()->delay(now()->addSeconds($delaySeconds));
        } else {
            Log::channel('ffmpeg')->info('HLS Monitor Job: Live failover disabled, not re-dispatching.');
        }
    }

    /**
     * Process a list of stream IDs for a given type.
     *
     * @param array $streamIds
     * @param string $type ('channel' or 'episode')
     */
    protected function processStreamIds(array $streamIds, string $type): void
    {
        if (empty($streamIds)) {
            Log::channel('ffmpeg')->info("HLS Monitor Job: No active {$type} streams found in Redis.");
            return;
        }

        Log::channel('ffmpeg')->info("HLS Monitor Job: Checking " . count($streamIds) . " active {$type} streams...");

        foreach ($streamIds as $id) {
            try {
                if (!$this->hlsStreamService->isRunning($type, $id)) {
                    $message = "HLS Monitor Job: Stream ID {$id} of type {$type} appears to have crashed. Attempting failover.";
                    Log::channel('ffmpeg')->warning($message);

                    $model = null;
                    if ($type === 'channel') {
                        $model = Channel::find($id);
                    } elseif ($type === 'episode') {
                        $model = Episode::find($id);
                    }

                    if (!$model) {
                        $notFoundMessage = "HLS Monitor Job: Model for stream ID {$id} ({$type}) not found. Cannot initiate failover.";
                        Log::channel('ffmpeg')->error($notFoundMessage);
                        continue;
                    }

                    $title = $model->title ?? ($model->name ?? "Untitled {$type} {$id}");
                    if (empty(trim($title))) {
                        $title = "Untitled {$type} {$id}";
                    }

                    $attemptMessage = "HLS Monitor Job: Attempting to restart/failover for {$type} '{$title}' (ID: {$id}).";
                    Log::channel('ffmpeg')->info($attemptMessage);

                    $failoverStream = $this->hlsStreamService->startStream($type, $model, $title, $id);

                    if ($failoverStream) {
                        $successMessage = "HLS Monitor Job: Successfully initiated failover for {$type} '{$title}' (ID: {$id}). New source: " . ($failoverStream->title ?? $failoverStream->name ?? "ID {$failoverStream->id}");
                        Log::channel('ffmpeg')->info($successMessage);
                    } else {
                        $failureMessage = "HLS Monitor Job: Failover attempt failed for {$type} '{$title}' (ID: {$id}). All sources (including failovers) failed to start.";
                        Log::channel('ffmpeg')->error($failureMessage);
                    }

                } else {
                     Log::channel('ffmpeg')->debug("HLS Monitor Job: Stream ID {$id} ({$type}) is running.");
                }
            } catch (\Throwable $e) {
                $errorMessage = "HLS Monitor Job: Error checking stream ID {$id} ({$type}): " . $e->getMessage();
                Log::channel('ffmpeg')->error($errorMessage, ['exception' => $e]);
            }
        }
    }
}
