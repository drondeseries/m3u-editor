<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\CustomPlaylist;
use Spatie\Tags\HasTags;

class FailoverChannel extends Model
{
    use HasTags;
    protected $table = 'failover_channels';

    protected $fillable = [
        'name',
        'speed_threshold',
        'tvg_id_override',
        'tvg_logo_override',
        'tvg_name_override',
        'tvg_chno_override',
        'tvg_guide_stationid_override',
    ];

    /**
     * The channels that are sources for this failover channel.
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'failover_channel_sources', 'failover_channel_id', 'channel_id')
                    ->withPivot('order') // Only 'order' remains
                    ->orderBy('failover_channel_sources.order', 'asc');
    }

    /**
     * The custom playlists that this failover channel belongs to.
     */
    public function customPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(CustomPlaylist::class, 'custom_playlist_failover_channel');
    }
}
