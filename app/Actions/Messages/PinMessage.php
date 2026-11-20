<?php

namespace App\Actions\Messages;

use App\Events\MessagePinned;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pin a message to its channel, or unpin it, and tell everyone
 * else viewing the channel (toOthers(): the moderator gets the message in
 * the HTTP response).
 *
 * Pinning a pinned message, or unpinning one that is not pinned, changes
 * nothing and broadcasts nothing. The UPDATE only matches a row in the
 * old state, so two moderators clicking at once give one change and one
 * broadcast. A channel holds at most Message::MAX_PINS_PER_CHANNEL pins,
 * like Discord; deleted messages do not count.
 */
class PinMessage
{
    /**
     * @return bool whether the message was pinned now
     *
     * @throws ValidationException
     */
    public function pin(User $user, Message $message): bool
    {
        if ($message->isPinned()) {
            return false;
        }

        $now = now();

        $updated = DB::transaction(function () use ($user, $message, $now): int {
            $count = Message::query()
                ->where('channel_id', $message->channel_id)
                ->whereNotNull('pinned_at')
                ->count();

            if ($count >= Message::MAX_PINS_PER_CHANNEL) {
                throw ValidationException::withMessages([
                    'message' => __('A channel can have at most :max pinned messages. Unpin one first.', ['max' => Message::MAX_PINS_PER_CHANNEL]),
                ]);
            }

            return Message::query()
                ->whereKey($message->id)
                ->whereNull('pinned_at')
                ->update(['pinned_at' => $now, 'pinned_by_id' => $user->id]);
        });

        if ($updated === 0) {
            $message->refresh();

            return false;
        }

        $message->forceFill(['pinned_at' => $now, 'pinned_by_id' => $user->id])->syncOriginal();
        $message->setRelation('pinnedBy', $user);
        $this->broadcast($message);

        return true;
    }

    /**
     * @return bool whether the message was unpinned now
     */
    public function unpin(Message $message): bool
    {
        $updated = Message::query()
            ->whereKey($message->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->forceFill(['pinned_at' => null, 'pinned_by_id' => null])->syncOriginal();
        $message->setRelation('pinnedBy', null);

        if ($updated === 0) {
            return false;
        }

        $this->broadcast($message);

        return true;
    }

    private function broadcast(Message $message): void
    {
        // Saved either way; if Reverb is down, report it and carry on.
        rescue(function () use ($message): void {
            broadcast(new MessagePinned($message))->toOthers();
        });
    }
}
