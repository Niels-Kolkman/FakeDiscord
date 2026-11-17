<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates only the server row. Owner membership, the @everyone role and
 * channels are not created automatically; see DemoSeeder for a full setup.
 *
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => ucwords(fake()->words(fake()->numberBetween(1, 3), true)),
            'description' => fake()->optional()->sentence(),
            'icon_path' => null,
        ];
    }
}
