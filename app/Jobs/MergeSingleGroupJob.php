<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MergeSingleGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $streamId,
        public int $userId,
        public ?int $playlistId = null
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $query = Channel::query()
            ->where('user_id', $this->userId)
            ->where('stream_id', $this->streamId);

        if ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId);
        }

        $group = $query->get();

        if ($group->count() <= 1) {
            return;
        }

        $master = $this->findMasterChannel($group);

        $failoversToUpsert = [];
        foreach ($group as $failover) {
            if ($failover->id !== $master->id) {
                $failoversToUpsert[] = [
                    'channel_id' => $master->id,
                    'channel_failover_id' => $failover->id,
                    'user_id' => $master->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($failoversToUpsert)) {
            ChannelFailover::upsert(
                $failoversToUpsert,
                ['channel_id', 'channel_failover_id'],
                ['user_id', 'updated_at']
            );
        }
    }

    private function findMasterChannel($group)
    {
        $master = null;

        if ($this->playlistId) {
            $preferredChannels = $group->where('playlist_id', $this->playlistId);
            if ($preferredChannels->isNotEmpty()) {
                $master = $preferredChannels->reduce(function ($highest, $channel) {
                    if (!$highest) {
                        return $channel;
                    }
                    $highestResolution = $this->getResolution($highest);
                    $currentResolution = $this->getResolution($channel);
                    return ($currentResolution > $highestResolution) ? $channel : $highest;
                });
            }
        }

        if (!$master) {
            $master = $group->reduce(function ($highest, $channel) {
                if (!$highest) {
                    return $channel;
                }
                $highestResolution = $this->getResolution($highest);
                $currentResolution = $this->getResolution($channel);
                return ($currentResolution > $highestResolution) ? $channel : $highest;
            });
        }

        return $master;
    }

    private function getResolution($channel)
    {
        $streamStats = $channel->stream_stats;
        foreach ($streamStats as $stream) {
            if (isset($stream['stream']['codec_type']) && $stream['stream']['codec_type'] === 'video') {
                return ($stream['stream']['width'] ?? 0) * ($stream['stream']['height'] ?? 0);
            }
        }
        return 0;
    }
}
