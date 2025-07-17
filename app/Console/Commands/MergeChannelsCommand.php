<?php

namespace App\Console\Commands;

use App\Jobs\StartChannelMerge;
use Illuminate\Console\Command;

class MergeChannelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'channels:merge {userId} {--playlistId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Merge channels for a given user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('userId');
        $playlistId = $this->option('playlistId');

        StartChannelMerge::dispatch($userId, $playlistId);

        $this->info("Channel merge process started for user ID: {$userId}");
    }
}
