<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelStream extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'provider_name',
        'stream_url',
        'priority',
        'status',
        'last_checked_at',
        'last_error_at',
        'consecutive_stall_count',
        'consecutive_failure_count',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * Get the channel that owns the stream.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
