<?php

namespace Database\Factories;

use App\Models\ChannelCategory;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelCategory>
 */
class ChannelCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => ucwords(fake()->words(2, true)),
            'position' => fake()->numberBetween(0, 20),
        ];
    }
}
