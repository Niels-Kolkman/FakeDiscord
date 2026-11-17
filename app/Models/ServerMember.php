<?php

namespace App\Models;

use Database\Factories\ServerMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
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
