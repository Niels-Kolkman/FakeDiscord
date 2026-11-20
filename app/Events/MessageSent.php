<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message in a server channel or a DM. Goes out on the private
 * "channel.{id}" channel, or "conversation.{id}" for a DM (ISSUE-23,
 * Message::broadcastChannel()); routes/channels.php only lets server
 * members / participants subscribe. The browser listens for ".message.sent"
 * (resources/js/channel-messages.js).
 *
 * Queued (ISSUE-42, Concerns\QueuesBroadcast): the sender's request only
 * adds a job, and the queue worker passes the payload to Reverb. The
 * message is saved either way: App\Actions\Messages\SendMessage reports a
 * failure to queue instead of failing the request.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'message.sent';

    public function __construct(public Message $message) {}

    public static function channelName(int $channelId): string
    {
        return "channel.{$channelId}";
    }

    protected function channels(): PrivateChannel
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
    protected function payload(): array
    {
        return $this->message->toPayload();
    }
}
