<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message in a server channel or a DM. Goes out on the private
 * "channel.{id}" channel, or "conversation.{id}" for a DM
 * (Message::broadcastChannel()); routes/channels.php only lets server
 * members / participants subscribe. The browser listens for ".message.sent"
 * (resources/js/channel-messages.js).
 *
 * ShouldBroadcastNow: members see the message right away, without waiting
 * for a queue worker. App\Actions\Messages\SendMessage reports a failed
 * broadcast instead of failing the request, since the message is saved.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'message.sent';

    public function __construct(public Message $message) {}

    public static function channelName(int $channelId): string
    {
        return "channel.{$channelId}";
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->message->broadcastChannel());
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message->toPayload();
    }
}
