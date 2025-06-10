<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamUnavailableEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $channelId;
    public ?int $userId;
    public string $streamType; // e.g., 'channel', 'episode'
    public string $message;

    /**
     * Create a new event instance.
     *
     * @param int $channelId
     * @param string $streamType
     * @param string $message
     * @param int|null $userId
     */
    public function __construct(int $channelId, string $streamType, string $message, ?int $userId = null)
    {
        $this->channelId = $channelId;
        $this->streamType = $streamType;
        $this->message = $message;
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
        return 'stream.unavailable';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->channelId,
            'streamType' => $this->streamType,
            'userId' => $this->userId,
            'message' => $this->message,
        ];
    }
}
