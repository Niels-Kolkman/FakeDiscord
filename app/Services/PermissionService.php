<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\Response;
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
 * - Administrator in that mask turns into every permission;
 * - a timed-out member (ISSUE-29) keeps only View Channels until the
 *   timeout runs out.
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

        $membership = $server->membershipOf($user);

        if ($membership === null) {
            return 0;
        }

        $mask = $this->roleMask($user, $server);

        if (Permission::Administrator->in($mask)) {
            return Permission::all();
        }

        // A timed-out member (ISSUE-29) keeps only TIMED_OUT_MASK: reading.
        // Administrators cannot be timed out, like on Discord.
        return $membership->isTimedOut() ? $mask & self::TIMED_OUT_MASK : $mask;
    }

    /**
     * What a timed-out member keeps: reading the channels they could see.
     */
    public const TIMED_OUT_MASK = Permission::ViewChannels->value;

    /**
     * Is the member timed out in the server right now (ISSUE-29)? Never true
     * for the owner or an Administrator, whose permissions a timeout does not
     * touch.
     */
    public function isTimedOut(User $user, Server $server): bool
    {
        return $this->timedOutUntil($user, $server) !== null;
    }

    /**
     * When the member's timeout runs out, or null when they are not timed
     * out (see isTimedOut()).
     */
    public function timedOutUntil(User $user, Server $server): ?CarbonInterface
    {
        if ($server->isOwnedBy($user)) {
            return null;
        }

        $membership = $server->membershipOf($user);

        if ($membership === null || ! $membership->isTimedOut()
            || Permission::Administrator->in($this->roleMask($user, $server))) {
            return null;
        }

        return $membership->timed_out_until;
    }

    /**
     * May $actor kick, ban or time out $target in the server (ISSUE-29)?
     * Like Discord:
     *
     * - $actor needs $permission (Kick, Ban or Timeout Members);
     * - $target must be a member (a 404 otherwise), and not $actor;
     * - nobody can act on the owner;
     * - anyone but the owner can only act on members whose highest role is
     *   below their own (Administrator does not lift this);
     * - Administrators cannot be timed out.
     *
     * The denial message says why.
     */
    public function moderationCheck(User $actor, User $target, Server $server, Permission $permission): Response
    {
        if (! $this->has($actor, $server, $permission)) {
            return Response::deny("You need the {$permission->label()} permission.");
        }

        if (! $server->hasMember($target)) {
            return Response::denyAsNotFound('This user is not a member of the server.');
        }

        if ((int) $actor->getKey() === (int) $target->getKey()) {
            return Response::deny('You cannot do this to yourself.');
        }

        if ($server->isOwnedBy($target)) {
            return Response::deny('The server owner cannot be kicked, banned or timed out.');
        }

        if ($this->highestPosition($target, $server) >= $this->highestPosition($actor, $server)) {
            return Response::deny('You can only do this to members whose highest role is below yours.');
        }

        if ($permission === Permission::TimeoutMembers
            && Permission::Administrator->in($this->roleMask($target, $server))) {
            return Response::deny('Administrators cannot be timed out.');
        }

        return Response::allow();
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
