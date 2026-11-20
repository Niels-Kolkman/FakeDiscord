<?php

namespace App\Actions\Friends;

use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Block someone. The block is a friendships row with
 * user_id = the blocker and status "blocked".
 *
 * - A pending request (either way) or a friendship between the two is
 *   replaced by the block. The other person gets FriendshipChanged
 *   "removed", the same as a decline or removal, so they cannot tell they
 *   were blocked. Without such a row nobody is told anything.
 * - When they already blocked you, your block is a second row (you->them
 *   next to them->you), so it stays when they unblock you. This is the
 *   only case where both directions exist.
 * - Blocking someone you already blocked changes nothing.
 *
 * What a block does is checked elsewhere: SendFriendRequest refuses
 * requests, OpenDirectConversation and ConversationPolicy refuse DMs, and
 * Message::visibleTo() hides the blocked user's server messages from the
 * blocker.
 */
class BlockUser
{
    /**
     * @return array{Friendship, bool} the blocker's row, and whether anything changed
     *
     * @throws ValidationException
     */
    public function block(User $blocker, User $blocked): array
    {
        if ($blocked->is($blocker)) {
            throw ValidationException::withMessages(['user' => __('You cannot block yourself.')]);
        }

        try {
            [$friendship, $changed, $ended] = DB::transaction(fn (): array => $this->apply($blocker, $blocked));
        } catch (UniqueConstraintViolationException) {
            // Two clicks at once: the other one created the block.
            $friendship = Friendship::query()
                ->where('user_id', $blocker->getKey())
                ->where('friend_id', $blocked->getKey())
                ->firstOrFail();

            return [$friendship, false];
        }

        $friendship->setRelation('user', $blocker)->setRelation('friend', $blocked);

        if ($ended) {
            FriendshipChanged::dispatchFor(FriendshipChanged::REMOVED, $friendship, $blocker);
        }

        return [$friendship, $changed];
    }

    /**
     * @return array{Friendship, bool, bool} the row, whether it changed, whether a request or friendship ended
     */
    private function apply(User $blocker, User $blocked): array
    {
        $rows = Friendship::query()->between($blocker, $blocked)->lockForUpdate()->get();

        $own = $rows->first(fn (Friendship $row): bool => $row->sentBy($blocker));
        $theirs = $rows->first(fn (Friendship $row): bool => ! $row->sentBy($blocker));

        if ($own !== null && $own->isBlocked()) {
            return [$own, false, false];
        }

        // A request or friendship, sent by either of them.
        $existing = $own ?? ($theirs !== null && ! $theirs->isBlocked() ? $theirs : null);

        $attributes = [
            'user_id' => $blocker->getKey(),
            'friend_id' => $blocked->getKey(),
            'status' => Friendship::STATUS_BLOCKED,
            'accepted_at' => null,
        ];

        if ($existing !== null) {
            // Keep the id, so the other person's lists drop the right row.
            $existing->forceFill($attributes)->save();

            return [$existing, true, true];
        }

        return [Friendship::query()->create($attributes), true, false];
    }
}
