<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;

/**
 * One page of a channel's message history, oldest first. A DM's history
 * (ISSUE-23) pages the same way, from ConversationMessageController::index.
 *
 * The channel page shows the newest page; scrolling up loads older pages
 * from GET /servers/{id}/channels/{channel}/messages?before={cursor}
 * (MessageController::index). Pages are cut with Laravel's cursorPaginate()
 * on the message id (newest first), so messages sent while someone is
 * reading never shift the pages they still have to load.
 *
 * $olderCursor is the encoded cursor of the page before this one, or NULL
 * when this page starts at the very first message of the channel.
 */
final readonly class MessageFeed
{
    public const LIMIT = 50;

    /** Query parameter that carries the cursor. */
    public const CURSOR = 'before';

    public bool $hasOlder;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function __construct(
        public Collection $messages,
        public ?string $olderCursor = null,
    ) {
        $this->hasOlder = $olderCursor !== null;
    }

    public static function empty(): self
    {
        return new self(collect());
    }

    /**
     * The newest page, or the page right before $before.
     *
     * With a $viewer, a server channel leaves out the messages of the
     * people they blocked (ISSUE-25, Message::visibleTo()). A DM always
     * shows its whole history.
     */
    public static function for(Channel|Conversation|null $channel, int $limit = self::LIMIT, ?Cursor $before = null, ?User $viewer = null): self
    {
        if ($channel === null || ($channel instanceof Channel && $channel->isVoice())) {
            return self::empty();
        }

        // Always hand cursorPaginate() a cursor: without one it would read
        // ?before= from the current request unvalidated. No message id is
        // ever PHP_INT_MAX, so this cursor means "start at the newest".
        $page = $channel->messages()
            ->when($viewer !== null && $channel instanceof Channel, fn ($query) => $query->visibleTo($viewer))
            ->with(['author', 'attachments', ...Reactions::relations(), ...Message::replyRelations()])
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], self::CURSOR, $before ?? new Cursor(['id' => PHP_INT_MAX]));

        /** @var Collection<int, Message> $newestFirst */
        $newestFirst = $page->getCollection();

        return new self(
            $newestFirst->reverse()->values(),
            $page->nextCursor()?->encode(),
        );
    }

    /**
     * Decode a cursor from the query string. Only the cursors this class
     * hands out are accepted ("older than message {id}"). Anything else,
     * including Laravel cursors that point at newer items, gives NULL.
     */
    public static function decodeCursor(mixed $encoded): ?Cursor
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > 200) {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $json === false ? null : json_decode($json, true);

        if (! is_array($parameters)
            || array_keys($parameters) !== ['id', '_pointsToNextItems']
            || $parameters['_pointsToNextItems'] !== true
            || ! is_int($parameters['id'])
            || $parameters['id'] < 1) {
            return null;
        }

        return new Cursor(['id' => $parameters['id']]);
    }

    /**
     * The JSON of MessageController::index. With a $viewer the reactions
     * say whether they reacted (`me`).
     *
     * @return array{messages: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function toPayload(?User $viewer = null): array
    {
        return [
            'messages' => $this->messages->map(fn (Message $message): array => $message->toPayload($viewer))->values()->all(),
            'next_cursor' => $this->olderCursor,
        ];
    }
}
