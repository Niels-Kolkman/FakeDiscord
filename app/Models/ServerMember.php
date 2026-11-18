<?php

namespace App\Models;

use App\Observers\ServerMemberObserver;
use Database\Factories\ServerMemberFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $timed_out_until NULL = never timed out (ISSUE-29)
 */
#[ObservedBy(ServerMemberObserver::class)]
class ServerMember extends Model
{
    /** @use HasFactory<ServerMemberFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'user_id',
        'nickname',
        'joined_at',
        'timed_out_until',
    ];

    /**
     * Set by App\Support\MemberList; not stored.
     */
    private ?Role $highestRole = null;

    /**
     * The server nickname if set, else the user's name. Needs `user` loaded.
     */
    public function displayName(): string
    {
        return $this->nickname ?? $this->user->name;
    }

    /**
     * The member's highest role other than @everyone, as set by MemberList.
     */
    public function highestRole(): ?Role
    {
        return $this->highestRole;
    }

    public function setHighestRole(?Role $role): void
    {
        $this->highestRole = $role;
    }

    /**
     * Is the member timed out right now (ISSUE-29)? A timeout that has run
     * out counts as none; nothing needs to clear the column.
     */
    public function isTimedOut(): bool
    {
        return $this->timed_out_until !== null && $this->timed_out_until->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'timed_out_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
