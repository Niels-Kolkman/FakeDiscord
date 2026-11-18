<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Works out what a member may do in a server (ISSUE-27). ServerPolicy,
 * ChannelPolicy, MessagePolicy, RolePolicy and InvitePolicy ask this
 * service; nothing else reads roles.permissions to make a decision.
 *
 * Effective server permissions, like Discord:
 *
 * - someone who is not a member has none (0);
 * - the owner has every permission (Permission::all()), whatever their roles;
 * - everyone else has the @everyone role's flags OR-ed with the flags of
 *   every role they hold;
 * - Administrator in that mask turns into every permission.
 *
 * Channel permissions are the server permissions for now. ISSUE-28 adds
 * per-channel role overrides in channelPermissions(); callers that ask
 * about a channel already go through it.
 *
 * Nothing is cached: feature tests change roles between requests in one
 * application instance, and a stale answer would be a security bug. Each
 * call is two small queries.
 */
class PermissionService
{
    /**
     * The member's effective permission mask in the server.
     */
    public function permissions(User $user, Server $server): int
    {
        if ($server->isOwnedBy($user)) {
            return Permission::all();
        }

        if (! $server->hasMember($user)) {
            return 0;
        }

        $mask = $this->roleMask($user, $server);

        return Permission::Administrator->in($mask) ? Permission::all() : $mask;
    }

    public function has(User $user, Server $server, Permission $permission): bool
    {
        return $permission->in($this->permissions($user, $server));
    }

    /**
     * The member's effective permission mask in a channel of the server.
     * The same as permissions() until ISSUE-28 adds channel overrides.
     */
    public function channelPermissions(User $user, Channel $channel): int
    {
        return $this->permissions($user, $channel->server);
    }

    public function hasInChannel(User $user, Channel $channel, Permission $permission): bool
    {
        return $permission->in($this->channelPermissions($user, $channel));
    }

    /**
     * The channels of $server in $channels that the user can see
     * (ChannelPolicy::view), in the same order. Works the permissions out
     * once instead of once per channel; ISSUE-28 applies channel overrides
     * here.
     *
     * @param  EloquentCollection<int, Channel>  $channels
     * @return EloquentCollection<int, Channel>
     */
    public function visibleChannels(User $user, Server $server, EloquentCollection $channels): EloquentCollection
    {
        return Permission::ViewChannels->in($this->permissions($user, $server))
            ? $channels->values()
            : $channels->take(0);
    }

    /**
     * Where the member stands in the role hierarchy: PHP_INT_MAX for the
     * owner, the position of their highest role for a member (0 when they
     * only have @everyone), -1 for someone who is not a member.
     */
    public function highestPosition(User $user, Server $server): int
    {
        if ($server->isOwnedBy($user)) {
            return PHP_INT_MAX;
        }

        if (! $server->hasMember($user)) {
            return -1;
        }

        $position = $user->roles()
            ->where('roles.server_id', $server->getKey())
            ->where('roles.is_default', false)
            ->max('roles.position');

        return $position === null ? 0 : (int) $position;
    }

    /**
     * Can the user edit, delete, move or hand out this role? The owner can
     * manage every role. Anyone else needs Manage Roles, and the role must
     * be below their own highest role; @everyone is below everyone.
     * Administrator gives Manage Roles but does not lift the hierarchy.
     */
    public function canManageRole(User $user, Role $role): bool
    {
        $server = $role->server;

        if (! $this->has($user, $server, Permission::ManageRoles)) {
            return false;
        }

        if ($server->isOwnedBy($user) || $role->is_default) {
            return true;
        }

        return (int) $role->position < $this->highestPosition($user, $server);
    }

    /**
     * The flags of the default role (@everyone) OR-ed with those of every
     * role the user holds in the server. A server without an @everyone row (older data) counts as
     * having the defaults, which Server::defaultRole() would create.
     */
    private function roleMask(User $user, Server $server): int
    {
        $roles = Role::query()
            ->where('server_id', $server->getKey())
            ->where(fn (Builder $query) => $query
                ->where('is_default', true)
                ->orWhereHas('users', fn (Builder $users) => $users->whereKey($user->getKey())))
            ->get(['permissions', 'is_default']);

        $mask = $roles->contains('is_default', true) ? 0 : Permission::everyoneDefaults();

        foreach ($roles as $role) {
            $mask |= (int) $role->permissions;
        }

        return $mask;
    }
}
