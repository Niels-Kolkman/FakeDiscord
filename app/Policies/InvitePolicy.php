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
     * Members who manage the server's invites (ServerPolicy::manageInvites:
     * the owner and Manage Server, ISSUE-27), and the person who made the
     * invite while they are still a member, can revoke it.
     */
    public function delete(User $user, Invite $invite): bool
    {
        $server = $invite->server;

        if ($user->can('manageInvites', $server)) {
            return true;
        }

        return (int) $invite->inviter_id === (int) $user->getKey() && $server->hasMember($user);
    }
}
