<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Settings\GeneralSettings;

class ForceSaveGeneralSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:force-save-general';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loads GeneralSettings, ensures defaults are present for new properties, and saves them to the database.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Attempting to load and save GeneralSettings...');
        try {
            $settings = app(GeneralSettings::class);

            // Access properties to ensure they are loaded with defaults if not set
            // For newly added properties with defaults in the class, these should reflect the defaults.
            $this->info('Current live_failover_enabled: ' . ($settings->live_failover_enabled ? 'true' : 'false'));
            $this->info('Current live_failover_monitor_interval_seconds: ' . $settings->live_failover_monitor_interval_seconds);

            // Explicitly set them from their current values (which should be defaults if not in DB)
            // This marks them as "dirty" in the context of the settings object, prompting a save.
            $settings->live_failover_enabled = $settings->live_failover_enabled;
            $settings->live_failover_monitor_interval_seconds = $settings->live_failover_monitor_interval_seconds;

            $settings->save();
            $this->info('GeneralSettings saved successfully.');

            // Verify by creating a new instance and reloading
            $settings = app(GeneralSettings::class); // Re-resolve to get a fresh instance
            $this->info('After save, reloaded live_failover_enabled: ' . ($settings->live_failover_enabled ? 'true' : 'false'));
            $this->info('After save, reloaded live_failover_monitor_interval_seconds: ' . $settings->live_failover_monitor_interval_seconds);

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            // Adding more debug info
            if ($e->getPrevious()) {
                $this->error('Previous Error: ' . $e->getPrevious()->getMessage());
            }
            $this->error('Trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
