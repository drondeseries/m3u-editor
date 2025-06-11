<?php

namespace App\Console\Commands;

use App\Models\Channel;
// Assuming App\Models\ChannelStreamProvider is the correct namespace
use App\Models\ChannelStreamProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateExistingChannelStreams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:existingchannelstreams';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates existing channel URLs and old failover channels to the new channel_stream_providers table.';

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
        $this->info('Starting migration of existing channel streams...');
        Log::info('MigrateExistingChannelStreams: Command started by user.');

        DB::transaction(function () {
            $channels = Channel::with('failoverChannels')->get(); // Eager load old failovers relationship

            $this->info("Found {$channels->count()} channels to process.");
            Log::info("MigrateExistingChannelStreams: Found {$channels->count()} total channels to process.");

            foreach ($channels as $channel) {
                $this->line('');
                $channelTitleForLog = strip_tags($channel->title_custom ?? $channel->title);
                $this->info("Processing Channel ID: {$channel->id} ('{$channelTitleForLog}')");
                Log::info("MigrateExistingChannelStreams: Processing Channel ID: {$channel->id} ('{$channelTitleForLog}')");

                $priorityCounter = 1;

                // 1. Migrate the primary URL from the channel itself
                $primaryUrl = !empty($channel->url_custom) ? $channel->url_custom : $channel->url;

                if (!empty($primaryUrl)) {
                    $existingPrimary = ChannelStreamProvider::where('channel_id', $channel->id)
                                        ->where('stream_url', $primaryUrl)
                                        ->first();

                    if (!$existingPrimary) {
                        ChannelStreamProvider::create([
                            'channel_id' => $channel->id,
                            'stream_url' => $primaryUrl,
                            'priority' => $priorityCounter,
                            'provider_name' => 'Primary Source (Migrated)',
                            'is_active' => $channel->enabled ?? true,
                            'status' => 'online',
                            'last_checked_at' => now(),
                        ]);
                        $this->info("    Added primary URL: {$primaryUrl} with priority {$priorityCounter}");
                        Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Added primary URL '{$primaryUrl}' Prio {$priorityCounter}");
                        $priorityCounter++;
                    } else {
                        $this->comment("    Primary URL {$primaryUrl} already exists as a provider for channel {$channel->id}. Checking for updates...");
                        Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Primary URL '{$primaryUrl}' already exists. Checking for updates.");
                        $updatedNeeded = false;
                        if ($existingPrimary->priority !== 1) {
                             $existingPrimary->priority = 1; $updatedNeeded = true;
                        }
                        if ($existingPrimary->is_active !== ($channel->enabled ?? true)) {
                             $existingPrimary->is_active = ($channel->enabled ?? true); $updatedNeeded = true;
                        }
                        if ($existingPrimary->provider_name !== 'Primary Source (Migrated)') {
                             $existingPrimary->provider_name = 'Primary Source (Migrated)'; $updatedNeeded = true;
                        }
                        if($updatedNeeded){
                            $existingPrimary->save();
                            $this->comment("        Updated existing primary provider. New prio: 1, Active: " . ($existingPrimary->is_active ? 'true' : 'false'));
                            Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Updated existing primary provider '{$primaryUrl}'. New Prio: 1, Active: " . ($existingPrimary->is_active ? 'true' : 'false'));
                        } else {
                            $this->comment("        No updates needed for existing primary provider.");
                        }
                        $priorityCounter = $existingPrimary->priority + 1;
                    }
                } else {
                    $this->comment("    No primary URL (url or url_custom) found for channel {$channel->id}.");
                    Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - No primary URL found.");
                }

                // 2. Migrate old failover channels
                $oldFailoverChannels = $channel->failoverChannels;

                if ($oldFailoverChannels && $oldFailoverChannels->isNotEmpty()) {
                    $this->info("    Found {$oldFailoverChannels->count()} old failover streams for channel {$channel->id}.");
                    Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Found {$oldFailoverChannels->count()} old failovers.");
                    foreach ($oldFailoverChannels as $failoverSourceChannel) {
                        $failoverUrl = !empty($failoverSourceChannel->url_custom) ? $failoverSourceChannel->url_custom : $failoverSourceChannel->url;
                        $failoverSourceTitle = strip_tags($failoverSourceChannel->title_custom ?? $failoverSourceChannel->title);

                        if (!empty($failoverUrl)) {
                            $existingFailover = ChannelStreamProvider::where('channel_id', $channel->id)
                                                ->where('stream_url', $failoverUrl)
                                                ->first();

                            $expectedFailoverName = "Failover: {$failoverSourceTitle} (Migrated)";

                            if (!$existingFailover) {
                                ChannelStreamProvider::create([
                                    'channel_id' => $channel->id,
                                    'stream_url' => $failoverUrl,
                                    'priority' => $priorityCounter,
                                    'provider_name' => $expectedFailoverName,
                                    'is_active' => $failoverSourceChannel->enabled ?? true,
                                    'status' => 'online',
                                    'last_checked_at' => now(),
                                ]);
                                $this->info("        Added failover URL: {$failoverUrl} (from old failover channel ID {$failoverSourceChannel->id} - '{$failoverSourceTitle}') with priority {$priorityCounter}");
                                Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Added failover '{$failoverUrl}' (from failover ID {$failoverSourceChannel->id}) Prio {$priorityCounter}");
                                $priorityCounter++;
                            } else {
                                $this->comment("        Failover URL {$failoverUrl} already exists as a provider for channel {$channel->id}. Checking for updates...");
                                Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Failover URL '{$failoverUrl}' already exists. Checking for updates.");
                                $updatedNeeded = false;
                                if ($existingFailover->priority !== $priorityCounter) {
                                    $existingFailover->priority = $priorityCounter; $updatedNeeded = true;
                                }
                                if ($existingFailover->is_active !== ($failoverSourceChannel->enabled ?? true )) {
                                     $existingFailover->is_active = ($failoverSourceChannel->enabled ?? true); $updatedNeeded = true;
                                }
                                if ($existingFailover->provider_name !== $expectedFailoverName) {
                                    $existingFailover->provider_name = $expectedFailoverName; $updatedNeeded = true;
                                }
                                if($updatedNeeded){
                                    $existingFailover->save();
                                    $this->comment("            Updated existing failover provider. New prio: {$priorityCounter}, Active: " . ($existingFailover->is_active ? 'true' : 'false'));
                                    Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Updated existing failover '{$failoverUrl}'. New Prio: {$priorityCounter}, Active: " . ($existingFailover->is_active ? 'true' : 'false'));
                                } else {
                                     $this->comment("            No updates needed for existing failover provider '{$failoverUrl}'.");
                                }
                                $priorityCounter = $existingFailover->priority + 1;
                            }
                        } else {
                            $this->comment("        Failover source channel ID {$failoverSourceChannel->id} ('{$failoverSourceTitle}') has no URL. Skipping.");
                            Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - Failover source ID {$failoverSourceChannel->id} ('{$failoverSourceTitle}') has no URL.");
                        }
                    }
                } else {
                     $this->info("    No old failover streams configured for channel {$channel->id}.");
                     Log::info("MigrateExistingChannelStreams: Channel {$channel->id} - No old failovers found.");
                }
            }
        });

        $this->info('Migration of existing channel streams completed successfully.');
        Log::info('MigrateExistingChannelStreams: Command finished successfully.');
        return Command::SUCCESS;
    }
}
