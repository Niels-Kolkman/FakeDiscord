<?php

namespace App\Actions\Messages;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Support\MentionNotifier;

/**
 * Change a message's content, mark it "(edited)" and broadcast it to the
 * others viewing the channel. The author gets the message in the HTTP
 * response, so their socket is skipped (toOthers()).
 *
 * Saving the same content again changes nothing: no "(edited)" and no
 * broadcast, like Discord.
 *
 * Mentions are worked out again on save; people newly mentioned
 * in a server channel get a notification and those no longer mentioned
 * lose theirs (App\Support\MentionNotifier::sync()).
 */
class EditMessage
{
    public function __construct(private readonly MentionNotifier $mentions) {}

    public function edit(Message $message, string $content): Message
    {
        if ($message->content === $content) {
            return $message;
        }

        $message->forceFill([
            'content' => $content,
            'edited_at' => now(),
        ])->save();

        // Saved either way; if Reverb is down, report it and carry on.
        rescue(function () use ($message): void {
            broadcast(new MessageUpdated($message))->toOthers();
        });

        if ($message->channel_id !== null) {
            $this->mentions->sync($message);
        }

        return $message;
    }
}
