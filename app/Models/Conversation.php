<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A 1:1 direct message or a group DM, outside of any server.
 *
 * 1:1 DMs (ISSUE-23): a pair of users has one direct conversation, found
 * by its direct_key (directKey(), unique). Its messages broadcast on the
 * private "conversation.{id}" channel (broadcastChannel()), which only the
 * participants may join (routes/channels.php). last_message_at orders the
 * DM list in the sidebar (scopeByLatestActivity()).
 *
 * @property string|null $direct_key
 * @property Carbon|null $last_message_at
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    /** How many conversations the sidebar lists. */
    public const SIDEBAR_LIMIT = 50;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'owner_id',
        'direct_key',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The direct_key of the pair: the lower user id first, so it is the
     * same whichever of the two asks.
     */
    public static function directKey(User|int $a, User|int $b): string
    {
        $ids = [$a instanceof User ? (int) $a->getKey() : $a, $b instanceof User ? (int) $b->getKey() : $b];
        sort($ids);

        return implode(':', $ids);
    }

    public static function broadcastChannelName(int $conversationId): string
    {
        return "conversation.{$conversationId}";
    }

    /** The presence channel for typing indicators in this conversation. */
    public static function typingChannelName(int $conversationId): string
    {
        return "conversation-typing.{$conversationId}";
    }

    public function broadcastChannel(): string
    {
        return self::broadcastChannelName((int) $this->getKey());
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function hasParticipant(User $user): bool
    {
        if ($this->relationLoaded('users')) {
            return $this->users->contains('id', $user->getKey());
        }

        return $this->participants()->where('user_id', $user->getKey())->exists();
    }

    /**
     * The person on the other side of a 1:1 DM, or NULL when their account
     * is gone (participant rows cascade with the user).
     */
    public function otherParticipant(User $viewer): ?User
    {
        /** @var User|null */
        return $this->users->first(fn (User $user): bool => (int) $user->getKey() !== (int) $viewer->getKey());
    }

    /**
     * What the sidebar and the header call the conversation.
     */
    public function displayName(User $viewer): string
    {
        if (! $this->isDirect()) {
            return $this->name ?? __('Group DM');
        }

        return $this->otherParticipant($viewer)->name ?? __('Deleted User');
    }

    /**
     * Newest activity first: the last message, or when the conversation was
     * opened if nothing has been said yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeByLatestActivity(Builder $query): void
    {
        $query->orderByDesc(DB::raw('coalesce(last_message_at, created_at)'))->orderByDesc('id');
    }

    /**
     * The 1:1 DMs of a user for the sidebar, latest activity first, with
     * both participants loaded. Like Discord, a conversation nobody has
     * written in yet is left out (opening a DM with someone does not put
     * it in their list), except $active, the one on screen.
     *
     * @return Collection<int, self>
     */
    public static function sidebarFor(User $user, ?self $active = null, int $limit = self::SIDEBAR_LIMIT): Collection
    {
        return self::query()
            ->where('type', self::TYPE_DIRECT)
            ->whereHas('participants', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->where(function (Builder $query) use ($active): void {
                $query->whereNotNull('last_message_at');
                if ($active !== null) {
                    $query->orWhere($query->qualifyColumn('id'), $active->getKey());
                }
            })
            ->with('users')
            ->byLatestActivity()
            ->limit($limit)
            ->get();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
