<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Works out what a member may do in a server. ServerPolicy,
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
 * - a timed-out member keeps only View Channels until the
 *   timeout runs out.
 *
 * Channel permissions start from the server permissions and
 * apply the channel's role overrides (App\Models\ChannelPermissionOverride),
 * in Discord's order:
 *
 * 1. the @everyone override: its deny flags are removed, then its allow
 *    flags added;
 * 2. a private channel removes View Channels at this point, so a private
 *    channel is hidden from everyone whatever @everyone is allowed;
 * 3. the overrides of every role the member holds, combined: all their deny
 *    flags are removed, then all their allow flags added. An allow on one
 *    role beats a deny on another, as in Discord.
 *
 * The owner and Administrator skip the overrides and see every channel.
 *
 * A timed-out member keeps only View Channels in channels too:
 * the channel overrides are applied first, then the result is cut down to
 * TIMED_OUT_MASK, so a channel that hides from them stays hidden.
 *
 * @phpstan-type Standing array{mask: int, final: bool, everyone: int|null, roles: list<int>, timedOutUntil: CarbonInterface|null}
 *
 * Nothing is cached: feature tests change roles between requests in one
 * application instance, and a stale answer would be a security bug. Each
 * call is a few small queries (membership, roles, and for channels one more
 * for the overrides).
 */
class PermissionService
{
    /**
     * The member's effective permission mask in the server.
     */
    public function permissions(User $user, Server $server): int
    {
        $standing = $this->standing($user, $server);

        // A timed-out member keeps only TIMED_OUT_MASK: reading.
        // standing() never reports a timeout for the owner or an
        // Administrator, like on Discord.
        return $standing['timedOutUntil'] === null ? $standing['mask'] : $standing['mask'] & self::TIMED_OUT_MASK;
    }

    /**
     * What a timed-out member keeps: reading the channels they could see.
     */
    public const TIMED_OUT_MASK = Permission::ViewChannels->value;

    /**
     * Is the member timed out in the server right now? Never true
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
        return $this->standing($user, $server)['timedOutUntil'];
    }

    /**
     * May $actor kick, ban or time out $target in the server?
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

        // $target is a member but not the owner here, so a final standing is
        // an Administrator.
        if ($permission === Permission::TimeoutMembers && $this->standing($target, $server)['final']) {
            return Response::deny('Administrators cannot be timed out.');
        }

        return Response::allow();
    }

    public function has(User $user, Server $server, Permission $permission): bool
    {
        return $permission->in($this->permissions($user, $server));
    }

    /**
     * The member's effective permission mask in a channel of the server:
     * the server permissions with the channel's overrides applied (see the
     * class comment).
     */
    public function channelPermissions(User $user, Channel $channel): int
    {
        $standing = $this->standing($user, $channel->server);

        if ($standing['final']) {
            return $standing['mask'];
        }

        $overrides = $this->overrides([(int) $channel->getKey()], $standing)->get((int) $channel->getKey(), collect());

        return $this->applyOverrides($standing, $channel, $overrides);
    }

    public function hasInChannel(User $user, Channel $channel, Permission $permission): bool
    {
        return $permission->in($this->channelPermissions($user, $channel));
    }

