<?php

namespace App\Filament\Pages;

use App\Livewire\StreamStatsChart;
use App\Models\Channel;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log; // Added
use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException; // Added

class Stats extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Live Stream Monitor';
    protected static ?string $title           = 'Live Stream Monitor';
    //protected ?string        $subheading      = 'Start streaming a channel to see the stats.';
    protected ?string        $subheading      = 'Overview of active streams, with on-demand access to their technical details.';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int    $navigationSort  = 97;
    protected static string  $view            = 'filament.pages.stats';
    protected static bool    $shouldRegisterNavigation = true; 

    public static ?string $pollingInterval = '5s';
    public array $activeStreamDetails = [];

    // Properties for FFprobe Modal
    public ?array $ffprobeStreamDetails = null;
    public bool $showFfprobeDetailsModal = false;
    public ?string $ffprobeModalTitle = null;

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function viewData(): array
    {
        $this->activeStreamDetails = [];
        try {
            $activeIds = Redis::smembers('stream_stats:active_ids'); // Updated Redis key
            
            if (empty($activeIds)) {
                // If no active IDs, ensure details are empty and return
                return ['activeStreamDetails' => $this->activeStreamDetails]; 
            }

            $details = [];
            foreach ($activeIds as $streamKey) { // Renamed variable for clarity
                $parts = explode('_', $streamKey); // Split by underscore for new format

                // Basic validation for the new format (e.g., type_channelId_...)
                if (count($parts) < 2) { 
                    Log::warning("Invalid stream key format from Redis: {$streamKey}");
                    continue; 
                }

                // Assuming format is like 'hls_CHANNELID_PID' or 'direct_CHANNELID_CLIENTKEY_PID'
                // The channel_id is expected to be the second part.
                $channel_id = $parts[1];
                
                // Client IP is not directly available in the new key format in a consistent way.
                // Set to "N/A" as per subtask instructions.
                $client_ip = "N/A"; 

                $channel = Channel::find($channel_id);

                if ($channel) {
                    $details[] = [
                        'channel_id' => $channel->id,
                        'title' => $channel->title ?? 'Unknown Title',
                        'client_ip' => $client_ip, // Updated client_ip handling
                        'proxy_url' => $channel->proxy_url, 
                        'owner_name' => $channel->user?->name ?? 'N/A', // Adjusted for potentially null user
                    ];
                } else {
                    Log::warning("Channel ID {$channel_id} from Redis stream_stats:active_ids not found in database. Key: {$streamKey}");
                }
            }
            $this->activeStreamDetails = $details;

        } catch (ConnectionException $e) {
            Log::error('Redis connection error in Stats page (viewData): ' . $e->getMessage());
            // $this->activeStreamDetails is already empty or as set before error
        }
        
        // Explicitly pass the data to the view.
        return ['activeStreamDetails' => $this->activeStreamDetails];
    }

    public function loadAndShowFfprobeStats(int $channelId): void
    {
        $this->ffprobeStreamDetails = null; // Reset previous details
        $channel = Channel::find($channelId);

        if (!$channel) {
            $this->ffprobeStreamDetails = [['error' => 'Channel not found.']]; // Ensure it's an array of arrays for consistent handling in view
            $this->ffprobeModalTitle = 'Error';
            $this->showFfprobeDetailsModal = true;
            return;
        }

        $this->ffprobeModalTitle = $channel->title ?? 'Stream Details';
        Log::info("Attempting to fetch ffprobe stats for channel ID: {$channelId} - Name: {$channel->title}");
        
        $stats = $channel->stream_stats; // This executes ffprobe via the accessor

        if (empty($stats)) {
            Log::warning("ffprobe returned no stats for channel ID: {$channelId} - Name: {$channel->title}. Stream might be offline or ffprobe failed. Check server logs if errors expected there.");
            $this->ffprobeStreamDetails = [['message' => 'No stream details could be retrieved. The stream might be offline or ffprobe failed.']];
        } else {
            $this->ffprobeStreamDetails = $stats;
        }
        
        $this->showFfprobeDetailsModal = true;
    }

    public function closeModal(): void
    {
        $this->showFfprobeDetailsModal = false;
        $this->ffprobeStreamDetails = null;
        $this->ffprobeModalTitle = null;
    }
}
