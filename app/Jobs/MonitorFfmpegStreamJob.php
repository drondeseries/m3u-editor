<?php

namespace App\Jobs;

use App\Services\HlsStreamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config; // Added for accessing config values

class MonitorFfmpegStreamJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $modelType;
    public int|string $channelId;
    public int $processPid;
    public string $streamUrlBeingMonitored;
    public string $stderrLogPath;

    /**
     * Create a new job instance.
     *
     * @param string $modelType 'channel' or 'episode'
     * @param int|string $channelId
     * @param int $processPid
     * @param string $streamUrlBeingMonitored
     * @param string $stderrLogPath
     */
    public function __construct(
        string $modelType,
        int|string $channelId,
        int $processPid,
        string $streamUrlBeingMonitored,
        string $stderrLogPath
    ) {
        $this->modelType = $modelType;
        $this->channelId = $channelId;
        $this->processPid = $processPid;
        $this->streamUrlBeingMonitored = $streamUrlBeingMonitored;
        $this->stderrLogPath = $stderrLogPath;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob started for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}, Log: {$this->stderrLogPath}");

        if (!config('proxy.ffmpeg_live_failover_enabled', false)) {
            Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob: Live failover is disabled globally. Job for {$this->modelType} ID {$this->channelId}, PID {$this->processPid} will not run monitoring logic.");
            $this->cleanupLogFile("Live failover disabled globally.");
            return;
        }

        if (!File::exists($this->stderrLogPath)) {
            Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob: stderr log file {$this->stderrLogPath} does not exist for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}. Aborting job.");
            // No log file to clean, just exit.
            return;
        }

        $errorPatterns = Config::get('proxy.ffmpeg_live_failover_error_patterns', [
            // Default patterns - these should be made more specific and robust
            "failed to resolve hostname",
            "Connection refused",
            "Connection timed out",
            "403 Forbidden",
            "404 Not Found",
            "500 Internal Server Error",
            "503 Service Unavailable",
            "509 Bandwidth Limit Exceeded",
            "Input/output error",
            "No such file or directory", // Could indicate segment issues if path is wrong
            "Conversion failed!", // A generic but critical ffmpeg error
            "Unable to open resource",
            "Server error: Failed to reload playlist",
            "Too many packets buffered for output stream", // Can indicate network problems downstream
            "Error number -110", // Connection timed out (from avformat)
            "Error number -104", // Connection reset by peer
            "Error number -5",   // Input/output error
        ]);
        $errorThresholdCount = Config::get('proxy.ffmpeg_live_failover_error_threshold_count', 3);
        $errorThresholdSeconds = Config::get('proxy.ffmpeg_live_failover_error_threshold_seconds', 30);
        $monitorInterval = Config::get('proxy.ffmpeg_monitor_interval_seconds', 5);

        $errorTimestamps = []; // Stores timestamps of detected errors

        try {
            $fileHandle = fopen($this->stderrLogPath, 'r');
            if (!$fileHandle) {
                Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob: Could not open stderr log file {$this->stderrLogPath} for reading.");
                $this->cleanupLogFile("Could not open log file for reading.");
                return;
            }
            // Move to the end of the file initially to only read new lines
            fseek($fileHandle, 0, SEEK_END);

            while (true) {
                // 1. Check if the stream is still supposed to be running with this PID
                if (!$hlsStreamService->isRunning($this->modelType, $this->channelId) || $hlsStreamService->getPid($this->modelType, $this->channelId) != $this->processPid) {
                    Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob: Stream {$this->modelType} ID {$this->channelId} is no longer running with PID {$this->processPid} or has been stopped. Exiting job.");
                    $this->cleanupLogFile("Stream stopped or PID changed.");
                    break;
                }

                // 2. Read new lines from the log file
                $newLines = [];
                while (($line = fgets($fileHandle)) !== false) {
                    $newLines[] = trim($line);
                }
                clearstatcache(true, $this->stderrLogPath); // Clear file status cache for accurate size checks by fgets/feof

                foreach ($newLines as $line) {
                    if (empty($line)) continue;
                    Log::channel('ffmpeg_stderr_monitor')->debug("[{$this->modelType} ID {$this->channelId} PID {$this->processPid}] FFmpeg stderr: " . $line); // Log to a potentially separate channel

                    foreach ($errorPatterns as $pattern) {
                        if (stripos($line, $pattern) !== false) {
                            Log::channel('ffmpeg')->warning("MonitorFfmpegStreamJob: Detected error pattern '{$pattern}' for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}. Line: {$line}");
                            $errorTimestamps[] = time();
                            break; // Count this line once even if it matches multiple patterns
                        }
                    }
                }

                // 3. Prune old errors from the tracking array
                $currentTime = time();
                $errorTimestamps = array_filter($errorTimestamps, function ($timestamp) use ($currentTime, $errorThresholdSeconds) {
                    return ($currentTime - $timestamp) <= $errorThresholdSeconds;
                });

                // 4. Check if threshold is breached
                if (count($errorTimestamps) >= $errorThresholdCount) {
                    Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob: Error threshold breached for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}. Count: " . count($errorTimestamps) . " within {$errorThresholdSeconds}s. Triggering failover.");

                    // Ensure HlsStreamService is resolved from container for each call if job is long-running daemon
                    $resolvedHlsStreamService = app(HlsStreamService::class);
                    $resolvedHlsStreamService->triggerLiveFailover(
                        $this->modelType,
                        $this->channelId,
                        $this->streamUrlBeingMonitored,
                        $this->processPid
                    );
                    $this->cleanupLogFile("Failover triggered.");
                    break; // Exit job after triggering failover
                }

                // 5. Wait for the next interval
                sleep($monitorInterval);
            }
        } catch (\Throwable $e) {
            Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob: Exception during monitoring for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}. Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            $this->cleanupLogFile("Exception during monitoring.");
            throw $e; // Re-throw to let Laravel handle job failure
        } finally {
            if (isset($fileHandle) && is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            // Final check for cleanup, in case loop was exited without explicit cleanup call
            $this->cleanupLogFile("Job finalization.");
        }
        Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob finished monitoring for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}.");
    }

    /**
     * Get the unique ID for the job.
     * This makes the job unique for the combination of modelType and channelId.
     * Laravel will not dispatch a new job if one with the same unique ID already exists on the queue
     * and has not finished processing.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->modelType . '-' . $this->channelId;
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @return int
     */
    public function uniqueFor(): int
    {
        // Allow the job to run for a considerable time, e.g., 24 hours.
        // The actual job execution time is controlled by the while loop and stream status.
        return config('proxy.ffmpeg_monitor_job_unique_lock_timeout', 86400); // 24 hours
    }


    private function cleanupLogFile(string $reason): void
    {
        // Check if the file still exists before attempting to delete
        // It might have been deleted by HlsStreamService::stopStream or another part of the process
        if (File::exists($this->stderrLogPath)) {
            try {
                File::delete($this->stderrLogPath);
                Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob: Cleaned up stderr log file {$this->stderrLogPath} for {$this->modelType} ID {$this->channelId}. Reason: {$reason}");
            } catch (\Exception $e) {
                Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob: Error deleting stderr log file {$this->stderrLogPath} for {$this->modelType} ID {$this->channelId}. Error: " . $e->getMessage());
            }
        } else {
            Log::channel('ffmpeg')->info("MonitorFfmpegStreamJob: Stderr log file {$this->stderrLogPath} already deleted for {$this->modelType} ID {$this->channelId}. Reason for cleanup attempt: {$reason}");
        }
    }

    /**
     * The job failed to process.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error("MonitorFfmpegStreamJob FAILED for {$this->modelType} ID {$this->channelId}, PID {$this->processPid}. Error: " . $exception->getMessage() . " at " . $exception->getFile() . ":" . $exception->getLine());
        $this->cleanupLogFile("Job failed with exception.");
    }
}
