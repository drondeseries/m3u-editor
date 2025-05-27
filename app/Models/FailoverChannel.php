<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\CustomPlaylist;
use Spatie\Tags\HasTags;
use Illuminate\Support\Str; // Added
use App\Enums\ChannelLogoType; // Added

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

    public function getLogoDisplayAttribute(): string
    {
        if (!empty($this->tvg_logo_override)) {
            return $this->tvg_logo_override;
        }
        $primarySource = $this->sources()->orderBy('failover_channel_sources.order', 'asc')->first();
        if ($primarySource) {
            if ($primarySource->logo_type === \App\Enums\ChannelLogoType::Epg && $primarySource->epgChannel) {
                return $primarySource->epgChannel->icon ?? url('/placeholder.png');
            } elseif ($primarySource->logo_type === \App\Enums\ChannelLogoType::Channel) {
                return $primarySource->logo ?? url('/placeholder.png');
            }
        }
        return url('/placeholder.png');
    }

    public function getTvgChannelNumberDisplayAttribute(): ?string
    {
        if (!empty($this->tvg_chno_override)) {
            return (string) $this->tvg_chno_override;
        }
        $primarySource = $this->sources()->orderBy('failover_channel_sources.order', 'asc')->first();
        if ($primarySource && $primarySource->channel) {
            return (string) $primarySource->channel;
        }
        return null; // Or a default like '-'
    }
}
