<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one role may or may not do in one channel (ISSUE-28). `allow` and
 * `deny` are App\Enums\Permission masks; a flag in neither is inherited from
 * the role's server permissions. How they combine is
 * App\Services\PermissionService::channelPermissions().
 *
 * @property int $allow
 * @property int $deny
 */
class ChannelPermissionOverride extends Model
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const INHERIT = 'inherit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'role_id',
        'allow',
        'deny',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_id' => 'integer',
            'role_id' => 'integer',
            'allow' => 'integer',
            'deny' => 'integer',
        ];
    }

    /**
     * ALLOW, DENY or INHERIT for one flag.
     */
    public function stateOf(Permission $permission): string
    {
        return match (true) {
            $permission->in((int) $this->deny) => self::DENY,
            $permission->in((int) $this->allow) => self::ALLOW,
            default => self::INHERIT,
        };
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
