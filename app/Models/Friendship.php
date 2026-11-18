<?php

namespace App\Models;

use Database\Factories\FriendshipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per pair of users. user_id sent the request, friend_id received
 * it. For a block (ISSUE-25), user_id is the one who blocked.
 *
 * The pair is unique in both directions (A->B and B->A never both exist);
 * App\Actions\Friends\SendFriendRequest looks up the pair with between()
 * before it inserts. The one exception is a mutual block: when both
 * blocked each other there is a blocked row in each direction
 * (App\Actions\Friends\BlockUser), so each block is lifted on its own.
 */
class Friendship extends Model
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'friend_id',
        'status',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }

    /**
     * The row for this pair of users, whichever of them sent it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBetween(Builder $query, User $a, User $b): void
    {
        $query->where(function (Builder $query) use ($a, $b): void {
            $query->where(fn (Builder $q) => $q->where('user_id', $a->getKey())->where('friend_id', $b->getKey()))
                ->orWhere(fn (Builder $q) => $q->where('user_id', $b->getKey())->where('friend_id', $a->getKey()));
        });
    }

    /**
     * Rows where the user is on either side.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $user->getKey())->orWhere('friend_id', $user->getKey()));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function sentBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey();
    }

    public function receivedBy(User $user): bool
    {
        return (int) $this->friend_id === (int) $user->getKey();
    }

    public function involves(User $user): bool
    {
        return $this->sentBy($user) || $this->receivedBy($user);
    }

    /**
     * The person on the other side of the row from $viewer.
     */
    public function otherUser(User $viewer): User
    {
        /** @var User */
        return $this->sentBy($viewer) ? $this->friend : $this->user;
    }

    /**
     * Ids of the users $user blocked (ISSUE-25), as a subquery. People who
     * blocked $user are not in it.
     *
     * @return Builder<self>
     */
    public static function blockedIdsQuery(User $user): Builder
    {
        return self::query()
            ->select('friend_id')
            ->where('user_id', $user->getKey())
            ->where('status', self::STATUS_BLOCKED);
    }

    /**
     * @return list<int>
     */
    public static function blockedIds(User $user): array
    {
        return self::blockedIdsQuery($user)->pluck('friend_id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * How many friend requests are waiting for the user to answer (the
     * badge on the Pending tab and the Friends link).
     */
    public static function incomingCount(User $user): int
    {
        return self::query()
            ->where('friend_id', $user->getKey())
            ->where('status', self::STATUS_PENDING)
            ->count();
    }
}
