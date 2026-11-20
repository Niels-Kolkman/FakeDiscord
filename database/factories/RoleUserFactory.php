<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A role held by a user. Usually given with $role->users()->attach() or
 * App\Actions\Roles\AssignRole instead.
 *
 * @extends Factory<RoleUser>
 */
class RoleUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'user_id' => User::factory(),
        ];
    }
}
