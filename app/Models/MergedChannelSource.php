<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergedChannelSource extends Model
{
    use HasFactory;

    protected $table = 'merged_channel_sources'; // Explicitly define table name

    protected $fillable = [
        'merged_channel_id',
        'source_channel_id',
        'priority',
    ];

    /**
     * Get the merged channel that this source entry belongs to.
     */
    public function mergedChannel(): BelongsTo
    {
        return $this->belongsTo(MergedChannel::class);
    }

    /**
     * Get the source channel that this entry points to.
     */
    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'source_channel_id');
    }
}
