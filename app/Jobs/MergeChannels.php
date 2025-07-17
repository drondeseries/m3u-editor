<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $playlistId;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channelIds, $user, $playlistId = null)
    {
        $this->user = $user;
        $this->playlistId = $playlistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;
        $channels = Channel::whereIn('id', $this->channelIds)->get();
        // Filter out channels where the stream ID is empty
        $filteredChannels = $channels->filter(function ($channel) {
            return !empty($channel->stream_id_custom) || !empty($channel->stream_id);
        });

        $groupedChannels = $filteredChannels->groupBy(function ($channel) {
            $streamId = $channel->stream_id_custom ?: $channel->stream_id;
            return strtolower($streamId);
        });

        foreach ($groupedChannels as $group) {
            if ($group->count() > 1) {
                // The master channel is the one from the preferred playlist.
                $master = $group->firstWhere('playlist_id', $this->playlistId);

                // If no channel from the preferred playlist is in the group, we can't determine a master.
                if (!$master) {
                    continue;
                }

                // The rest are failovers
                foreach ($group as $failover) {
                    if ($failover->id !== $master->id) {
                        ChannelFailover::updateOrCreate(
                            ['channel_id' => $master->id, 'channel_failover_id' => $failover->id],
                            ['user_id' => $master->user_id]
                        );
                        $processed++;
                    }
                }
            }
        }
        $this->sendCompletionNotification($processed);
    }

    protected function sendCompletionNotification($processed)
    {
        $body = $processed > 0 ? "Merged {$processed} channels successfully." : 'No channels were merged.';

        Notification::make()
            ->title('Merge complete')
            ->body($body)
            ->success()
            ->sendToDatabase($this->user);
    }
}
