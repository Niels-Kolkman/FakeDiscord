<?php

namespace Tests\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;

/**
 * Helpers for permission tests (ISSUE-27): members with roles, and changes
 * to @everyone.
 */
trait GivesPermissions
{
    protected function joinServer(Server $server, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);

        return $user;
    }

    /**
     * A role at $position (or at the top) with exactly these flags.
     */
    protected function makeRole(Server $server, string $name, int $permissions, ?int $position = null): Role
    {
        $position ??= ((int) $server->roles()->max('position')) + 1;

        return $server->roles()->create([
            'name' => $name,
            'permissions' => $permissions,
            'position' => $position,
            'is_default' => false,
        ]);
    }

    protected function giveRole(User $user, Role $role): void
    {
        $role->users()->attach($user->id);
    }

    /**
     * A new member of $server holding a fresh role with $permissions.
     */
    protected function memberWith(Server $server, Permission ...$permissions): User
    {
        $user = $this->joinServer($server);
        $name = 'Has '.implode(', ', array_map(fn (Permission $permission): string => $permission->name, $permissions));
        $this->giveRole($user, $this->makeRole($server, $name, Permission::mask(...$permissions)));

        return $user;
    }

    /**
     * Turn flags off for @everyone.
     */
    protected function revokeFromEveryone(Server $server, Permission ...$permissions): void
    {
        $everyone = $server->defaultRole();
        $everyone->update(['permissions' => (int) $everyone->permissions & ~Permission::mask(...$permissions)]);
    }
}
