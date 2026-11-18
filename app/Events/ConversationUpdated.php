<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A group DM changed (ISSUE-24): it was created or renamed, or people were
 * added, removed or left. Goes to the "App.Models.User.{id}" channel of
 * every participant and of the people who just left or were removed, so
 * resources/js/direct-messages.js can reload the DM list and, on the
 * group's own page, its header and member list (or leave the page when
 * the viewer is no longer in it). Only the id goes out.
 *
 * The recipients are read when the event is made, so a group that was
 * deleted when its last person left still reaches that person.
 */
class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'conversation.updated';

    public readonly int $conversationId;

    /** @var list<int> */
    public readonly array $recipientIds;

    /**
     * @param  list<int>  $alsoNotify  former participants
     */
    public function __construct(Conversation $conversation, array $alsoNotify = [])
    {
        $this->conversationId = (int) $conversation->getKey();
        $this->recipientIds = $conversation->participants()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->merge($alsoNotify)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id): PrivateChannel => new PrivateChannel('App.Models.User.'.$id), $this->recipientIds);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{conversation_id: int}
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
