<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a role to a member of its server, or takes it away. The default
 * role (@everyone) cannot be given or taken: every member has it implicitly.
 */
class AssignRole
{
    /**
     * @return bool false when the member already had the role
     *
     * @throws ValidationException when the role is @everyone or the user is not a member
     */
    public function assign(Role $role, User $user): bool
    {
        $this->guardDefault($role);

        return DB::transaction(function () use ($role, $user): bool {
            // Lock the membership so the user cannot leave halfway and keep
            // a role_user row on a server they are no longer in.
            $isMember = DB::table('server_members')
                ->where('server_id', $role->server_id)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $isMember) {
                throw ValidationException::withMessages([
                    'user_id' => 'Only members of this server can be given a role.',
                ]);
            }

            $inserted = DB::table('role_user')->insertOrIgnore([
                'role_id' => $role->getKey(),
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $inserted > 0;
        });
    }

    /**
     * @return bool false when the member did not have the role
     *
     * @throws ValidationException when the role is @everyone
     */
    public function remove(Role $role, User $user): bool
    {
        $this->guardDefault($role);

        return $role->users()->detach($user->getKey()) > 0;
    }

    private function guardDefault(Role $role): void
    {
        if ($role->is_default) {
            throw ValidationException::withMessages([
                'user_id' => 'Every member has the '.Role::EVERYONE.' role.',
            ]);
        }
    }
}
