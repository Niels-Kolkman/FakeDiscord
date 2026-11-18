<?php

namespace App\Models;

use App\Support\Reactions;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * Posted in a server channel OR a conversation: exactly one of channel_id /
 * conversation_id is set (enforced in the app layer, see the migration).
 *
 * Deleting a message soft deletes it (ISSUE-16): every query hides it, but
 * the row stays so replies keep pointing at it.
 *
 * Searchable with Laravel Scout's database engine (ISSUE-21): a search is a
 * LIKE query on the columns of toSearchableArray(), so there is no index to
 * keep in sync. App\Support\MessageSearch adds the server and filters.
 *
 * Replies (ISSUE-20) point at the original with reply_to_id; the payload
 * carries a short preview of it (replyPreview()). Pinned messages have a
 * pinned_at (and pinned_by_id, who pinned them).
 *
 * @property Carbon|null $edited_at NULL = never edited
 * @property Carbon|null $pinned_at NULL = not pinned
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, Searchable, SoftDeletes;

    /** Discord's limit for a message without Nitro. */
    public const MAX_CONTENT_LENGTH = 2000;

    /** Discord's limit of pinned messages per channel. */
    public const MAX_PINS_PER_CHANNEL = 50;

    /** Length of the quoted original above a reply. */
    public const REPLY_PREVIEW_LENGTH = 100;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'conversation_id',
        'user_id',
        'reply_to_id',
        'content',
        'edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The author; NULL once the account has been deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Display name of the author, or "Deleted User" for anonymised messages.
     */
    public function authorName(): string
    {
        return $this->author === null ? __('Deleted User') : $this->author->name;
    }

    /**
     * The columns Scout's database engine searches. Only the keys matter
     * to that engine, the values are never read.
     *
     * @return array{content: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'content' => (string) $this->content,
        ];
    }

    /**
     * Shown as "(edited)" next to the message.
     */
    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * Who pinned the message; NULL when not pinned or the account is gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    /**
     * Relations the reply preview reads, for eager loading. The original's
     * attachments are only counted: a reply to a file-only message says so.
     *
     * @return array<int|string, \Closure|string>
     */
    public static function replyRelations(): array
    {
        return [
            'replyTo' => fn (BelongsTo $query) => $query->withCount('attachments'),
            'replyTo.author',
        ];
    }

    /**
     * The quoted original above a reply: NULL when this is not a reply,
     * ['deleted' => true] when the original is gone (soft deleted, or its
     * row removed). The content is cut to REPLY_PREVIEW_LENGTH characters.
     *
     * @return array{id: int|null, deleted: bool, author: array{id: int, name: string}|null, content: string, has_attachments: bool}|null
     */
    public function replyPreview(): ?array
    {
        if ($this->reply_to_id === null) {
            return null;
        }

        if (! $this->relationLoaded('replyTo')) {
            $this->load(self::replyRelations());
        }

        $original = $this->replyTo;

        if ($original === null) {
            return [
                'id' => (int) $this->reply_to_id,
                'deleted' => true,
                'author' => null,
                'content' => '',
                'has_attachments' => false,
            ];
        }

        $hasAttachments = isset($original->attachments_count)
            ? (int) $original->attachments_count > 0
            : $original->hasAttachments();

        return [
            'id' => (int) $original->id,
            'deleted' => false,
            'author' => $original->author === null ? null : [
                'id' => (int) $original->author->id,
                'name' => $original->author->name,
            ],
            'content' => self::excerpt((string) $original->content),
            'has_attachments' => $hasAttachments,
        ];
    }

    /**
     * The text quoted above a reply: the excerpt, or a hint for a message
     * that only has files. Like previewText() in channel-messages.js.
     *
     * @param  array{content: string, has_attachments: bool}  $preview  replyPreview()
     */
    public static function previewText(array $preview): string
    {
        if ($preview['content'] !== '') {
            return $preview['content'];
        }

        return $preview['has_attachments'] ? __('Click to see attachment') : '';
    }

    /**
     * One line of at most REPLY_PREVIEW_LENGTH characters.
     */
    public static function excerpt(string $content): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $content));

        return mb_strlen($line) > self::REPLY_PREVIEW_LENGTH
            ? rtrim(mb_substr($line, 0, self::REPLY_PREVIEW_LENGTH - 1)).'…'
            : $line;
    }

    /**
     * The JSON a browser gets for a message: in the MessageSent and
     * MessageUpdated broadcasts and in the responses to the author.
     * resources/js/channel-messages.js renders it the same way as
     * resources/views/messages/item.blade.php.
     *
     * `reactions` are the grouped reactions (App\Support\Reactions::groups());
     * with a $viewer each group also says whether they reacted (`me`).
     * `attachments` (ISSUE-19) carry signed URLs (MessageAttachment::toPayload()).
     * `reply_to` (ISSUE-20) is replyPreview(); `pinned_at` is NULL unless pinned.
     *
     * @return array{id: int, channel_id: int|null, content: string, created_at: string|null, edited_at: string|null, pinned_at: string|null, author: array{id: int, name: string, username: string, avatar_url: string, profile_url: string}|null, reply_to: array<string, mixed>|null, reactions: list<array<string, mixed>>, attachments: list<array<string, mixed>>}
     */
    public function toPayload(?User $viewer = null): array
    {
        $author = $this->author;

        if (! $this->relationLoaded('reactions')) {
            $this->load(Reactions::relations());
        }

        if (! $this->relationLoaded('attachments')) {
            $this->load('attachments');
        }

        return [
            'id' => (int) $this->id,
            'channel_id' => $this->channel_id === null ? null : (int) $this->channel_id,
            'content' => (string) $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'pinned_at' => $this->pinned_at?->toIso8601String(),
            'author' => $author === null ? null : [
                'id' => (int) $author->id,
                'name' => $author->name,
                'username' => $author->username,
                'avatar_url' => $author->avatarUrl(),
                'profile_url' => route('users.profile', $author),
            ],
            'reply_to' => $this->replyPreview(),
            'reactions' => Reactions::groups($this, $viewer === null ? null : (int) $viewer->id),
            'attachments' => $this->attachments
                ->map(fn (MessageAttachment $attachment): array => $attachment->toPayload())
                ->values()
                ->all(),
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'reply_to_id');
    }

    /**
     * In upload order.
     *
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    public function hasAttachments(): bool
    {
        return $this->relationLoaded('attachments')
            ? $this->attachments->isNotEmpty()
            : $this->attachments()->exists();
    }

    /**
     * @return HasMany<Reaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }
}
