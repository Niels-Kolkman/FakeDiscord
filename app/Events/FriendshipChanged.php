<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed between two users. Sent to the other person
 * only, on their private "App.Models.User.{id}" channel; the one who acted
 * gets the change in the HTTP response.
 *
 * type:
 * - "request":  $actor sent the recipient a friend request (the toast)
 * - "accepted": $actor accepted the recipient's request
 * - "removed":  the pending request or friendship is gone. Declining,
 *               cancelling and removing a friend all look the same, so
 *               the sender does not learn which one it was.
 *
 * incoming_count is the recipient's number of requests waiting for an
 * answer, so the badges are exact without another request.
 * resources/js/friends.js listens for ".friendship.changed".
 */
class FriendshipChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'friendship.changed';

    public const REQUEST = 'request';

    public const ACCEPTED = 'accepted';

    public const REMOVED = 'removed';

    public function __construct(
        public string $type,
        public int $friendshipId,
        public User $actor,
        public User $recipient,
    ) {}

    public static function dispatchFor(string $type, Friendship $friendship, User $actor): void
    {
        // Saved either way; if Reverb is down, report it and carry on.
        rescue(function () use ($type, $friendship, $actor): void {
            broadcast(new self($type, (int) $friendship->getKey(), $actor, $friendship->otherUser($actor)));
        });
    }

    protected function channels(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipient->getKey());
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{type: string, friendship_id: int, user: array{id: int, name: string, username: string, avatar_url: string}, incoming_count: int}
     */
    protected function payload(): array
    {
        return [
            'type' => $this->type,
            'friendship_id' => $this->friendshipId,
            'user' => [
                'id' => (int) $this->actor->getKey(),
                'name' => $this->actor->name,
                'username' => $this->actor->username,
                'avatar_url' => $this->actor->avatarUrl(),
            ],
            'incoming_count' => Friendship::incomingCount($this->recipient),
        ];
    }
}
