<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
     * Discord's "Manage Channels": create, rename, reorder and delete
     * channels and categories.
     */
    public function manageChannels(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }

    /**
     * Members can leave, except the owner: they must transfer ownership
     * first, so a server is never left without an owner.
     */
    public function leave(User $user, Server $server): Response
    {
        if ($server->isOwnedBy($user)) {
            return Response::deny('Transfer ownership to another member before leaving this server.');
        }

        return $server->hasMember($user) ? Response::allow() : Response::deny();
    }

    /**
     * Hand the server to another member. Owner only, also once ISSUE-26
     * adds role permissions (Discord does the same).
     */
    public function transferOwnership(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }

    /**
     * See and revoke every invite of the server, not only your own.
     */
    public function manageInvites(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }
}
