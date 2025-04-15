<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CustomPlaylist extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'short_urls' => 'array',
        'short_urls_enabled' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_custom_playlist');
    }

    public function enabled_channels(): BelongsToMany
    {
        return $this->channels()->where('enabled', true);
    }

    // public function playlists(): HasManyThrough
    // {
    //     return $this->hasManyThrough(
    //         Playlist::class,
    //         CustomPlaylistPivot::class,
    //         'custom_playlist_id',
    //         'channel_id',
    //         'id',
    //         'channel_id'
    //     );
    // }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }
}
