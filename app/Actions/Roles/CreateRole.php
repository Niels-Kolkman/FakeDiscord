<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a role at the top of the server's hierarchy.
 */
class CreateRole
{
    /**
     * @throws ValidationException when the server already has the maximum number of roles
     */
    public function create(Server $server, string $name, ?string $color, int $permissions): Role
    {
        return DB::transaction(function () use ($server, $name, $color, $permissions): Role {
            // Lock the server row so two parallel creates cannot both get the
            // same position or both take the last free slot.
            Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if ($server->roles()->count() >= Role::MAX_PER_SERVER) {
                throw ValidationException::withMessages([
                    'name' => 'A server can have at most '.Role::MAX_PER_SERVER.' roles.',
                ]);
            }

            $max = $server->roles()->getQuery()->max('position');

            return $server->roles()->create([
                'name' => $name,
                'color' => $color,
                'permissions' => $permissions,
                'position' => $max === null ? 1 : max(1, (int) $max + 1),
                'is_default' => false,
            ]);
        });
    }
}
