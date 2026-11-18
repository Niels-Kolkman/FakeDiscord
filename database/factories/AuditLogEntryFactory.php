<?php

namespace Database\Factories;

use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLogEntry>
 */
class AuditLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'server_id' => Server::factory(),
            'actor_id' => User::factory(),
            'action' => AuditLogEntry::CHANNEL_UPDATE,
            'target_type' => 'channel',
            'target_id' => fake()->numberBetween(1, 1000),
            'target_label' => $name,
            'details' => ['name' => ['old' => 'old-'.$name, 'new' => $name]],
            'reason' => null,
        ];
    }
}
