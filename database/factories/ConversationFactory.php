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
     * The 1:1 DM of two users, with its direct_key and both participants.
     */
    public function between(User $a, User $b): static
    {
        return $this->state(fn () => [
            'type' => Conversation::TYPE_DIRECT,
            'name' => null,
            'owner_id' => null,
            'direct_key' => Conversation::directKey($a, $b),
        ])->afterCreating(function (Conversation $conversation) use ($a, $b): void {
            $conversation->users()->attach([$a->getKey(), $b->getKey()]);
        });
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
