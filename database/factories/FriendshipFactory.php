<?php

namespace Database\Factories;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Friendship>
 */
class FriendshipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'friend_id' => User::factory(),
            'status' => Friendship::STATUS_PENDING,
            'accepted_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => Friendship::STATUS_ACCEPTED,
            'accepted_at' => fake()->dateTimeBetween('-6 months'),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => Friendship::STATUS_BLOCKED,
            'accepted_at' => null,
        ]);
    }
}
