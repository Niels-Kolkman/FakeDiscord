<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A user's read marker in a channel. By default a new user who
 * has read nothing (marker 0).
 *
 * @extends Factory<ChannelRead>
 */
class ChannelReadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel_id' => Channel::factory(),
            'last_read_message_id' => 0,
        ];
    }
}
