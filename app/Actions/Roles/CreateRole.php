<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a role at the top of the server's hierarchy, or, with $below, just
 * under the role at that position (ISSUE-27: a member who is not the owner
 * creates roles right below their own highest role, so they can still
 * manage them). The roles from $below up move one place up.
 */
class CreateRole
{
    /**
     * @param  int|null  $below  the position of the role to go under; null for the top
     *
     * @throws ValidationException when the server already has the maximum number of roles, or $below is below 1
     */
    public function create(Server $server, string $name, ?string $color, int $permissions, ?int $below = null): Role
    {
        return DB::transaction(function () use ($server, $name, $color, $permissions, $below): Role {
            // Lock the server row so two parallel creates cannot both get the
            // same position or both take the last free slot.
            Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if ($server->roles()->count() >= Role::MAX_PER_SERVER) {
                throw ValidationException::withMessages([
                    'name' => 'A server can have at most '.Role::MAX_PER_SERVER.' roles.',
                ]);
            }

            if ($below !== null) {
                // Only @everyone is lower: the new role would be above
                // the member who creates it.
                if ($below < 1) {
                    throw ValidationException::withMessages([
                        'name' => 'You need a role of your own to create roles below it.',
                    ]);
                }

                $server->roles()->getQuery()
                    ->where('is_default', false)
                    ->where('position', '>=', $below)
                    ->increment('position');

                $position = $below;
            } else {
                $max = $server->roles()->getQuery()->max('position');
                $position = $max === null ? 1 : max(1, (int) $max + 1);
            }

            return $server->roles()->create([
                'name' => $name,
                'color' => $color,
                'permissions' => $permissions,
                'position' => $position,
                'is_default' => false,
            ]);
        });
    }
}
