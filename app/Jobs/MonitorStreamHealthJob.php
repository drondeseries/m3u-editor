<?php

namespace App\Jobs;

use App\Models\UserStreamSession;
use App\Models\ChannelStream;
use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process; // Keep this for Process::run
use App\Events\StreamSwitchEvent; // Placeholder
use App\Events\StreamUnavailableEvent; // Placeholder
use Illuminate\Contracts\Queue\ShouldBeUnique; // For ensuring only one job per session
use Illuminate\Support\Str; // For Str::contains in isFfmpegProcessHealthy


class MonitorStreamHealthJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userStreamSessionId;
    public $tries = 1; // Don't retry this job via Laravel's retry mechanism if it fails; it handles its own rescheduling or failover.
    public $timeout = 60; // Job timeout

    const MONITORING_INTERVAL_SECONDS = 10; // How often to run this check
    const MAX_CONSECUTIVE_STALLS_FOR_STREAM = 2; // Number of stall detections before marking ChannelStream problematic
    const MAX_CONSECUTIVE_FAILURES_FOR_STREAM = 3; // Number of general failures before marking ChannelStream problematic

    /**
     * Create a new job instance.
     *
     * @param int $userStreamSessionId
     */
    public function __construct(int $userStreamSessionId)
    {
        $this->userStreamSessionId = $userStreamSessionId;
    }

    /**
     * The unique ID of the job.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return (string)$this->userStreamSessionId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::debug("MonitorStreamHealthJob: Starting for UserStreamSession ID {$this->userStreamSessionId}.");

        $userStreamSession = UserStreamSession::with('channel', 'activeChannelStream')->find($this->userStreamSessionId);

        if (!$userStreamSession || !$userStreamSession->activeChannelStream || !$userStreamSession->channel) {
            Log::warning("MonitorStreamHealthJob: UserStreamSession ID {$this->userStreamSessionId} or its relations not found. Terminating monitoring.");
            return;
        }

        $channelStream = $userStreamSession->activeChannelStream;
        $channel = $userStreamSession->channel;
        $sessionId = $userStreamSession->session_id;

        // 1. Check FFmpeg Process Health
        if (!$userStreamSession->ffmpeg_pid || !$this->isFfmpegProcessHealthy($userStreamSession->ffmpeg_pid)) {
            Log::warning("MonitorStreamHealthJob: FFmpeg process (PID: {$userStreamSession->ffmpeg_pid}) for session {$sessionId}, stream {$channelStream->id} is not running.");
            $this->initiateFailover($userStreamSession, $channelStream, $channel, "FFmpeg process died.");
            return;
        }

        // 2. Check Manifest Liveness & Segment Advancement
        $hlsStoragePath = storage_path("app/hls/{$sessionId}_{$channelStream->id}");
        $m3u8FilePath = "{$hlsStoragePath}/master.m3u8";

        if (!File::exists($m3u8FilePath)) {
            Log::warning("MonitorStreamHealthJob: M3U8 file {$m3u8FilePath} not found for session {$sessionId}, stream {$channelStream->id}.");
            // This could be a startup issue or FFmpeg died just now.
            // Increment failure count for the stream, then try to failover.
            $channelStream->increment('consecutive_failure_count');
             if ($channelStream->consecutive_failure_count >= self::MAX_CONSECUTIVE_FAILURES_FOR_STREAM) {
                 $this->markStreamProblematic($channelStream, "M3U8 file missing repeatedly.");
             } else { // Save if not yet problematic, just to record failure count
                $channelStream->save();
             }
            $this->initiateFailover($userStreamSession, $channelStream, $channel, "M3U8 file missing.");
            return;
        }

        $manifestContent = File::get($m3u8FilePath);
        $currentMediaSequence = $this->parseMediaSequence($manifestContent);
        // $latestSegmentTimestamp = File::lastModified($m3u8FilePath); // Use M3U8 modification time as proxy for new segment

        if ($currentMediaSequence === null) {
            Log::warning("MonitorStreamHealthJob: Could not parse media sequence from {$m3u8FilePath}.");
            // Treat as a stall/failure
            $channelStream->increment('consecutive_stall_count');
            $channelStream->increment('consecutive_failure_count'); // Also count as a general failure
            $channelStream->last_error_at = now();
            $channelStream->save();
        } elseif ($userStreamSession->last_segment_media_sequence !== null && $currentMediaSequence == $userStreamSession->last_segment_media_sequence) {
            // Media sequence has not advanced
            // Check if last_segment_at is set and if enough time has passed to consider it a stall
            if ($userStreamSession->last_segment_at && (time() - $userStreamSession->last_segment_at->timestamp) >= (self::MONITORING_INTERVAL_SECONDS * (self::MAX_CONSECUTIVE_STALLS_FOR_STREAM -1) ) ) {
                 Log::warning("MonitorStreamHealthJob: Stream stall detected for session {$sessionId}, stream {$channelStream->id}. Media sequence {$currentMediaSequence} unchanged.");
                 $channelStream->increment('consecutive_stall_count');
                 $channelStream->last_error_at = now(); // Update last error time on stall detection
                 $channelStream->save();
            }
        } else {
            // Stream is advancing or it's the first check for media sequence
            $userStreamSession->last_segment_media_sequence = $currentMediaSequence;
            $userStreamSession->last_segment_at = now();
            $userStreamSession->save();

            if ($channelStream->consecutive_stall_count > 0) {
                $channelStream->consecutive_stall_count = 0;
                // Do not reset last_error_at here, only on full recovery
                $channelStream->save();
                 Log::info("MonitorStreamHealthJob: Stream stall count reset (sequence advanced) for session {$sessionId}, stream {$channelStream->id}.");
            }
        }

        // Reset general failure count for the stream if checks are passing now (M3U8 exists, sequence parsable)
        if ($channelStream->consecutive_failure_count > 0 && $currentMediaSequence !== null && File::exists($m3u8FilePath)) {
            Log::info("MonitorStreamHealthJob: Stream {$channelStream->id} seems to have recovered from general failures (M3U8 present, sequence parsable). Resetting failure count.");
            $channelStream->consecutive_failure_count = 0;
            // $channelStream->last_error_at = null; // Consider if this should be cleared
            $channelStream->save();
        }


        // 3. Failover Condition Check
        if ($channelStream->consecutive_stall_count >= self::MAX_CONSECUTIVE_STALLS_FOR_STREAM ||
            $channelStream->consecutive_failure_count >= self::MAX_CONSECUTIVE_FAILURES_FOR_STREAM) {
            Log::error("MonitorStreamHealthJob: Stream {$channelStream->id} for session {$sessionId} exceeded max stalls/failures. Stalls: {$channelStream->consecutive_stall_count}, Failures: {$channelStream->consecutive_failure_count}. Initiating failover.");
            $this->killFfmpegProcess($userStreamSession->ffmpeg_pid);
            $this->markStreamProblematic($channelStream, "Exceeded max stalls or failures during monitoring.");
            $this->initiateFailover($userStreamSession, $channelStream, $channel, "Stream unhealthy (stalled/failed).");
            return;
        }

        // 4. If Healthy, Reschedule Monitoring
        Log::debug("MonitorStreamHealthJob: Health check OK for session {$sessionId}, stream {$channelStream->id}. Rescheduling.");
        MonitorStreamHealthJob::dispatch($this->userStreamSessionId)->onQueue('monitoring')->delay(now()->addSeconds(self::MONITORING_INTERVAL_SECONDS));
    }

    private function isFfmpegProcessHealthy($pid): bool
    {
        if (!$pid) return false;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $result = shell_exec("tasklist /FI \"PID eq $pid\" /NH");
            return Str::contains($result, (string)$pid);
        } else {
            try {
                $result = \Illuminate\Support\Facades\Process::run("ps -p {$pid}");
                return $result->successful();
            } catch (\Exception $e) {
                Log::error("isFfmpegProcessHealthy: Error checking PID {$pid}: " . $e->getMessage());
                return false;
            }
        }
    }

    private function parseMediaSequence($manifestContent): ?int
    {
        if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $manifestContent, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function killFfmpegProcess($pid)
    {
        if ($pid && $this->isFfmpegProcessHealthy($pid)) {
            Log::info("MonitorStreamHealthJob: Attempting to kill FFmpeg process PID {$pid}.");
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                 \Illuminate\Support\Facades\Process::run("taskkill /PID {$pid} /F");
            } else {
                 \Illuminate\Support\Facades\Process::run("kill -9 {$pid}");
            }
        }
    }

    private function markStreamProblematic(ChannelStream $channelStream, string $reason)
    {
        Log::warning("MonitorStreamHealthJob: Marking stream {$channelStream->id} as problematic. Reason: {$reason}");
        $channelStream->status = 'problematic';
        $channelStream->last_error_at = now();
        $channelStream->consecutive_stall_count = 0;
        $channelStream->save();
    }

    private function initiateFailover(UserStreamSession $userStreamSession, ChannelStream $failedStream, Channel $channel, string $reason)
    {
        Log::info("MonitorStreamHealthJob: Initiating failover for session {$userStreamSession->session_id}, channel {$channel->id} from stream {$failedStream->id}. Reason: {$reason}");

        $userStreamSession->ffmpeg_pid = null;
        $userStreamSession->worker_pid = null;
        // Keep last segment info for now, might be useful for debugging, or clear it:
        // $userStreamSession->last_segment_media_sequence = null;
        // $userStreamSession->last_segment_at = null;
        $userStreamSession->save();

        $nextStream = $channel->channelStreams()
            ->where('id', '!=', $failedStream->id)
            ->where('status', '!=', 'disabled')
            ->where(function ($query) {
                $query->where('status', '!=', 'problematic')
                      ->orWhere('last_error_at', '<', now()->subMinutes(1));
            })
            ->orderBy('priority', 'asc')
            ->first();

        if ($nextStream) {
            Log::info("MonitorStreamHealthJob: Failing over session {$userStreamSession->session_id} for channel {$channel->id} to new stream_id {$nextStream->id}.");
            $userStreamSession->active_channel_stream_id = $nextStream->id;
            $userStreamSession->save(); // Save new active stream first

            // Dispatch job to start the new stream.
            StartStreamProcessingJob::dispatch($userStreamSession->id)->onQueue('streaming');

        } else {
            Log::error("MonitorStreamHealthJob: No alternative streams available for failover for session {$userStreamSession->session_id}, channel {$channel->id}.");
        }
    }
}
