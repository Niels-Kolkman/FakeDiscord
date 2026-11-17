<?php

namespace App\Models;

use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $max_uses NULL = unlimited
 * @property int $uses
 * @property Carbon|null $expires_at NULL = never
 */
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'server_id',
        'channel_id',
        'inviter_id',
        'max_uses',
        'uses',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Every use is taken (max_uses NULL means unlimited).
     */
    public function isFull(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isFull();
    }

    /**
     * Invites that can still be used.
     *
     * @param  Builder<Invite>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    public function url(): string
    {
        return route('invites.show', $this->code);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
