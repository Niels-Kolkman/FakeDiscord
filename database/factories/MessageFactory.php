<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a channel message. Use inConversation() for a DM; that state
 * clears channel_id so exactly one of the two targets is set.
 *
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'conversation_id' => null,
            'user_id' => User::factory(),
            'reply_to_id' => null,
            'content' => fake()->realTextBetween(10, 200),
            'edited_at' => null,
        ];
    }

    public function inChannel(Channel $channel): static
    {
        return $this->state(fn () => [
            'channel_id' => $channel->id,
            'conversation_id' => null,
        ]);
    }

    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn () => [
            'channel_id' => null,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * A reply in the same channel / conversation as the original.
     */
    public function replyTo(Message $message): static
    {
        return $this->state(fn () => [
            'channel_id' => $message->channel_id,
            'conversation_id' => $message->conversation_id,
            'reply_to_id' => $message->id,
        ]);
    }

    public function edited(): static
    {
        return $this->state(fn () => ['edited_at' => now()]);
    }
}
