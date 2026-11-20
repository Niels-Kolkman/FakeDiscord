<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open the 1:1 DM between two users, creating it the first time.
 *
 * A pair has one conversation: it is looked up by its direct_key, and the
 * unique index on that column makes createOrFirst() return the existing
 * row when both users open it at the same moment.
 *
 * Like Discord you can message friends and people you share a server
 * with. Nobody can message themselves, and nobody can open a DM across a
 * block in either direction. Someone who blocked
 * you gets the same error as someone you cannot reach, so this cannot be
 * used to find out about the block.
 */
class OpenDirectConversation
{
    /**
     * @throws ValidationException
     */
    public function open(User $from, User $to): Conversation
    {
        if ($to->is($from)) {
            throw self::fail(__('You cannot send direct messages to yourself.'));
        }

        $key = Conversation::directKey($from, $to);
        $existing = Conversation::query()->where('direct_key', $key)->first();

        // An existing conversation stays reachable for its history; sending
        // still checks for a block (ConversationPolicy::sendMessage).
        if ($existing !== null) {
            return $existing;
        }

        if (! self::canStart($from, $to)) {
            throw self::fail(self::unreachable());
        }

        return DB::transaction(function () use ($from, $to, $key): Conversation {
            $conversation = Conversation::query()->createOrFirst(
                ['direct_key' => $key],
                ['type' => Conversation::TYPE_DIRECT],
            );

            if ($conversation->wasRecentlyCreated) {
                $conversation->users()->attach([$from->getKey(), $to->getKey()]);
            }

            return $conversation;
        });
    }

    /**
     * Friends, or members of a common server, with no block between them.
     */
    public static function canStart(User $from, User $to): bool
    {
        if ($to->is($from) || self::blocked($from, $to)) {
            return false;
        }

        $friends = Friendship::query()
            ->between($from, $to)
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->exists();

        return $friends || $from->servers()
            ->whereHas('members', fn ($query) => $query->whereKey($to->getKey()))
            ->exists();
    }

    /**
     * Whether either of the two blocked the other.
     */
    public static function blocked(User $a, User $b): bool
    {
        return Friendship::query()
            ->between($a, $b)
            ->where('status', Friendship::STATUS_BLOCKED)
            ->exists();
    }

    public static function unreachable(): string
    {
        return __('You can only send direct messages to friends and people you share a server with.');
    }

    private static function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['user' => $message]);
    }
}
