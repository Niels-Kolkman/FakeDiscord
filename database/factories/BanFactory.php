<?php

namespace Database\Factories;

use App\Models\Ban;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ban>
 */
class BanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'banned_by_id' => User::factory(),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
