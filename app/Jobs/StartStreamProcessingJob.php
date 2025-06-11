<?php

namespace App\Jobs;

use App\Models\UserStreamSession;
use App\Models\ChannelStream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use App\Events\StreamSwitchEvent; // Placeholder, might need different event or data
use App\Events\StreamUnavailableEvent; // Placeholder
use Illuminate\Support\Str; // For Str::contains in isFfmpegProcessHealthy

class StartStreamProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userStreamSessionId;
    public $tries = 1; // Don't retry this job automatically if ffmpeg fails to start initially
    public $timeout = 120; // 2 minutes timeout for the job itself

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
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("StartStreamProcessingJob: Starting for UserStreamSession ID {$this->userStreamSessionId}.");

        $userStreamSession = UserStreamSession::with('channel', 'activeChannelStream')->find($this->userStreamSessionId);

        if (!$userStreamSession) {
            Log::error("StartStreamProcessingJob: UserStreamSession ID {$this->userStreamSessionId} not found.");
            return;
        }

        if (!$userStreamSession->activeChannelStream) {
            Log::error("StartStreamProcessingJob: No activeChannelStream associated with UserStreamSession ID {$this->userStreamSessionId}.");
            // This case should ideally be handled before dispatching, but good to check.
            // Potentially try to find the next available stream for the parent channel.
            $this->initiateFailover($userStreamSession, 'No active channel stream found in session.');
            return;
        }

        $channelStream = $userStreamSession->activeChannelStream;
        $channel = $userStreamSession->channel;
        $sessionId = $userStreamSession->session_id; // session_id from UserStreamSession

        // Check if a process is already running for this session and stream
        if ($userStreamSession->ffmpeg_pid && $this->isFfmpegProcessHealthy($userStreamSession->ffmpeg_pid)) {
            Log::info("StartStreamProcessingJob: FFmpeg process {$userStreamSession->ffmpeg_pid} already running and healthy for session {$sessionId}, stream {$channelStream->id}.");
            // Ensure monitoring is active
            // Assuming MonitorStreamHealthJob exists and is correctly namespaced
            if (class_exists(\App\Jobs\MonitorStreamHealthJob::class)) {
                 \App\Jobs\MonitorStreamHealthJob::dispatch($this->userStreamSessionId)->onQueue('monitoring')->delay(now()->addSeconds(10));
            } else {
                Log::warning("StartStreamProcessingJob: MonitorStreamHealthJob class not found. Cannot dispatch monitoring.");
            }
            return;
        }

        // Ensure HLS storage directory exists
        $hlsStoragePath = storage_path("app/hls/{$sessionId}_{$channelStream->id}");
        if (!File::isDirectory($hlsStoragePath)) {
            File::makeDirectory($hlsStoragePath, 0755, true);
        } else {
            // Clean up old segments/playlist from a previous attempt for this specific session stream path
            File::cleanDirectory($hlsStoragePath);
        }

        $m3u8FilePath = "{$hlsStoragePath}/master.m3u8";
        $segmentFilenamePattern = "segment_%05d.ts"; // Using %05d for more segments if needed

        // Construct FFmpeg command
        $ffmpegCommand = [
            'ffmpeg',
            '-re',
            '-fflags', '+igndts',
            '-analyzeduration', '10M',
            '-probesize', '10M',
            '-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.90 Safari/537.36',
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '10',
            '-reconnect_on_network_error', '1',
            '-reconnect_on_http_error', '4xx,5xx',
            '-i', $channelStream->stream_url,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-c:s', 'copy',
            '-f', 'hls',
            '-hls_time', '4',
            '-hls_list_size', '10',
            '-hls_flags', 'delete_segments+program_date_time+round_durations',
            '-hls_segment_filename', "{$hlsStoragePath}/{$segmentFilenamePattern}",
            $m3u8FilePath
        ];

        $ffmpegLogPath = storage_path("logs/ffmpeg/stream_{$sessionId}_{$channelStream->id}.log");
        File::ensureDirectoryExists(dirname($ffmpegLogPath));


        $process = new Process($ffmpegCommand);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        Log::info("StartStreamProcessingJob: Executing FFmpeg for session {$sessionId}, stream {$channelStream->id}: " . $process->getCommandLine());

        try {
            $process->start(null, ['FFMPEG_LOG_PATH' => $ffmpegLogPath]);

            $pid = $process->getPid();
            if (!$pid) {
                Log::error("StartStreamProcessingJob: Failed to get PID for FFmpeg process for session {$sessionId}, stream {$channelStream->id}.");
                $this->handleStreamStartupFailure($userStreamSession, $channelStream, "Failed to get FFmpeg PID.");
                return;
            }

            $userStreamSession->ffmpeg_pid = $pid;
            $userStreamSession->worker_pid = (string) getmypid();
            $userStreamSession->save();

            Log::info("StartStreamProcessingJob: FFmpeg process started with PID {$pid} for session {$sessionId}, stream {$channelStream->id}. Outputting to {$ffmpegLogPath}");

            sleep(5);

            if (!$process->isRunning() || !file_exists($m3u8FilePath)) {
                 $errorOutput = "FFmpeg process died shortly after start or M3U8 not created.";
                if (!$process->isRunning()) {
                    $errorOutput .= " Exit code: " . $process->getExitCode() . ". Output: " . $process->getOutput() . ". Error: " . $process->getErrorOutput();
                }
                Log::error("StartStreamProcessingJob: {$errorOutput} for PID {$pid}, session {$sessionId}, stream {$channelStream->id}.");
                $this->handleStreamStartupFailure($userStreamSession, $channelStream, $errorOutput);
                return;
            }

            if (class_exists(\App\Jobs\MonitorStreamHealthJob::class)) {
                \App\Jobs\MonitorStreamHealthJob::dispatch($this->userStreamSessionId)->onQueue('monitoring')->delay(now()->addSeconds(5));
                Log::info("StartStreamProcessingJob: Successfully started and dispatched Monitor job for session {$sessionId}, stream {$channelStream->id}.");
            } else {
                 Log::warning("StartStreamProcessingJob: MonitorStreamHealthJob class not found. Cannot dispatch monitoring for session {$sessionId}.");
            }


        } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
            Log::error("StartStreamProcessingJob: FFmpeg process failed to start for session {$sessionId}, stream {$channelStream->id}. Error: " . $e->getMessage());
            $this->handleStreamStartupFailure($userStreamSession, $channelStream, $e->getMessage());
        } catch (\Exception $e) {
            Log::critical("StartStreamProcessingJob: Unexpected exception for session {$sessionId}, stream {$channelStream->id}. Error: " . $e->getMessage());
            $this->handleStreamStartupFailure($userStreamSession, $channelStream, $e->getMessage());
        }
    }

    private function handleStreamStartupFailure(UserStreamSession $userStreamSession, ChannelStream $channelStream, string $errorMessage)
    {
        Log::warning("StartStreamProcessingJob: Handling startup failure for session {$userStreamSession->session_id}, stream {$channelStream->id}. Error: {$errorMessage}");
        $channelStream->status = 'problematic';
        $channelStream->last_error_at = now();
        $channelStream->consecutive_failure_count = $channelStream->consecutive_failure_count + 1;
        $channelStream->save();

        $userStreamSession->ffmpeg_pid = null;
        $userStreamSession->worker_pid = null;
        $userStreamSession->save();

        $this->initiateFailover($userStreamSession, $errorMessage);
    }

    private function initiateFailover(UserStreamSession $userStreamSession, string $reason)
    {
        Log::info("StartStreamProcessingJob: Initiating failover for session {$userStreamSession->session_id}, channel {$userStreamSession->channel_id} due to: {$reason}");

        $channel = $userStreamSession->channel;
        if (!$channel) {
            Log::error("StartStreamProcessingJob: Cannot initiate failover, channel not found for session {$userStreamSession->session_id}.");
            return;
        }

        $currentStreamId = $userStreamSession->active_channel_stream_id;

        $nextStream = $channel->channelStreams()
            ->where('id', '!=', $currentStreamId)
            ->where('status', '!=', 'disabled')
            ->where(function ($query) {
                $query->where('status', '!=', 'problematic')
                      ->orWhere('last_error_at', '<', now()->subMinutes(1));
            })
            ->orderBy('priority', 'asc')
            ->first();

        if ($nextStream) {
            Log::info("StartStreamProcessingJob: Failing over session {$userStreamSession->session_id} for channel {$channel->id} to stream_id {$nextStream->id}.");
            $userStreamSession->active_channel_stream_id = $nextStream->id;
            $userStreamSession->ffmpeg_pid = null;
            $userStreamSession->worker_pid = null;
            $userStreamSession->last_segment_at = null;
            $userStreamSession->last_segment_media_sequence = null;
            $userStreamSession->save();

            StartStreamProcessingJob::dispatch($userStreamSession->id)->onQueue('streaming');

        } else {
            Log::error("StartStreamProcessingJob: No alternative streams available for failover for session {$userStreamSession->session_id}, channel {$channel->id}.");
        }
    }

    private function isFfmpegProcessHealthy($pid): bool
    {
        if (!$pid) return false;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $result = shell_exec("tasklist /FI \"PID eq $pid\" /NH");
            return Str::contains($result, (string)$pid);
        } else {
            // For Linux/macOS, check if process exists
            // Using Process facade for consistency if available and suitable, or direct exec
            try {
                $result = \Illuminate\Support\Facades\Process::run("ps -p {$pid}");
                return $result->successful();
            } catch (\Exception $e) {
                Log::error("isFfmpegProcessHealthy: Error checking PID {$pid}: " . $e->getMessage());
                return false; // Assume not healthy if check fails
            }
        }
    }
}
