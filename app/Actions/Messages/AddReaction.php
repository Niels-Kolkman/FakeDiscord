<?php

namespace App\Actions\Messages;

use App\Events\ReactionsUpdated;
use App\Models\Message;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Validation\ValidationException;

/**
 * React to a message with an emoji and broadcast the new counts to the
 * others viewing the channel (toOthers(): the reactor gets them in the
 * HTTP response).
 *
 * Reacting twice with the same emoji changes nothing: the unique
 * (message_id, emoji, user_id) index makes createOrFirst() find the
 * existing row, also when two requests race. Then there is no broadcast.
 *
 * A message holds at most Reactions::MAX_PER_MESSAGE different emoji.
 */
class AddReaction
{
    /**
     * @return bool whether a reaction was added
     *
     * @throws ValidationException
     */
    public function add(User $user, Message $message, string $emoji): bool
    {
        $distinct = $message->reactions()->distinct()->pluck('emoji');

        if (! $distinct->contains($emoji) && $distinct->count() >= Reactions::MAX_PER_MESSAGE) {
            throw ValidationException::withMessages([
                'emoji' => __('A message can have at most :max different reactions.', ['max' => Reactions::MAX_PER_MESSAGE]),
            ]);
        }

        $reaction = $message->reactions()->createOrFirst([
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        $message->load(Reactions::relations());

        if (! $reaction->wasRecentlyCreated) {
            return false;
        }

        // Saved either way; if Reverb is down, report it and carry on.
        rescue(function () use ($message, $user, $emoji): void {
            broadcast(new ReactionsUpdated($message, (int) $user->id, $emoji, added: true))->toOthers();
        });

        return true;
    }
}
