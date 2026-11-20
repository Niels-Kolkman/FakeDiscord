<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Discovered automatically for App\Models\Role.
 *
 * Opening the role settings and creating roles is ServerPolicy::manageRoles.
 * This decides which existing role a member may edit, delete, move or give
 * to and take from members: like Discord, only roles below their own
 * highest role (PermissionService::canManageRole). The owner manages every
 * role, and @everyone is below everyone with Manage Roles.
 */
class RolePolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function manage(User $user, Role $role): bool
    {
        return $this->permissions->canManageRole($user, $role);
    }
}
