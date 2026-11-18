<?php

namespace App\Models;

use App\Actions\Servers\UpdateServerIcon;
use App\Enums\Permission;
use App\Support\ChannelLayout;
use App\Support\MemberList;
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

    public function hasBanned(User $user): bool
    {
        return $this->bans()->where('user_id', $user->getKey())->exists();
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
        return $this->hasMany(Channel::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ChannelCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(ChannelCategory::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The sidebar: uncategorised channels first, then each category with its
     * channels, all in display order.
     */
    public function channelLayout(): ChannelLayout
    {
        $channels = $this->channels()->get();
        $categories = $this->categories()->get();
        $byCategory = $channels->groupBy(fn (Channel $channel): int => (int) $channel->category_id);

        foreach ($categories as $category) {
            $category->setRelation('channels', $byCategory->get($category->id, collect())->values());
        }

        // A category_id that is not one of this server's categories cannot
        // be created through the app; list such channels as uncategorised.
        $known = $categories->modelKeys();
        $uncategorized = $channels
            ->filter(fn (Channel $channel): bool => $channel->category_id === null || ! in_array($channel->category_id, $known, true))
            ->values();

        return new ChannelLayout($uncategorized, $categories);
    }

    /**
     * The member sidebar, grouped by highest role and online status.
     */
    public function memberList(): MemberList
    {
        return MemberList::for($this);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Roles in hierarchy order: highest position first, @everyone last.
     *
     * @return HasMany<Role, $this>
     */
    public function rolesInOrder(): HasMany
    {
        return $this->roles()
            ->orderBy('is_default')
            ->orderByDesc('position')
            ->orderBy('id');
    }

    /**
     * The @everyone role. App\Actions\Servers\CreateServer always makes one;
     * it is created here for servers that somehow have none.
     */
    public function defaultRole(): Role
    {
        return $this->roles()->firstOrCreate(
            ['is_default' => true],
            ['name' => Role::EVERYONE, 'position' => 0, 'permissions' => Permission::everyoneDefaults()],
        );
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
