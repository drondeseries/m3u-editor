<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ChannelFailover;
use App\Models\Playlist; // Added Playlist model

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'active_channel_stream_id',
    ];

    /**
     * Get all of the streams for the Channel.
     */
    public function channelStreams(): HasMany
    {
        return $this->hasMany(ChannelStream::class);
    }

    /**
     * Get the active stream for the Channel.
     */
    public function activeStream(): BelongsTo
    {
        return $this->belongsTo(ChannelStream::class, 'active_channel_stream_id');
    }

    /**
     * Get the failover configurations for this channel.
     * These point to other channels that can act as backups.
     */
    public function failovers(): HasMany
    {
        return $this->hasMany(ChannelFailover::class, 'channel_id', 'id')->orderBy('sort', 'asc');
    }

    /**
     * Get the playlist that this channel belongs to.
     */
    public function playlist(): BelongsTo
    {
        // Assuming 'playlist_id' is the foreign key on the 'channels' table
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the display name formatted for Filament select components.
     */
    public function getFilamentSelectNameAttribute(): string
    {
        $displayName = $this->name; // Default to name
        if (!empty($this->title_custom)) {
            $displayName = $this->title_custom;
        } elseif (!empty($this->title)) {
            $displayName = $this->title;
        }

        // Assuming 'playlist' relationship exists and is a BelongsTo
        $playlistName = $this->playlist?->name ?? 'N/A';
        return "{$displayName} [{$playlistName}]";
    }
}
