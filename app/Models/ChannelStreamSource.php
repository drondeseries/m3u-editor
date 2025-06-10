<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelStreamSource extends Model
{
    use HasFactory;

    protected $table = 'channel_stream_sources';

    protected $fillable = [
        'channel_id',
        'stream_url',
        'provider_name',
        'priority',
        'status',
        'is_enabled',
        'last_checked_at',
        'last_failed_at',
        'consecutive_failures',
        'custom_headers',
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'custom_headers' => 'array',
        'last_checked_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    /**
     * Get the channel that owns the stream source.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
