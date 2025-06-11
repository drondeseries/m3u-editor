<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamSwitchedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param int $channelId The ID of the parent channel.
     * @param string $sessionId The unique session ID of the user.
     * @param int $newChannelStreamId The ID of the new ChannelStream that is now active for this session.
     * @param string $newHlsUrl The specific master M3U8 URL for the client to load for the new stream.
     */
    public function __construct(
        public int $channelId,
        public string $sessionId,
        public int $newChannelStreamId,
        public string $newHlsUrl
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel("stream-session.{$this->sessionId}");
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'stream.switched';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'channel_id' => $this->channelId,
            'new_channel_stream_id' => $this->newChannelStreamId,
            'new_hls_url' => $this->newHlsUrl,
        ];
    }
}
