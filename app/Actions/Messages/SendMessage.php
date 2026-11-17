<?php

namespace App\Actions\Messages;

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/**
 * Store a message in a channel and broadcast it to everyone else viewing
 * the channel. The sender gets the message in the HTTP response, so the
 * broadcast skips their socket (toOthers(), using the X-Socket-ID header).
 */
class SendMessage
{
    public function send(User $author, Channel $channel, string $content): Message
    {
        $message = $channel->messages()->create([
            'user_id' => $author->id,
            'content' => $content,
        ]);

        $message->setRelation('author', $author);
        // A new message has no reactions; saves toPayload() a query.
        $message->setRelation('reactions', $message->reactions()->getRelated()->newCollection());

        // The message is saved either way. If Reverb is down, report it and
        // let the sender carry on; the others see it after a reload.
        rescue(function () use ($message): void {
            broadcast(new MessageSent($message))->toOthers();
        });

        return $message;
    }
}
