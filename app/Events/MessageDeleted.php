<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was deleted. Only the ids go out, never the
 * content: the browser removes the message with that id. It listens for
 * ".message.deleted" (resources/js/channel-messages.js).
 *
 * A channel message goes out on "channel.{id}"; a DM, with a
 * $conversationId and no $channelId, on "conversation.{id}".
 */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'message.deleted';

    public function __construct(
        public int $messageId,
        public ?int $channelId,
        public ?int $conversationId = null,
    ) {}

    protected function channels(): PrivateChannel
    {
        return new PrivateChannel($this->conversationId !== null
            ? Conversation::broadcastChannelName($this->conversationId)
            : MessageSent::channelName((int) $this->channelId));
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{id: int, channel_id: int|null, conversation_id: int|null}
     */
    protected function payload(): array
    {
        return ['id' => $this->messageId, 'channel_id' => $this->channelId, 'conversation_id' => $this->conversationId];
    }
}
