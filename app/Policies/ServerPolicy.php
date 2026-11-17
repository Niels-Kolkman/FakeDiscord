<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Discovered automatically for App\Models\Server.
 *
 * Until role permissions exist (ISSUE-26) only the owner may change a server.
 */
class ServerPolicy
{
    /**
     * Any signed-in user with a verified email address can create servers.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Members (including the owner) can open a server.
     */
    public function view(User $user, Server $server): bool
    {
        return $server->hasMember($user);
    }

    /**
     * Rename the server or change its icon.
     */
    public function update(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }

    public function delete(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }

    /**
     * Like Discord's default @everyone permission, any member can invite.
     */
    public function createInvite(User $user, Server $server): bool
    {
        return $server->hasMember($user);
    }

    /**
     * See and revoke every invite of the server, not only your own.
     */
    public function manageInvites(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }
}
