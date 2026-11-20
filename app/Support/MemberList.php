<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The right-hand member sidebar of a server (built by Server::memberList()),
 * grouped like Discord's:
 *
 * - online members go under their highest role (highest position, then
 *   lowest id), with the role groups in hierarchy order;
 * - online members with no role besides @everyone go under "Online";
 * - every offline member goes under "Offline", whatever their roles.
 *
 * Who is online: with $connectedUserIds (from
 * App\Support\ServerPresence) a member is online when they are connected,
 * shown with the status they picked (online, idle or dnd). Without it
 * (null: presence unknown, e.g. no websocket broadcaster) the stored
 * status alone decides, as before. statusOf() gives the status to show.
 *
 * Empty groups are left out of $groups; $allGroups has every possible
 * group, empty or not, in the same order, so the browser can move members
 * between them (resources/js/presence.js). Members are sorted by display
 * name (nickname, else name), case-insensitively. Each member has its
 * `user` relation loaded and ServerMember::highestRole() returns their
 * highest role, or null.
 */
final readonly class MemberList
{
    /**
     * @param  list<MemberGroup>  $groups
     * @param  array<int, string>  $statuses  user id => "online", "idle", "dnd" or "offline"
     * @param  list<MemberGroup>  $allGroups
     */
    public function __construct(
        public array $groups,
        public int $total,
        public int $online,
        public array $statuses = [],
        public array $allGroups = [],
    ) {}

    /**
     * @param  list<int>|null  $connectedUserIds
     */
    public static function for(Server $server, ?array $connectedUserIds = null): self
    {
        $members = $server->memberships()->with('user')->get();

        // Hierarchy order, so the first role a member holds is their highest.
        $roles = $server->roles()
            ->where('is_default', false)
            ->orderByDesc('position')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, list<int>> $roleIdsByUser */
        $roleIdsByUser = DB::table('role_user')
            ->whereIn('role_id', $roles->modelKeys())
            ->get(['role_id', 'user_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (object $row): int => (int) $row->role_id)->all());

        foreach ($members as $member) {
            $held = $roleIdsByUser->get($member->user_id, []);
            $member->setHighestRole($roles->first(fn (Role $role): bool => in_array($role->id, $held, true)));
        }

        $connected = $connectedUserIds === null ? null : array_flip($connectedUserIds);

        $statuses = [];
        foreach ($members as $member) {
            $statuses[(int) $member->user_id] = self::statusFor($member->user, $connected);
        }

        $sorted = $members->toBase()
            ->sortBy(fn (ServerMember $member): string => mb_strtolower($member->displayName()))
            ->values();

        [$online, $offline] = $sorted->partition(fn (ServerMember $member): bool => $statuses[(int) $member->user_id] !== 'offline');

        $allGroups = [];

        foreach ($roles as $role) {
            $allGroups[] = new MemberGroup(
                "role-{$role->id}",
                $role->name,
                $online->filter(fn (ServerMember $member): bool => $member->highestRole()?->is($role) ?? false)->values(),
                $role->color,
            );
        }

        $allGroups[] = new MemberGroup(
            MemberGroup::ONLINE,
            'Online',
            $online->filter(fn (ServerMember $member): bool => $member->highestRole() === null)->values(),
        );
        $allGroups[] = new MemberGroup(MemberGroup::OFFLINE, 'Offline', $offline->values());

        $groups = array_values(array_filter($allGroups, fn (MemberGroup $group): bool => $group->members->isNotEmpty()));

        return new self($groups, $sorted->count(), $online->count(), $statuses, $allGroups);
    }

    /**
     * "online", "idle", "dnd" or "offline".
     */
    public function statusOf(ServerMember $member): string
    {
        return $this->statuses[(int) $member->user_id] ?? 'offline';
    }

    /**
     * @param  array<int, int>|null  $connected  user id => anything, or null when unknown
     */
    private static function statusFor(User $user, ?array $connected): string
    {
        if ($connected === null) {
            return $user->isOnline() ? (string) $user->status : 'offline';
        }

        return isset($connected[(int) $user->getKey()]) ? $user->chosenStatus() : 'offline';
    }
}
