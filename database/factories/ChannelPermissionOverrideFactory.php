<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A role override in a channel. By default a new role of the
 * channel's server that may not send messages there.
 *
 * @extends Factory<ChannelPermissionOverride>
 */
class ChannelPermissionOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            // The role must belong to the channel's server.
            'role_id' => fn (array $attributes) => Role::factory()->create([
                'server_id' => Channel::query()->findOrFail($attributes['channel_id'])->server_id,
            ]),
            'allow' => 0,
            'deny' => Permission::SendMessages->value,
        ];
    }

    public function allowing(Permission ...$permissions): static
    {
        return $this->state(fn () => ['allow' => Permission::mask(...$permissions)]);
    }

    public function denying(Permission ...$permissions): static
    {
        return $this->state(fn () => ['deny' => Permission::mask(...$permissions)]);
    }
}
