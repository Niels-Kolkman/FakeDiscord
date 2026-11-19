<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Actions\Profile\UpdateUserAvatar;
use App\Support\DefaultAvatar;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'avatar_path',
        'bio',
        'status',
        'password',
    ];

    /**
     * New users show as online while connected (ISSUE-31). The column's
     * database default is still "offline", from before presence; a stored
     * "offline" reads as "online" through chosenStatus().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'online',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The statuses a user can pick (ISSUE-31), which are also the ones that
     * count as online. Anything else ("offline" or an unknown value) is
     * listed as offline.
     */
    public const ONLINE_STATUSES = ['online', 'idle', 'dnd'];

    public const STATUS_LABELS = ['online' => 'Online', 'idle' => 'Idle', 'dnd' => 'Do Not Disturb'];

    /**
     * Whether the stored status counts as online. Real presence (is the
     * user connected?) comes from App\Support\ServerPresence; MemberList
     * only falls back to this when that is unknown.
     */
    public function isOnline(): bool
    {
        return in_array($this->status, self::ONLINE_STATUSES, true);
    }

    /**
     * The status the user picked, "online" when the stored value is not
     * one of ONLINE_STATUSES.
     */
    public function chosenStatus(): string
    {
        return $this->isOnline() ? (string) $this->status : 'online';
    }

    /**
     * URL of the uploaded avatar, or a generated initials avatar (data URI)
     * when the user has not uploaded one.
     */
    public function avatarUrl(): string
    {
        return $this->avatar_path !== null
            ? Storage::disk(UpdateUserAvatar::disk())->url($this->avatar_path)
            : DefaultAvatar::dataUri($this);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function ownedServers(): HasMany
    {
        return $this->hasMany(Server::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Server, $this>
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_members')
            ->withPivot(['nickname', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
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

    /**
     * Friend requests this user sent.
     *
     * @return HasMany<Friendship, $this>
     */
    public function sentFriendships(): HasMany
    {
        return $this->hasMany(Friendship::class);
    }

    /**
     * Friend requests this user received.
     *
     * @return HasMany<Friendship, $this>
     */
    public function receivedFriendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'friend_id');
    }
}
