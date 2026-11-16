<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => fake()->slug(2),
            'type' => Channel::TYPE_TEXT,
            'topic' => fake()->optional()->sentence(),
            'position' => fake()->numberBetween(0, 50),
        ];
    }

    public function voice(): static
    {
        return $this->state(fn () => ['type' => Channel::TYPE_VOICE, 'topic' => null]);
    }

    public function announcement(): static
    {
        return $this->state(fn () => ['type' => Channel::TYPE_ANNOUNCEMENT]);
    }
}
