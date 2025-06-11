<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelStreamProvider extends Model
{
    use HasFactory;

    protected $table = 'channel_stream_providers';

    protected $fillable = [
        'channel_id',
        'stream_url',
        'priority',
        'provider_name',
        'is_active',
        'last_checked_at',
        'status',
    ];

    protected $casts = [
        'channel_id' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
