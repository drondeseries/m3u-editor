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
            $activeIds = Redis::smembers('mpts:active_ids');
            
            if (empty($activeIds)) {
                // If no active IDs, ensure details are empty and return
                return ['activeStreamDetails' => $this->activeStreamDetails]; 
            }

            $details = [];
            foreach ($activeIds as $clientKey) {
                $parts = explode('::', $clientKey);
                if (count($parts) < 2) {
                    continue; // Invalid format
                }
                $client_ip = $parts[0];
                $channel_id = $parts[1];

                $channel = Channel::find($channel_id);

                if ($channel) {
                    $details[] = [
                        'channel_id' => $channel->id,
                        'title' => $channel->title ?? 'Unknown Title',
                        'client_ip' => $client_ip,
                        'proxy_url' => $channel->proxy_url, // Assuming proxy_url exists on Channel model
                        'owner_name' => $channel->user->name ?? ($channel->user ? 'Owner Name Missing' : 'N/A'),
                    ];
                } else {
                    // Optionally handle case where channel is not found but ID was in Redis
                    Log::warning("Channel ID {$channel_id} from Redis active_ids not found in database.");
                }
            }
            $this->activeStreamDetails = $details;

        } catch (ConnectionException $e) {
            Log::error('Redis connection error in Stats page (viewData): ' . $e->getMessage());
            // $this->activeStreamDetails is already empty or as set before error
        }
        
        // Explicitly pass the data to the view. For Filament Pages, public properties are
        // typically automatically available, but this makes it explicit.
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
