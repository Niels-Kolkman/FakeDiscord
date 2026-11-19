<?php

namespace App\Support;

use App\Events\DirectMessageReceived;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;

/**
 * Browser notifications for DMs (ISSUE-34): after a DM or group DM
 * message is sent, every other participant gets DirectMessageReceived on
 * their own channel, except people who blocked the author (they don't see
 * those messages, ISSUE-25). Nothing is stored: DMs are not in the inbox.
 *
 * A failed broadcast is reported and the others still go out, like the
 * message events.
 */
class DirectMessageNotifier
{
    /**
     * @return list<int> the ids of the users it went to
     */
    public function notify(Message $message, Conversation $conversation): array
    {
        $authorId = (int) $message->user_id;
        $conversation->loadMissing('users');

        $recipients = $conversation->users
            ->reject(fn (User $user): bool => (int) $user->getKey() === $authorId)
            ->sortBy('id')
            ->values();

        if ($recipients->isEmpty()) {
            return [];
        }

        $blockers = $message->user_id === null ? [] : Friendship::query()
            ->where('friend_id', $authorId)
            ->where('status', Friendship::STATUS_BLOCKED)
            ->whereIn('user_id', $recipients->modelKeys())
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $sent = [];
        foreach ($recipients as $user) {
            if (in_array((int) $user->getKey(), $blockers, true)) {
                continue;
            }

            // A void closure: PendingBroadcast sends when it is destroyed,
            // which has to happen inside rescue().
            rescue(function () use ($message, $conversation, $user): void {
                broadcast(new DirectMessageReceived($message, $conversation, $user));
            });
            $sent[] = (int) $user->getKey();
        }

        return $sent;
    }
}
