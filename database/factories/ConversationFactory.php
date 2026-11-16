<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates only the conversation row; add participants with
 * ConversationParticipant::factory() or $conversation->users()->attach().
 *
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Conversation::TYPE_DIRECT,
            'name' => null,
            'owner_id' => null,
        ];
    }

    /**
     * A named group DM with an owner.
     */
    public function group(): static
    {
        return $this->state(fn () => [
            'type' => Conversation::TYPE_GROUP,
            'name' => ucwords(fake()->words(2, true)),
            'owner_id' => User::factory(),
        ]);
    }
}
