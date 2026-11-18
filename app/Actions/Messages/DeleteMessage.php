<?php

namespace App\Actions\Messages;

use App\Events\MessageDeleted;
use App\Models\Message;

/**
 * Soft delete a message and tell everyone else viewing the channel to
 * remove it. The person deleting it removes it from their own screen
 * after the HTTP response, so their socket is skipped (toOthers()).
 */
class DeleteMessage
{
    public function delete(Message $message): void
    {
        $message->delete();

        // Deleted either way; if Reverb is down, report it and carry on.
        rescue(function () use ($message): void {
            broadcast(new MessageDeleted(
                (int) $message->id,
                $message->channel_id === null ? null : (int) $message->channel_id,
                $message->conversation_id === null ? null : (int) $message->conversation_id,
            ))->toOthers();
        });
    }
}
