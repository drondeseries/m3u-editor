<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessProblematicChannelStreamsJob;
use Illuminate\Support\Facades\Log;

class ProcessProblematicStreams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'streams:process-problematic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches a job to process HLS streams marked as problematic to check for recovery.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info('Artisan command [streams:process-problematic] called. Dispatching ProcessProblematicChannelStreamsJob.');
        ProcessProblematicChannelStreamsJob::dispatch()->onQueue('default'); // Or a specific queue for utility tasks
        $this->info('ProcessProblematicChannelStreamsJob dispatched successfully.');
        return Command::SUCCESS;
    }
}
