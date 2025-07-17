<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Filament\Notifications\Notification;

class StartChannelMerge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public ?int $playlistId = null)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        $query = Channel::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('stream_id');

        if ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId);
        }

        $streamIds = $query->distinct()->pluck('stream_id');

        $jobs = $streamIds->map(function ($streamId) {
            return new MergeSingleGroupJob($streamId, $this->userId, $this->playlistId);
        })->all();

        if (empty($jobs)) {
            Notification::make()
                ->title('Merge complete')
                ->body('No channels were found to merge.')
                ->success()
                ->sendToDatabase($user);

            return;
        }

        Bus::batch($jobs)
            ->then(function (Batch $batch) use ($user) {
                Notification::make()
                    ->title('Merge complete')
                    ->body('All channels have been merged successfully.')
                    ->success()
                    ->sendToDatabase($user);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                Notification::make()
                    ->title('Merge failed')
                    ->body('An error occurred while merging channels.')
                    ->danger()
                    ->sendToDatabase($user);
            })
            ->name('merge-channels-'.$this->userId)
            ->dispatch();
    }
}
