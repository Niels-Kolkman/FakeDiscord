<?php

namespace Database\Factories;

use App\Models\Invite;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Za-z0-9]{8}'),
            'server_id' => Server::factory(),
            'channel_id' => null,
            'inviter_id' => User::factory(),
            'max_uses' => null,
            'uses' => 0,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    /**
     * An invite that has been used up.
     */
    public function exhausted(int $maxUses = 5): static
    {
        return $this->state(fn () => ['max_uses' => $maxUses, 'uses' => $maxUses]);
    }
}
