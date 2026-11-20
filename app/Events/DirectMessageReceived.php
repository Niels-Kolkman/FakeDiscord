<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone wrote to you in a DM or group DM (ISSUE-34). Goes to one
 * recipient's own "App.Models.User.{id}" channel, so the browser can show
 * a notification wherever they are in the app while the tab is not in
 * focus (resources/js/push-notifications.js, ".dm.received").
 *
 * Unlike ConversationActivity it carries who wrote and a short excerpt:
 * it only ever goes to someone who can read the message. One event per
 * recipient (App\Support\DirectMessageNotifier), because a group DM
 * without a name is called something different for each of them.
 */
class DirectMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'dm.received';

    public function __construct(
        public Message $message,
        public Conversation $conversation,
        public User $recipient,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    protected function channels(): array
    {
        return [new PrivateChannel('App.Models.User.'.(int) $this->recipient->getKey())];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{conversation_id: int, message_id: int, is_group: bool, title: string, author_name: string, avatar_url: string|null, excerpt: string, has_attachments: bool, url: string}
     */
    protected function payload(): array
    {
        $message = $this->message;
        $conversation = $this->conversation;
        $author = $message->author;

        return [
            'conversation_id' => (int) $conversation->getKey(),
            'message_id' => (int) $message->getKey(),
            'is_group' => ! $conversation->isDirect(),
            'title' => $conversation->displayName($this->recipient),
            'author_name' => $author !== null ? (string) $author->name : (string) __('Deleted User'),
            'avatar_url' => $author?->avatarUrl(),
            'excerpt' => Message::excerpt((string) $message->content),
            'has_attachments' => $message->relationLoaded('attachments') && $message->attachments->isNotEmpty(),
            'url' => route('dms.show', $conversation).'#message-'.(int) $message->getKey(),
        ];
    }
}
