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
use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
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
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob started.");

        try {
            $problematicUrlsJson = Redis::smembers("hls:problematic_urls");

            if (empty($problematicUrlsJson)) {
                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: No problematic URLs found in Redis set.");
                return;
            }

            Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Found " . count($problematicUrlsJson) . " problematic URLs to check.");

            foreach ($problematicUrlsJson as $jsonStringMember) {
                $entry = json_decode($jsonStringMember, true);
                if (!$entry || !isset($entry['url'], $entry['channel_id'], $entry['type'])) {
                    Log::channel('scheduler_jobs')->warning("CheckProblematicStreamsJob: Invalid entry in problematic_urls set: " . $jsonStringMember);
                    Redis::srem("hls:problematic_urls", $jsonStringMember); // Remove invalid entry
                    continue;
                }

                $urlToCheck = $entry['url'];
                $channelId = $entry['channel_id'];
                $streamType = $entry['type'];
                $userAgent = $entry['user_agent'] ?? null; // User agent might not always be stored
                $failedAt = $entry['failed_at'] ?? null;

                // Optional: Add a delay before re-checking a URL that recently failed
                $recheckDelayMinutes = Config::get('failover.problematic_url_recheck_delay_minutes', 5);
                if ($failedAt && now()->subMinutes($recheckDelayMinutes)->timestamp < $failedAt) {
                    Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Skipping URL {$urlToCheck} for {$streamType} ID {$channelId} as it failed recently (within last {$recheckDelayMinutes} mins).");
                    continue;
                }


                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Checking URL {$urlToCheck} for {$streamType} ID {$channelId}.");

                // Perform health check (custom headers usually null for this context unless stored with problematic URL entry)
                $healthCheckResult = $hlsStreamService->performHealthCheck($urlToCheck, null, $userAgent);

                if ($healthCheckResult['status'] === 'ok') {
                    Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: URL {$urlToCheck} for {$streamType} ID {$channelId} has recovered.");
                    Redis::srem("hls:problematic_urls", $jsonStringMember);
                    Redis::del("hls:url_failures:" . md5($urlToCheck)); // Clear specific failure counters
                    Redis::del("hls:url_stalls:" . md5($urlToCheck));   // Clear specific stall counters


                    // Optional Switch-Back Logic
                    if (Config::get('failover.auto_switch_back_on_recovery', false)) {
                        $model = null;
                        if ($streamType === 'channel') {
                            $model = Channel::find($channelId);
                        } elseif ($streamType === 'episode') {
                            // Episodes might not have the same primary/failover structure for "better" URL comparison
                            // For now, switch-back for episodes might be simpler or not implemented.
                            // $model = Episode::find($channelId);
                        }

                        if ($model instanceof Channel) { // Only proceed if it's a Channel and found
                            $currentActiveUrl = Redis::get("hls:active_url:{$streamType}:{$channelId}");
                            $primaryUrlOfChannel = $model->url_custom ?? $model->url;

                            // Is the recovered URL "better" than the current active one?
                            // Definition of "better":
                            // 1. Recovered URL is the primary URL of the channel.
                            // 2. OR Current active URL is a failover, and recovered URL is an earlier failover (not implemented here, needs priority logic)
                            //    OR Current active URL is a failover, and recovered URL is primary.
                            $isRecoveredUrlPrimary = ($urlToCheck === $primaryUrlOfChannel);
                            $isCurrentActiveUrlNotPrimary = ($currentActiveUrl && $currentActiveUrl !== $primaryUrlOfChannel);

                            if ($isRecoveredUrlPrimary && $currentActiveUrl !== $urlToCheck) { // If recovered is primary and not already active
                                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Recovered URL {$urlToCheck} is primary for {$streamType} ID {$channelId}. Current active is {$currentActiveUrl}. Attempting switch-back.");
                                HandleStreamFailoverJob::dispatch($channelId, $currentActiveUrl, $streamType, null);
                            } elseif ($isCurrentActiveUrlNotPrimary && $isRecoveredUrlPrimary && $currentActiveUrl !== $urlToCheck) {
                                // This case is redundant if the above covers it, but explicit for clarity:
                                // If current is failover and recovered is primary
                                Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: Recovered URL {$urlToCheck} (Primary) is better than current active failover URL {$currentActiveUrl} for {$streamType} ID {$channelId}. Attempting switch-back.");
                                HandleStreamFailoverJob::dispatch($channelId, $currentActiveUrl, $streamType, null);
                            }
                        }
                    }
                } else {
                    // Still failing, update failed_at timestamp if you want to implement retry delays based on last check
                    $updatedEntry = $entry;
                    $updatedEntry['failed_at'] = now()->timestamp; // Update last attempt time
                    $updatedEntry['last_check_status'] = $healthCheckResult['status'];
                    $updatedEntry['last_check_message'] = $healthCheckResult['message'] ?? 'N/A';
                    Redis::srem("hls:problematic_urls", $jsonStringMember);
                    Redis::sadd("hls:problematic_urls", json_encode($updatedEntry));
                    Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob: URL {$urlToCheck} for {$streamType} ID {$channelId} still failing. Status: {$healthCheckResult['status']}. Updated last check time.");
                }
            }
            Log::channel('scheduler_jobs')->info("CheckProblematicStreamsJob finished.");

        } catch (Throwable $e) {
            Log::channel('scheduler_jobs')->error("CheckProblematicStreamsJob: Exception: {$e->getMessage()} \nStack: {$e->getTraceAsString()}");
        }
    }
}
