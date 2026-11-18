<?php

namespace App\Models;

use App\Observers\ChannelObserver;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ChannelObserver::class)]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const MAX_NAME_LENGTH = 100;

    public const MAX_TOPIC_LENGTH = 1024;

    /** Discord allows 500 channels per server. */
    public const MAX_PER_SERVER = 500;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'category_id',
        'name',
        'type',
        'topic',
        'position',
        'is_private',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'position' => 'integer',
            'is_private' => 'boolean',
        ];
    }

    /**
     * Discord-style text channel name: lower case, whitespace becomes "-",
     * and only letters, digits, "-" and "_" are kept ("My Cool  Chat!" →
     * "my-cool-chat"). Returns "" when nothing usable is left.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\s+/u', '-', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N}_-]+/u', '', $name);
        $name = (string) preg_replace('/-{2,}/', '-', $name);

        return trim($name, '-');
    }

    public function isVoice(): bool
    {
        return $this->type === self::TYPE_VOICE;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<ChannelCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class, 'category_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Per-role allow/deny overrides in this channel (ISSUE-28).
     *
     * @return HasMany<ChannelPermissionOverride, $this>
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(ChannelPermissionOverride::class);
    }

    /**
     * @return HasMany<Invite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }
}
