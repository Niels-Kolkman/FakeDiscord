<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\NotificationMute;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A muted server (ISSUE-34); forChannel() mutes one channel instead.
 *
 * @extends Factory<NotificationMute>
 */
class NotificationMuteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'server_id' => Server::factory(),
            'channel_id' => null,
        ];
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn (): array => [
            'server_id' => $channel->server_id,
            'channel_id' => $channel->id,
        ]);
    }
}
