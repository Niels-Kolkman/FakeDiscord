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
            // No nickname by default: a random one would change the display
            // name that sorting and "assertSee" tests rely on, and fail them
            // at random. Use withNickname() when a test wants one.
            'nickname' => null,
            'joined_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }

    public function withNickname(?string $nickname = null): static
    {
        return $this->state(fn (): array => [
            'nickname' => $nickname ?? fake()->regexify('[A-Za-z][A-Za-z0-9_]{3,15}'),
        ]);
    }
}
