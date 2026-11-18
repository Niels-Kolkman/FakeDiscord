<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A channel message was edited (ISSUE-16). Same channel and payload as
 * MessageSent, so the browser can re-render it; it listens for
 * ".message.updated" (resources/js/channel-messages.js).
 */
class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'message.updated';

    public function __construct(public Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->message->broadcastChannel());
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message->toPayload();
    }
}
