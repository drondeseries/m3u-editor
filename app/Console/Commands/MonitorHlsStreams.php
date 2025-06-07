<?php

namespace App\Console\Commands;

use App\Services\HlsStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;

class MonitorHlsStreams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hls:monitor-streams';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor active HLS streams and log any streams that appear to have crashed.';

    /**
     * Execute the console command.
     *
     * @param HlsStreamService $hlsStreamService
     * @param GeneralSettings $settings
     * @return int
     */
    public function handle(HlsStreamService $hlsStreamService, GeneralSettings $settings): int
    {
        if (!$settings->live_failover_enabled) {
            $this->info('Live failover is disabled in settings. Exiting.');
            Log::channel('ffmpeg')->info('HLS Monitor: Live failover is disabled. Exiting.');
            return Command::SUCCESS;
        }

        $this->info('Starting HLS stream monitoring (Live failover enabled)...');
        Log::channel('ffmpeg')->info('HLS Monitor: Starting stream check (Live failover enabled).');

        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        $activeEpisodeIds = Redis::smembers('hls:active_episode_ids');

        // Store HlsStreamService in a property to make it accessible in processStreamIds
        $this->hlsStreamService = $hlsStreamService;

        $this->processStreamIds($activeChannelIds, 'channel');
        $this->processStreamIds($activeEpisodeIds, 'episode');

        Log::channel('ffmpeg')->info('HLS Monitor: Stream check finished.');
        $this->info('HLS stream monitoring finished.');
        return Command::SUCCESS;
    }

    // Property to hold HlsStreamService
    protected HlsStreamService $hlsStreamService;

    /**
     * Process a list of stream IDs for a given type.
     *
     * @param array $streamIds
     * @param string $type ('channel' or 'episode')
     */
    protected function processStreamIds(array $streamIds, string $type): void
    {
        if (empty($streamIds)) {
            $this->line("No active {$type} streams found in Redis.");
            return;
        }

        $this->line("Checking " . count($streamIds) . " active {$type} streams...");

        foreach ($streamIds as $id) {
            try {
                if (!$this->hlsStreamService->isRunning($type, $id)) {
                    $message = "HLS Monitor: Stream ID {$id} of type {$type} appears to have crashed. Attempting failover.";
                    Log::channel('ffmpeg')->warning($message);
                    $this->warn($message);

                    $model = null;
                    if ($type === 'channel') {
                        $model = Channel::find($id);
                    } elseif ($type === 'episode') {
                        $model = Episode::find($id);
                    }

                    if (!$model) {
                        $notFoundMessage = "HLS Monitor: Model for stream ID {$id} ({$type}) not found. Cannot initiate failover.";
                        Log::channel('ffmpeg')->error($notFoundMessage);
                        $this->error($notFoundMessage);
                        // Optionally remove from active set if model is gone
                        // Redis::srem("hls:active_{$type}_ids", $id);
                        continue;
                    }

                    // Ensure title is available, default if not.
                    $title = $model->title ?? ($model->name ?? "Untitled {$type} {$id}");
                    if (empty(trim($title))) { // Handle cases where title might be empty or just whitespace
                        $title = "Untitled {$type} {$id}";
                    }

                    // Log the attempt with the model's actual title
                    $attemptMessage = "HLS Monitor: Attempting to restart/failover for {$type} '{$title}' (ID: {$id}).";
                    Log::channel('ffmpeg')->info($attemptMessage);
                    $this->line($attemptMessage);

                    // Call startStream with the original model ID for path preservation
                    $failoverStream = $this->hlsStreamService->startStream($type, $model, $title, $id);

                    if ($failoverStream) {
                        $successMessage = "HLS Monitor: Successfully initiated failover for {$type} '{$title}' (ID: {$id}). New source: " . ($failoverStream->title ?? $failoverStream->name ?? "ID {$failoverStream->id}");
                        Log::channel('ffmpeg')->info($successMessage);
                        $this->info($successMessage);
                    } else {
                        $failureMessage = "HLS Monitor: Failover attempt failed for {$type} '{$title}' (ID: {$id}). All sources (including failovers) failed to start.";
                        Log::channel('ffmpeg')->error($failureMessage);
                        $this->error($failureMessage);
                        // If all failovers failed, the original ID is likely still in active_ids but no process is running for its path.
                        // Consider if it should be removed from active_ids here or if a subsequent check will handle it.
                        // For now, leave it, as isRunning will continue to report false for it.
                    }

                } else {
                    $this->line("HLS Monitor: Stream ID {$id} ({$type}) is running.");
                }
            } catch (\Throwable $e) {
                $errorMessage = "HLS Monitor: Error checking stream ID {$id} ({$type}): " . $e->getMessage();
                Log::channel('ffmpeg')->error($errorMessage);
                $this->error($errorMessage);
                // Optionally, remove the ID from Redis if it's causing persistent errors,
                // though isRunning should ideally handle non-existent PIDs gracefully.
                // Redis::srem("hls:active_{$type}_ids", $id);
            }
        }
    }
}
