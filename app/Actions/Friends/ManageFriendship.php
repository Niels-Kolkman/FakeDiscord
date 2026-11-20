<?php

namespace App\Actions\Friends;

use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;

/**
 * Answer, withdraw or end a friendship. Who may do what is
 * App\Policies\FriendshipPolicy; this only makes the change.
 *
 * Each write only matches the row in the state it was checked in, so two
 * clicks at once (or accept racing a cancel) give one change and one
 * broadcast. Each method returns whether it changed anything.
 *
 * The other person gets FriendshipChanged: "accepted" or "removed" (decline,
 * cancel and remove look the same to them). Unblocking tells nobody.
 */
class ManageFriendship
{
    public function accept(User $user, Friendship $friendship): bool
    {
        $now = now();

        $updated = Friendship::query()
            ->whereKey($friendship->getKey())
            ->where('friend_id', $user->getKey())
            ->where('status', Friendship::STATUS_PENDING)
            ->update(['status' => Friendship::STATUS_ACCEPTED, 'accepted_at' => $now, 'updated_at' => $now]);

        if ($updated === 0) {
            return false;
        }

        $friendship->forceFill(['status' => Friendship::STATUS_ACCEPTED, 'accepted_at' => $now, 'updated_at' => $now])->syncOriginal();
        FriendshipChanged::dispatchFor(FriendshipChanged::ACCEPTED, $friendship, $user);

        return true;
    }

    /** The recipient turns a request down. */
    public function decline(User $user, Friendship $friendship): bool
    {
        return $this->delete($user, $friendship, Friendship::STATUS_PENDING);
    }

    /** The sender takes a request back. */
    public function cancel(User $user, Friendship $friendship): bool
    {
        return $this->delete($user, $friendship, Friendship::STATUS_PENDING);
    }

    /** Either friend ends the friendship. */
    public function remove(User $user, Friendship $friendship): bool
    {
        return $this->delete($user, $friendship, Friendship::STATUS_ACCEPTED);
    }

    /** The blocker lifts a block. The other person is not told. */
    public function unblock(User $user, Friendship $friendship): bool
    {
        return Friendship::query()
            ->whereKey($friendship->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', Friendship::STATUS_BLOCKED)
            ->delete() > 0;
    }

    private function delete(User $user, Friendship $friendship, string $status): bool
    {
        $deleted = Friendship::query()
            ->whereKey($friendship->getKey())
            ->involving($user)
            ->where('status', $status)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        FriendshipChanged::dispatchFor(FriendshipChanged::REMOVED, $friendship, $user);

        return true;
    }
}