    /**
     * The channels of $server in $channels that the user can see
     * (ChannelPolicy::view), in the same order. Works the server
     * permissions out once and loads the overrides of all the channels in
     * one query, instead of asking once per channel.
     *
     * @param  EloquentCollection<int, Channel>  $channels
     * @return EloquentCollection<int, Channel>
     */
    public function visibleChannels(User $user, Server $server, EloquentCollection $channels): EloquentCollection
    {
        $channels = $channels
            ->filter(fn (Channel $channel): bool => (int) $channel->server_id === (int) $server->getKey())
            ->values();
        $standing = $this->standing($user, $server);

        if ($standing['final']) {
            return Permission::ViewChannels->in($standing['mask']) ? $channels : $channels->take(0);
        }

        $overrides = $this->overrides(array_map('intval', $channels->modelKeys()), $standing);

        return $channels
            ->filter(fn (Channel $channel): bool => Permission::ViewChannels->in(
                $this->applyOverrides($standing, $channel, $overrides->get((int) $channel->getKey(), collect())),
            ))
            ->values();
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
     * Where the user stands in the server: their server permission mask,
     * whether channel overrides can still change it ('final' is true for
     * the owner, Administrator and non-members), and the ids of the
     *
     * @everyone role and the other roles they hold, for the overrides.
     *
     * The mask is the flags of @everyone OR-ed with those of every role the
     * user holds. A server without an @everyone row (older data) counts as
     * having the defaults, which Server::defaultRole() would create.
     *
     * 'timedOutUntil' is when an active timeout ends, null for
     * members who are not timed out and for the owner and Administrators,
     * whom a timeout does not touch. It does not change 'mask': permissions()
     * and applyOverrides() cut it down to TIMED_OUT_MASK.
     *
     * @return Standing
     */
    private function standing(User $user, Server $server): array
    {
        if ($server->isOwnedBy($user)) {
            return ['mask' => Permission::all(), 'final' => true, 'everyone' => null, 'roles' => [], 'timedOutUntil' => null];
        }

        $membership = $server->membershipOf($user);

        if ($membership === null) {
            return ['mask' => 0, 'final' => true, 'everyone' => null, 'roles' => [], 'timedOutUntil' => null];
        }

        $roles = Role::query()
            ->where('server_id', $server->getKey())
            ->where(fn (Builder $query) => $query
                ->where('is_default', true)
                ->orWhereHas('users', fn (Builder $users) => $users->whereKey($user->getKey())))
            ->get(['id', 'permissions', 'is_default']);

        $everyone = $roles->firstWhere('is_default', true);
        $mask = $everyone === null ? Permission::everyoneDefaults() : 0;

        foreach ($roles as $role) {
            $mask |= (int) $role->permissions;
        }

        if (Permission::Administrator->in($mask)) {
            return ['mask' => Permission::all(), 'final' => true, 'everyone' => null, 'roles' => [], 'timedOutUntil' => null];
        }

        return [
            'mask' => $mask,
            'final' => false,
            'everyone' => $everyone === null ? null : (int) $everyone->getKey(),
            'roles' => $roles->where('is_default', false)->map(fn (Role $role): int => (int) $role->getKey())->values()->all(),
            'timedOutUntil' => $membership->isTimedOut() ? $membership->timed_out_until : null,
        ];
    }

    /**
     * The overrides of the given channels for the user's roles, grouped by
     * channel id and keyed by role id.
     *
     * @param  list<int>  $channelIds
     * @param  Standing  $standing
     * @return Collection<int, Collection<int, ChannelPermissionOverride>>
     */
    private function overrides(array $channelIds, array $standing): Collection
    {
        $roleIds = $standing['roles'];
        if ($standing['everyone'] !== null) {
            $roleIds[] = $standing['everyone'];
        }

        if ($channelIds === [] || $roleIds === []) {
            return collect();
        }

        $rows = ChannelPermissionOverride::query()
            ->whereIn('channel_id', $channelIds)
            ->whereIn('role_id', $roleIds)
            ->get(['channel_id', 'role_id', 'allow', 'deny']);

        /** @var array<int, array<int, ChannelPermissionOverride>> $byChannel */
        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[(int) $row->channel_id][(int) $row->role_id] = $row;
        }

        return collect($byChannel)->map(fn (array $byRole): Collection => collect($byRole));
    }

    /**
     * @param  Standing  $standing
     * @param  Collection<int, ChannelPermissionOverride>  $overrides  keyed by role id
     */
    private function applyOverrides(array $standing, Channel $channel, Collection $overrides): int
    {
        $mask = $standing['mask'];

        $everyone = $standing['everyone'] === null ? null : $overrides->get($standing['everyone']);
        if ($everyone !== null) {
            $mask = ($mask & ~(int) $everyone->deny) | (int) $everyone->allow;
        }

        if ($channel->is_private) {
            $mask &= ~Permission::ViewChannels->value;
        }

        $allow = 0;
        $deny = 0;
        foreach ($standing['roles'] as $roleId) {
            $override = $overrides->get($roleId);
            if ($override !== null) {
                $allow |= (int) $override->allow;
                $deny |= (int) $override->deny;
            }
        }

        $mask = ($mask & ~$deny) | $allow;

        // A timed-out member only keeps reading, whatever the
        // channel allows.
        return $standing['timedOutUntil'] === null ? $mask : $mask & self::TIMED_OUT_MASK;
    }
}
