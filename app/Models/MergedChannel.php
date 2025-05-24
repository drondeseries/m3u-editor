<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MergedChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'epg_channel_id',
        'tvg_id',
        'tvg_name',
        'tvg_logo',
        'tvg_chno',
        'tvc_guide_stationid',
    ];

    /**
     * Get the user that owns the merged channel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the source entries for this merged channel, ordered by priority.
     * This defines the direct relationship to the pivot model.
     */
    public function sourceEntries(): HasMany
    {
        return $this->hasMany(MergedChannelSource::class)->orderBy('priority', 'asc');
    }

    /**
     * Get all source channels for this merged channel, ordered by priority.
     * This provides a direct way to get the Channel models.
     */
    public function sourceChannels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'merged_channel_sources', 'merged_channel_id', 'source_channel_id')
                    ->withPivot('priority')
                    ->orderBy('merged_channel_sources.priority', 'asc');
    }

    /**
     * Get the EPG channel associated with this merged channel.
     */
    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }
}
