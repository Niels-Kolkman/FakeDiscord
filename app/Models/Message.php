<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Posted in a server channel OR a conversation: exactly one of channel_id /
 * conversation_id is set (enforced in the app layer, see the migration).
 *
 * Deleting a message soft deletes it (ISSUE-16): every query hides it, but
 * the row stays so replies keep pointing at it.
 *
 * @property Carbon|null $edited_at NULL = never edited
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    /** Discord's limit for a message without Nitro. */
    public const MAX_CONTENT_LENGTH = 2000;

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
     * Shown as "(edited)" next to the message.
     */
    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * The JSON a browser gets for a message: in the MessageSent and
     * MessageUpdated broadcasts and in the responses to the author.
     * resources/js/channel-messages.js renders it the same way as
     * resources/views/messages/item.blade.php.
     *
     * @return array{id: int, channel_id: int|null, content: string, created_at: string|null, edited_at: string|null, author: array{id: int, name: string, username: string, avatar_url: string, profile_url: string}|null}
     */
    public function toPayload(): array
    {
        $author = $this->author;

        return [
            'id' => (int) $this->id,
            'channel_id' => $this->channel_id === null ? null : (int) $this->channel_id,
            'content' => (string) $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'author' => $author === null ? null : [
                'id' => (int) $author->id,
                'name' => $author->name,
                'username' => $author->username,
                'avatar_url' => $author->avatarUrl(),
                'profile_url' => route('users.profile', $author),
            ],
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
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * @return HasMany<Reaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }
}
