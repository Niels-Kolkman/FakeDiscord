<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A channel message was pinned or unpinned. Carries the ids,
 * the new pinned_at (NULL = unpinned) and who pinned it; the browser
 * updates the pin marker and the pinned messages panel. It listens for
 * ".message.pinned" (resources/js/channel-messages.js).
 */
class MessagePinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'message.pinned';

    public function __construct(public Message $message) {}

    protected function channels(): PrivateChannel
    {
        return new PrivateChannel(MessageSent::channelName((int) $this->message->channel_id));
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{id: int, channel_id: int, pinned_at: string|null, pinned_by: array{id: int, name: string}|null}
     */
    protected function payload(): array
    {
        $by = $this->message->isPinned() ? $this->message->pinnedBy : null;

        return [
            'id' => (int) $this->message->id,
            'channel_id' => (int) $this->message->channel_id,
            'pinned_at' => $this->message->pinned_at?->toIso8601String(),
            'pinned_by' => $by === null ? null : ['id' => (int) $by->id, 'name' => $by->name],
        ];
    }
}
