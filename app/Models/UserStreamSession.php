<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStreamSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_id',
        'channel_id',
        'active_channel_stream_id',
        'ffmpeg_pid',
        'worker_pid',
        'last_segment_filename',
        'last_segment_media_sequence',
        'last_segment_at',
        'session_started_at',
        'last_activity_at',
    ];

    protected $casts = [
        'last_segment_at' => 'datetime',
        'session_started_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Get the channel associated with the user stream session.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the active channel stream associated with the user stream session.
     */
    public function activeChannelStream(): BelongsTo
    {
        return $this->belongsTo(ChannelStream::class, 'active_channel_stream_id');
    }

    // Optional: Define user relationship if a User model exists and is used.
    // public function user(): BelongsTo
    // {
    //     return $this->belongsTo(User::class); // Assuming App\Models\User
    // }
}
