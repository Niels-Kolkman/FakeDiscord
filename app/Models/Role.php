<?php

namespace App\Models;

use App\Enums\Permission;
use App\Observers\RoleObserver;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A server role: a name, an optional #rrggbb colour, a place in
 * the hierarchy (higher position = higher) and an App\Enums\Permission
 * bitmask.
 *
 * Every server has one default role, @everyone (is_default, position 0).
 * Every member has it implicitly, so it has no role_user rows; its name,
 * colour and position are fixed and it cannot be deleted.
 */
#[ObservedBy(RoleObserver::class)]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const EVERYONE = '@everyone';

    public const MAX_NAME_LENGTH = 100;

    /** Discord allows 250 roles per server, @everyone included. */
    public const MAX_PER_SERVER = 250;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'name',
        'color',
        'permissions',
        'position',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'integer',
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function hasPermission(Permission $permission): bool
    {
        return $permission->in((int) $this->permissions);
    }

    /**
     * @return list<Permission>
     */
    public function permissionList(): array
    {
        return Permission::fromMask((int) $this->permissions);
    }

    /**
     * The colour if it is a valid #rrggbb value, else null, so it is safe in
     * a style attribute.
     */
    public function displayColor(): ?string
    {
        return is_string($this->color) && preg_match('/^#[0-9a-fA-F]{6}$/', $this->color) === 1
            ? $this->color
            : null;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
