<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamParametersChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $channelId;
    public ?int $userId;
    public string $streamType; // e.g., 'channel', 'episode'

    /**
     * Create a new event instance.
     *
     * @param int $channelId
     * @param string $streamType
     * @param int|null $userId
     */
    public function __construct(int $channelId, string $streamType, ?int $userId = null)
    {
        $this->channelId = $channelId;
        $this->streamType = $streamType;
        $this->userId = $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("stream-status.{$this->streamType}.{$this->channelId}")
        ];

        if ($this->userId) {
            $channels[] = new PrivateChannel("stream-status.user.{$this->userId}.{$this->streamType}.{$this->channelId}");
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'stream.parameters.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // By default, all public properties will be broadcast.
        // Return an empty array if you want to rely on default behavior explicitly,
        // or customize the payload here.
        return [
            'channelId' => $this->channelId,
            'streamType' => $this->streamType,
            'userId' => $this->userId,
            // Add any other specific parameters that changed, if necessary
        ];
    }
}
