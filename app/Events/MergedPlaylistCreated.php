<?php

namespace App\Events;

use App\Models\MergedPlaylist;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MergedPlaylistCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * 
     * @param MergedPlaylist $playlist
     */
    public function __construct(
        public MergedPlaylist $playlist
    ) {}
}
