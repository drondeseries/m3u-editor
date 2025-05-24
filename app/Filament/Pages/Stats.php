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
    protected ?string        $subheading      = 'Displays real-time statistics for active MPEG-TS streams.';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int    $navigationSort  = 97;
    protected static string  $view            = 'filament.pages.stats';
    protected static bool    $shouldRegisterNavigation = true; 

    public static ?string $pollingInterval = '5s';

    protected function getHeaderWidgets(): array
    {
        try {
            // Fetch all currently streaming channel IDs
            $activeIds = Redis::smembers('mpts:active_ids');
            if (empty($activeIds)) {
                return [];
            }

            // Decode the channel IDs and IPs
            $clients = [];
            foreach ($activeIds as $clientKey) {
                $keys = explode('::', $clientKey);
                if (count($keys) < 2) {
                    continue;
                }
                $channelId = $keys[1];
                $channel = Channel::find($channelId);
                $clients[] = [
                    'channelId' => $channelId,
                    'title'     => $channel?->title ?? 'Unknown',
                    'ip'        => $keys[0],
                ];
            }

            // Dynamically spawn one StreamStatsChart per streaming channel/client
            $widgets = [];
            foreach ($clients as $client) {
                $widgets[] = StreamStatsChart::make([
                    'streamId'          => $client['channelId'],
                    'title'             => "{$client['title']} (MPTS)",
                    'subheading'        => $client['ip'],
                    'columnSpan'        => 4,
                    'pollingInterval'   => '1s',
                ]);
            }
            return $widgets;
        } catch (ConnectionException $e) {
            Log::error('Redis connection error in Stats page: ' . $e->getMessage());
            return [];
        }
    }
}
