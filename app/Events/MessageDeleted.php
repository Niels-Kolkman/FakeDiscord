<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A channel message was deleted (ISSUE-16). Only the ids go out, never
 * the content: the browser removes the message with that id. It listens
 * for ".message.deleted" (resources/js/channel-messages.js).
 */
class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'message.deleted';

    public function __construct(public int $messageId, public int $channelId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(MessageSent::channelName($this->channelId));
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{id: int, channel_id: int}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'channel_id' => $this->channelId];
    }
}
