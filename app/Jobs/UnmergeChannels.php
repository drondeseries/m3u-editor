<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Filament\Notifications\Notification;

class UnmergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public ?int $playlistId = null)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $query = Channel::query()->where('user_id', $this->userId);

        if ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId);
        }

        $channelIds = $query->pluck('id');

        // Delete all failover records where the selected channels are either the master or the failover
        ChannelFailover::whereIn('channel_id', $channelIds)
            ->orWhereIn('channel_failover_id', $channelIds)
            ->delete();

        $this->sendCompletionNotification();
    }

    protected function sendCompletionNotification()
    {
        $user = User::find($this->userId);
        Notification::make()
            ->title('Unmerge complete')
            ->body('All channels have been unmerged successfully.')
            ->success()
            ->sendToDatabase($user);
    }
}
