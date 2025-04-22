<?php

namespace App\Events;

use App\Models\Epg;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EpgDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * 
     * @param Epg $epg
     */
    public function __construct(
        public Epg $epg,
    ) {}
}
