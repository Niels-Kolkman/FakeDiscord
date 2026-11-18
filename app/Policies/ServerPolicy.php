<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Auth\Access\Response;

/**
 * Discovered automatically for App\Models\Server.
 *
 * Server-wide abilities, decided by the member's effective permissions
 * (App\Services\PermissionService, ISSUE-27): the owner can do everything,
 * Administrator gives every permission, and everyone else has what their
 * roles and the default role (@everyone) give them. Deleting the server and
 * handing it to someone else stay owner only, like on Discord.
 *
 * What happens inside one channel (reading, posting, attaching, reacting,
 * moderating messages) is App\Policies\ChannelPolicy.
 */
class ServerPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Any signed-in user with a verified email address can create servers.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Members (including the owner) can open a server. Which channels they
     * see is ChannelPolicy::view.
     */
    public function view(User $user, Server $server): bool
    {
        return $server->hasMember($user);
    }

    /**
     * Rename the server or change its icon: Manage Server.
     */
    public function update(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::ManageServer);
    }

    /**
     * Owner only, also for administrators (Discord does the same).
     */
    public function delete(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }

    /**
     * Create Invite (an @everyone default).
     */
    public function createInvite(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::CreateInvite);
    }

    /**
     * See and revoke every invite of the server, not only your own: Manage
     * Server, like Discord's invite settings.
     */
    public function manageInvites(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::ManageServer);
    }

    /**
     * Send Messages anywhere in the server (an @everyone default). Posting
     * in one channel is ChannelPolicy::sendMessages, which also needs View
     * Channels.
     */
    public function sendMessages(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::SendMessages);
    }

    /**
     * Manage Messages: delete and pin other members' messages. Per channel
     * this is ChannelPolicy::manageMessages.
     */
    public function manageMessages(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::ManageMessages);
    }

    /**
     * Manage Channels: create, rename, reorder and delete channels and
     * categories.
     */
    public function manageChannels(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::ManageChannels);
    }

    /**
     * Manage Roles: open the role settings and create roles. Which existing
     * roles a member may change, move or hand out is RolePolicy::manage
     * (only roles below their own highest role).
     */
    public function manageRoles(User $user, Server $server): bool
    {
        return $this->permissions->has($user, $server, Permission::ManageRoles);
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
     * Hand the server to another member. Owner only, also for
     * administrators (Discord does the same).
     */
    public function transferOwnership(User $user, Server $server): bool
    {
        return $server->isOwnedBy($user);
    }
}
