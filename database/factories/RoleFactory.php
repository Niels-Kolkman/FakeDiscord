<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => ucfirst(fake()->word()),
            'color' => fake()->hexColor(),
            'permissions' => 0,
            'position' => fake()->numberBetween(1, 20),
            'is_default' => false,
        ];
    }

    /**
     * The implicit "@everyone" role every member of a server has.
     */
    public function everyone(): static
    {
        return $this->state(fn () => [
            'name' => Role::EVERYONE,
            'color' => null,
            'permissions' => Permission::everyoneDefaults(),
            'position' => 0,
            'is_default' => true,
        ]);
    }

    /**
     * Set the raw permission bitmask (flags must stay within bits 0-62).
     */
    public function withPermissions(int $bitmask): static
    {
        return $this->state(fn () => ['permissions' => $bitmask]);
    }
}
