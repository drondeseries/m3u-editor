<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FailoverChannel extends Model
{
    protected $table = 'failover_channels';

    protected $fillable = [
        'name',
        'speed_threshold',
    ];

    /**
     * The channels that are sources for this failover channel.
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'failover_channel_sources', 'failover_channel_id', 'channel_id')
                    ->withPivot('order')
                    ->orderBy('failover_channel_sources.order', 'asc');
    }
}
