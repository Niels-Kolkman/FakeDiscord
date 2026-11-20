<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A channel message was edited. Same channel and payload as
 * MessageSent, so the browser can re-render it; it listens for
 * ".message.updated" (resources/js/channel-messages.js).
 */
class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'message.updated';

    public function __construct(public Message $message) {}

    protected function channels(): PrivateChannel
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
    protected function payload(): array
    {
        return $this->message->toPayload();
    }
}
