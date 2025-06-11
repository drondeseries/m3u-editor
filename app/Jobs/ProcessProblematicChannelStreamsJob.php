<?php

namespace App\Jobs;

use App\Models\ChannelStream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process; // For ffprobe
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessProblematicChannelStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const FFPROBE_TIMEOUT_SECONDS = 5; // Slightly longer timeout for recovery checks
    const RECHECK_INTERVAL_MINUTES = 5; // Only re-check streams if last_checked_at is older than this

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("ProcessProblematicChannelStreamsJob: Starting to process problematic streams.");

        $problematicStreams = ChannelStream::where('status', 'problematic')
            ->where(function ($query) {
                $query->where('last_checked_at', '<', now()->subMinutes(self::RECHECK_INTERVAL_MINUTES))
                      ->orWhereNull('last_checked_at'); // Also check those that might have never been checked after being marked problematic
            })
            ->orderBy('last_checked_at', 'asc') // Process oldest checked ones first
            ->limit(10) // Process in batches to avoid overwhelming resources/providers
            ->get();

        if ($problematicStreams->isEmpty()) {
            Log::info("ProcessProblematicChannelStreamsJob: No problematic streams due for re-check.");
            return;
        }

        Log::info("ProcessProblematicChannelStreamsJob: Found " . $problematicStreams->count() . " problematic streams to re-check.");

        foreach ($problematicStreams as $stream) {
            Log::debug("ProcessProblematicChannelStreamsJob: Checking stream ID {$stream->id} (URL: {$stream->stream_url})");

            if ($this->isValidStreamSource($stream)) {
                Log::info("ProcessProblematicChannelStreamsJob: Stream ID {$stream->id} has recovered.");
                $stream->status = 'recovered'; // Or 'active' if it's the only/highest priority - simpler to just mark 'recovered'
                $stream->consecutive_failure_count = 0;
                $stream->consecutive_stall_count = 0;
                // last_error_at remains as is, indicating the last time it actually had an error
            } else {
                Log::warning("ProcessProblematicChannelStreamsJob: Stream ID {$stream->id} is still problematic.");
                // Status remains 'problematic'
            }
            $stream->last_checked_at = now();
            $stream->save();

            // Small delay between checks to avoid hammering one provider if multiple streams are from them
            sleep(1);
        }

        Log::info("ProcessProblematicChannelStreamsJob: Finished processing batch of problematic streams.");
    }

    /**
     * Validates a stream source using ffprobe.
     * (This is similar to the one in StreamController, consider moving to a Trait or Service if DRY is desired)
     *
     * @param ChannelStream $channelStream
     * @return bool
     */
    private function isValidStreamSource(ChannelStream $channelStream): bool
    {
        $command = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_streams',
            '-show_format',
            '-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.90 Safari/537.36',
            $channelStream->stream_url
        ];

        try {
            $result = Process::timeout(self::FFPROBE_TIMEOUT_SECONDS)->run(implode(' ', $command));

            if (!$result->successful()) {
                Log::warning("isValidStreamSource (RecoveryJob): ffprobe failed for {$channelStream->stream_url}. Error: " . $result->errorOutput());
                return false;
            }

            $output = json_decode($result->output(), true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($output['streams'])) {
                Log::warning("isValidStreamSource (RecoveryJob): No streams or invalid JSON from ffprobe for {$channelStream->stream_url}.");
                return false;
            }

            foreach($output['streams'] as $stream_info) {
                if (isset($stream_info['codec_type']) && ($stream_info['codec_type'] === 'video' || $stream_info['codec_type'] === 'audio')) {
                    return true; // Found at least one A/V stream
                }
            }
            Log::warning("isValidStreamSource (RecoveryJob): No audio or video streams found by ffprobe for {$channelStream->stream_url}.");
            return false;

        } catch (ProcessTimedOutException $e) {
            Log::warning("isValidStreamSource (RecoveryJob): ffprobe timed out for {$channelStream->stream_url}.");
            return false;
        } catch (\Exception $e) {
            Log::error("isValidStreamSource (RecoveryJob): Exception during ffprobe for {$channelStream->stream_url}: " . $e->getMessage());
            return false;
        }
    }
}
