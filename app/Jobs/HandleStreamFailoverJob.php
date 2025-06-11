<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
use App\Events\StreamParametersChanged;
use App\Events\StreamUnavailableEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Throwable;

class HandleStreamFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $channelId;
    public string $failedUrl;
    public string $streamType;
    public ?int $userId;

    /**
     * Create a new job instance.
     *
     * @param int $channelId
     * @param string $failedUrl
     * @param string $streamType
     * @param int|null $userId
     */
    public function __construct(int $channelId, string $failedUrl, string $streamType, ?int $userId = null)
    {
        $this->channelId = $channelId;
        $this->failedUrl = $failedUrl;
        $this->streamType = $streamType;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        Log::channel('failover')->info("HandleStreamFailoverJob started for {$this->streamType} ID: {$this->channelId}. Failed URL: {$this->failedUrl}.");

        try {
            $model = null;
            if ($this->streamType === 'channel') {
                $model = Channel::find($this->channelId);
            } elseif ($this->streamType === 'episode') {
                // Episodes might not have failover in the same way, but HlsStreamService handles it
                $model = Episode::find($this->channelId);
            }

            if (!$model) {
                Log::channel('failover')->error("HandleStreamFailoverJob: Model for {$this->streamType} ID {$this->channelId} not found. Terminating job.");
                return;
            }

            $switchSuccess = $hlsStreamService->switchToNextAvailableUrl($this->streamType, $model, $this->failedUrl);

            if ($switchSuccess) {
                Log::channel('failover')->info("HandleStreamFailoverJob: Successfully switched {$this->streamType} ID {$this->channelId} to a new URL. Broadcasting StreamParametersChanged.");
                StreamParametersChanged::dispatch($this->channelId, $this->userId, $this->streamType);
            } else {
                Log::channel('failover')->error("HandleStreamFailoverJob: Failed to switch {$this->streamType} ID {$this->channelId} to a new URL. All available URLs might have failed. Broadcasting StreamUnavailableEvent.");
                StreamUnavailableEvent::dispatch($this->channelId, $this->userId, $this->streamType, 'All stream sources are currently unavailable.');
            }

        } catch (Throwable $e) {
            Log::channel('failover')->error("HandleStreamFailoverJob: Exception for {$this->streamType} ID {$this->channelId}, Failed URL {$this->failedUrl}: {$e->getMessage()} \nStack: {$e->getTraceAsString()}");
            if ($this->attempts() < Config::get('failover.failover_job_max_attempts', 2)) {
                $this->release(Config::get('failover.failover_job_retry_delay', 30));
            } else {
                 Log::channel('failover')->critical("HandleStreamFailoverJob: Max attempts reached for {$this->streamType} ID {$this->channelId}. Error: {$e->getMessage()}");
            }
        }
    }
}
