<?php

namespace App\Models;

use App\Actions\Servers\UpdateServerIcon;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A community ("guild") owned by one user.
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'icon_path',
    ];

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->getKey();
    }

    public function hasMember(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->getKey())->exists();
    }

    /**
     * URL of the uploaded icon, or null; views fall back to initials().
     */
    public function iconUrl(): ?string
    {
        return $this->icon_path !== null
            ? Storage::disk(UpdateServerIcon::disk())->url($this->icon_path)
            : null;
    }

    /**
     * Discord-style fallback icon text: the first letter of up to three words.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->take(3)
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class)->orderBy('position');
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<ServerMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ServerMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'server_members')
            ->withPivot(['nickname', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Invite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /**
     * @return HasMany<Ban, $this>
     */
    public function bans(): HasMany
    {
        return $this->hasMany(Ban::class);
    }
}
