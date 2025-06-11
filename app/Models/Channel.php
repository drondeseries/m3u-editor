<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ChannelFailover;

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
}
