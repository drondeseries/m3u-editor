<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use Carbon\Carbon;
use Filament\Pages\Page;

class DetailedSystemStats extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-information-circle';

    protected static ?string $navigationLabel = 'Detailed Statistics';

    protected static ?string $title = 'Detailed System Statistics';

    protected static ?string $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 98;

    protected static string $view = 'filament.pages.detailed-system-stats';

    protected static bool $shouldRegisterNavigation = true; // Make it visible

    public $totalPlaylists;
    public $lastPlaylistSync;
    public $totalGroups;
    public $totalChannels;
    public $enabledChannels;
    public $totalEpgs;
    public $lastEpgSync;
    public $totalEpgChannels;
    public $mappedEpgChannels;

    public function mount(): void
    {
        $userId = auth()->id();

        $this->totalPlaylists = Playlist::where('user_id', $userId)->count();
        $lastPlaylistSyncDate = Playlist::where('user_id', $userId)->max('synced'); 
        $this->lastPlaylistSync = $lastPlaylistSyncDate ? Carbon::parse($lastPlaylistSyncDate)->diffForHumans() : 'Never';

        $this->totalGroups = Group::where('user_id', $userId)->count();

        $this->totalChannels = Channel::where('user_id', $userId)->count();
        $this->enabledChannels = Channel::where('user_id', $userId)->where('enabled', true)->count();

        $this->totalEpgs = Epg::where('user_id', $userId)->count();
        $lastEpgSyncDate = Epg::where('user_id', $userId)->max('synced'); 
        $this->lastEpgSync = $lastEpgSyncDate ? Carbon::parse($lastEpgSyncDate)->diffForHumans() : 'Never';

        $this->totalEpgChannels = EpgChannel::whereHas('epg', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->count();
        
        $this->mappedEpgChannels = EpgChannel::whereHas('epg', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->whereNotNull('channel_id')->count();
    }
}
