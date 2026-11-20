<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Message;
use App\Support\Reactions;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone added or removed a reaction on a channel message (ISSUE-18).
 *
 * Carries the change (who, which emoji, added or removed) and the
 * message's grouped reactions after it, without `me`: the browser keeps
 * its own "did I react" state and only changes it when user_id is the
 * viewer (their other tabs). It listens for ".reactions.updated"
 * (resources/js/channel-messages.js).
 */
class ReactionsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'reactions.updated';

    public function __construct(
        public Message $message,
        public int $userId,
        public string $emoji,
        public bool $added,
    ) {}

    protected function channels(): PrivateChannel
    {
        return new PrivateChannel($this->message->broadcastChannel());
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{message_id: int, channel_id: int|null, conversation_id: int|null, user_id: int, emoji: string, added: bool, reactions: list<array<string, mixed>>}
     */
    protected function payload(): array
    {
        return [
            'message_id' => (int) $this->message->id,
            'channel_id' => $this->message->channel_id === null ? null : (int) $this->message->channel_id,
            'conversation_id' => $this->message->conversation_id === null ? null : (int) $this->message->conversation_id,
            'user_id' => $this->userId,
            'emoji' => $this->emoji,
            'added' => $this->added,
            'reactions' => Reactions::groups($this->message),
        ];
    }
}
