<?php

namespace App\Notifications;

use App\Models\Message;
use App\Support\NotificationInbox;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "Someone mentioned you" (ISSUE-33): a row in the user's notification
 * inbox (the database channel) and a live ".mention.created" event on
 * their private "App.Models.User.{id}" channel (the broadcast channel,
 * resources/js/notifications.js).
 *
 * Sent by App\Support\MentionNotifier, which decides who gets one. Not
 * queued, like the app's other broadcasts (ShouldBroadcastNow): it shows
 * up together with the message, without a queue worker (toBroadcast()).
 *
 * The stored data is a snapshot (names as they were, a short excerpt);
 * NotificationInbox::present() turns it into what the inbox shows, with
 * the links worked out when it is shown.
 */
class MentionedInMessage extends Notification
{
    public const BROADCAST_TYPE = 'mention.created';

    /** How the user was mentioned. */
    public const REASON_USER = 'user';

    public const REASON_ROLE = 'role';

    public const REASON_EVERYONE = 'everyone';

    public function __construct(
        public readonly Message $message,
        public readonly string $reason = self::REASON_USER,
        public readonly ?string $roleName = null,
    ) {}

    /**
     * MentionNotifier names the channels itself (the database first, then
     * a broadcast that may fail); this is the default for anyone else.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{message_id: int, channel_id: int, server_id: int, reason: string, role_name: string|null, author: array{id: int, name: string}|null, server_name: string, channel_name: string, excerpt: string}
     */
    public function toArray(object $notifiable): array
    {
        $message = $this->message;
        $channel = $message->channel;
        $author = $message->author;

        return [
            'message_id' => (int) $message->id,
            'channel_id' => (int) $channel->id,
            'server_id' => (int) $channel->server_id,
            'reason' => $this->reason,
            'role_name' => $this->roleName,
            'author' => $author === null ? null : ['id' => (int) $author->id, 'name' => $author->name],
            'server_name' => (string) $channel->server->name,
            'channel_name' => (string) $channel->name,
            'excerpt' => Message::excerpt((string) $message->content),
        ];
    }

    /**
     * Laravel broadcasts notifications through a queued event
     * (BroadcastNotificationCreated); the "sync" connection sends it right
     * away, like the app's ShouldBroadcastNow events, whatever
     * QUEUE_CONNECTION is.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(NotificationInbox::present(
            (string) $this->id,
            $this->toArray($notifiable),
            readAt: null,
            createdAt: $this->message->created_at,
            author: $this->message->author,
        )))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return self::BROADCAST_TYPE;
    }
}
