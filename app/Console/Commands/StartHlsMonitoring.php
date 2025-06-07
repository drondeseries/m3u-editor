<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\MonitorHlsStreamsJob;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;

class StartHlsMonitoring extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hls:start-monitoring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches the initial HLS monitoring job to the queue if live failover is enabled.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(GeneralSettings $settings)
    {
        if ($settings->live_failover_enabled) {
            MonitorHlsStreamsJob::dispatch();
            $this->info('Initial HLS Monitoring Job dispatched. Ensure your queue worker is running.');
            Log::channel('ffmpeg')->info('Initial HLS Monitoring Job dispatched via hls:start-monitoring command.');
        } else {
            $this->info('Live HLS monitoring is disabled in settings. Job not dispatched.');
            Log::channel('ffmpeg')->info('Live HLS monitoring disabled, hls:start-monitoring command did not dispatch job.');
        }
        return Command::SUCCESS;
    }
}
