<?php

namespace App\Policies;

use App\Models\Invite;
use App\Models\User;

/**
 * Discovered automatically for App\Models\Invite.
 */
class InvitePolicy
{
    /**
     * The server owner, and the person who made the invite while they are
     * still a member, can revoke it.
     */
    public function delete(User $user, Invite $invite): bool
    {
        $server = $invite->server;

        if ($server->isOwnedBy($user)) {
            return true;
        }

        return (int) $invite->inviter_id === (int) $user->getKey() && $server->hasMember($user);
    }
}
