<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone wrote in a DM. Goes to every participant's own
 * "App.Models.User.{id}" channel, so the DM list in their sidebar can move
 * the conversation to the top, or show it for the first time, wherever
 * they are in the app. Only ids and the time go out, never the content.
 * The browser listens for ".conversation.activity"
 * (resources/js/direct-messages.js).
 */
class ConversationActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'conversation.activity';

    public function __construct(public Conversation $conversation) {}

    /**
     * @return list<PrivateChannel>
     */
    protected function channels(): array
    {
        return $this->conversation->participants()
            ->pluck('user_id')
            ->map(fn ($id): PrivateChannel => new PrivateChannel('App.Models.User.'.(int) $id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{conversation_id: int, last_message_at: string|null}
     */
    protected function payload(): array
    {
        return [
            'conversation_id' => (int) $this->conversation->getKey(),
            'last_message_at' => $this->conversation->last_message_at?->toIso8601String(),
        ];
    }
}
