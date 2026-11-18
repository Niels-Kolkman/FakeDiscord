<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Discovered automatically for App\Models\Friendship (ISSUE-22).
 *
 * Someone who is not one of the two users gets a 404, so friendship ids
 * do not tell anyone who is friends with whom. A blocked user gets a 404
 * too: they never learn about the block. A party asking for the wrong
 * thing (accepting their own request, removing a pending one) gets a 403.
 */
class FriendshipPolicy
{
    /** The recipient of a pending request. */
    public function accept(User $user, Friendship $friendship): Response
    {
        return $this->allow($user, $friendship, $friendship->isPending() && $friendship->receivedBy($user));
    }

    /** The recipient of a pending request. */
    public function decline(User $user, Friendship $friendship): Response
    {
        return $this->accept($user, $friendship);
    }

    /** The sender of a pending request. */
    public function cancel(User $user, Friendship $friendship): Response
    {
        return $this->allow($user, $friendship, $friendship->isPending() && $friendship->sentBy($user));
    }

    /** Either friend. */
    public function remove(User $user, Friendship $friendship): Response
    {
        return $this->allow($user, $friendship, $friendship->isAccepted());
    }

    /** Only the one who blocked. */
    public function unblock(User $user, Friendship $friendship): Response
    {
        return $this->allow($user, $friendship, $friendship->isBlocked());
    }

    private function allow(User $user, Friendship $friendship, bool $allowed): Response
    {
        if (! $friendship->involves($user)) {
            return Response::denyAsNotFound();
        }

        if ($friendship->isBlocked() && ! $friendship->sentBy($user)) {
            return Response::denyAsNotFound();
        }

        return $allowed ? Response::allow() : Response::deny();
    }
}
