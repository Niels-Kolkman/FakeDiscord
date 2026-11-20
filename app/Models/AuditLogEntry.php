<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\AuditLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a server's audit log: who (actor) did what
 * (action) to what (target), and when (created_at). Written by
 * App\Support\AuditLog, never changed afterwards.
 *
 * `target_label` is the target's name at the time (a deleted channel keeps
 * its name in the log). `details` maps a field to {old?, new}: updates have
 * both, creations and extra context only `new`.
 *
 * @property string $action one of the ACTIONS keys
 * @property array<string, array{old?: mixed, new?: mixed}>|null $details
 * @property Carbon|null $created_at
 */
class AuditLogEntry extends Model
{
    /** @use HasFactory<AuditLogEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const SERVER_UPDATE = 'server.update';

    public const SERVER_OWNER_TRANSFER = 'server.owner_transfer';

    public const CHANNEL_CREATE = 'channel.create';

    public const CHANNEL_UPDATE = 'channel.update';

    public const CHANNEL_DELETE = 'channel.delete';

    public const CHANNEL_OVERRIDE_CREATE = 'channel_override.create';

    public const CHANNEL_OVERRIDE_UPDATE = 'channel_override.update';

    public const CHANNEL_OVERRIDE_DELETE = 'channel_override.delete';

    public const CATEGORY_CREATE = 'category.create';

    public const CATEGORY_UPDATE = 'category.update';

    public const CATEGORY_DELETE = 'category.delete';

    public const ROLE_CREATE = 'role.create';

    public const ROLE_UPDATE = 'role.update';

    public const ROLE_DELETE = 'role.delete';

    public const MEMBER_ROLE_ADD = 'member.role_add';

    public const MEMBER_ROLE_REMOVE = 'member.role_remove';

    public const MEMBER_KICK = 'member.kick';

    public const MEMBER_BAN = 'member.ban';

    public const MEMBER_UNBAN = 'member.unban';

    public const MEMBER_TIMEOUT = 'member.timeout';

    public const MEMBER_TIMEOUT_REMOVE = 'member.timeout_remove';

    public const MESSAGE_DELETE = 'message.delete';

    public const INVITE_CREATE = 'invite.create';

    public const INVITE_DELETE = 'invite.delete';

    /**
     * Action => label, in the order the filter lists them.
     */
    public const ACTIONS = [
        self::SERVER_UPDATE => 'Update server',
        self::SERVER_OWNER_TRANSFER => 'Transfer ownership',
        self::CHANNEL_CREATE => 'Create channel',
        self::CHANNEL_UPDATE => 'Update channel',
        self::CHANNEL_DELETE => 'Delete channel',
        self::CHANNEL_OVERRIDE_CREATE => 'Create channel permission',
        self::CHANNEL_OVERRIDE_UPDATE => 'Update channel permission',
        self::CHANNEL_OVERRIDE_DELETE => 'Delete channel permission',
        self::CATEGORY_CREATE => 'Create category',
        self::CATEGORY_UPDATE => 'Update category',
        self::CATEGORY_DELETE => 'Delete category',
        self::ROLE_CREATE => 'Create role',
        self::ROLE_UPDATE => 'Update role',
        self::ROLE_DELETE => 'Delete role',
        self::MEMBER_ROLE_ADD => 'Give role',
        self::MEMBER_ROLE_REMOVE => 'Take role',
        self::MEMBER_KICK => 'Kick member',
        self::MEMBER_BAN => 'Ban member',
        self::MEMBER_UNBAN => 'Unban member',
        self::MEMBER_TIMEOUT => 'Time out member',
        self::MEMBER_TIMEOUT_REMOVE => 'Remove timeout',
        self::MESSAGE_DELETE => 'Delete message',
        self::INVITE_CREATE => 'Create invite',
        self::INVITE_DELETE => 'Delete invite',
    ];

    private const FIELD_LABELS = [
        'name' => 'Name',
        'description' => 'Description',
        'icon' => 'Icon',
        'owner' => 'Owner',
        'topic' => 'Topic',
        'type' => 'Type',
        'is_private' => 'Private',
        'color' => 'Colour',
        'permissions' => 'Permissions',
        'allow' => 'Allowed',
        'deny' => 'Denied',
        'role' => 'Role',
        'channel' => 'Channel',
        'timed_out_until' => 'Timed out until',
        'max_uses' => 'Max uses',
        'expires_at' => 'Expires',
    ];

    /** Fields that hold App\Enums\Permission masks. */
    private const MASK_FIELDS = ['permissions', 'allow', 'deny'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'details',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'details' => 'array',
        ];
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /**
     * The changes as readable lines, e.g. "Name: general → chat" or
     * "Permissions: + Kick Members, − Ban Members".
     *
     * @return list<string>
     */
    public function changeLines(): array
    {
        $lines = [];

        foreach ($this->details ?? [] as $field => $change) {
            $label = self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', (string) $field));
            $new = $change['new'] ?? null;

            if (in_array($field, self::MASK_FIELDS, true)) {
                $lines[] = $label.': '.$this->describeMask(
                    array_key_exists('old', $change) ? (int) $change['old'] : null,
                    (int) $new,
                );

                continue;
            }

            $lines[] = array_key_exists('old', $change)
                ? $label.': '.$this->format($field, $change['old']).' → '.$this->format($field, $new)
                : $label.': '.$this->format($field, $new);
        }

        return $lines;
    }

    private function describeMask(?int $old, int $new): string
    {
        $labels = fn (int $mask): array => array_map(fn (Permission $p): string => $p->label(), Permission::fromMask($mask));

        if ($old === null) {
            return $labels($new) === [] ? 'none' : implode(', ', $labels($new));
        }

        $parts = [
            ...array_map(fn (string $label): string => '+ '.$label, $labels($new & ~$old)),
            ...array_map(fn (string $label): string => '− '.$label, $labels($old & ~$new)),
        ];

        return $parts === [] ? 'no change' : implode(', ', $parts);
    }

    private function format(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return match ($field) {
                'color' => 'default',
                'max_uses' => 'unlimited',
                'expires_at' => 'never',
                default => 'none',
            };
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (in_array($field, ['timed_out_until', 'expires_at'], true)) {
            return Carbon::parse((string) $value)->toDayDateTimeString();
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
