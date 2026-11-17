<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerMember>
 */
class ServerMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'nickname' => fake()->optional(0.2)->regexify('[A-Za-z][A-Za-z0-9_]{3,15}'),
            'joined_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
