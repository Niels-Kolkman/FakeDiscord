<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
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
 * Empty groups are left out. Members are sorted by display name (nickname,
 * else name), case-insensitively. Each member has its `user` relation loaded
 * and ServerMember::highestRole() returns their highest role, or null.
 */
final readonly class MemberList
{
    /**
     * @param  list<MemberGroup>  $groups
     */
    public function __construct(
        public array $groups,
        public int $total,
        public int $online,
    ) {}

    public static function for(Server $server): self
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

        $sorted = $members->toBase()
            ->sortBy(fn (ServerMember $member): string => mb_strtolower($member->displayName()))
            ->values();

        [$online, $offline] = $sorted->partition(fn (ServerMember $member): bool => $member->user->isOnline());

        $groups = [];

        foreach ($roles as $role) {
            $inRole = $online->filter(fn (ServerMember $member): bool => $member->highestRole()?->is($role) ?? false);
            if ($inRole->isNotEmpty()) {
                $groups[] = new MemberGroup("role-{$role->id}", $role->name, $inRole->values());
            }
        }

        $roleless = $online->filter(fn (ServerMember $member): bool => $member->highestRole() === null);
        if ($roleless->isNotEmpty()) {
            $groups[] = new MemberGroup(MemberGroup::ONLINE, 'Online', $roleless->values());
        }

        if ($offline->isNotEmpty()) {
            $groups[] = new MemberGroup(MemberGroup::OFFLINE, 'Offline', $offline->values());
        }

        return new self($groups, $sorted->count(), $online->count());
    }
}
