<?php

namespace App\Observers;

use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\ServerCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Drops what App\Support\ServerCache holds for a server when something it
 * was worked out from changes (ISSUE-42): roles, who holds them, members
 * (joining, leaving, nicknames, timeouts), channel overrides, the server
 * itself (its owner) and a member's profile (the member list).
 * Registered in App\Providers\AppServiceProvider.
 *
 * Writes that skip model events (plain query inserts, updates and
 * deletes) call ServerCache::forget() themselves: App\Actions\Roles\AssignRole
 * and MoveRole, App\Actions\Servers\LeaveServer. (CreateRole's position
 * shift goes with the new role, whose event covers it.)
 */
class ForgetsServerCache
{
    /**
     * Changes to these user columns don't show anywhere ServerCache keeps.
     */
    private const IGNORED_USER_COLUMNS = [
        'password', 'remember_token', 'email', 'email_verified_at', 'theme', 'updated_at',
    ];

    public function __construct(private readonly ServerCache $cache) {}

    public function saved(Model $model): void
    {
        // A new user is in no server yet; updated() handles users.
        if (! $model instanceof User) {
            $this->cache->forget(...$this->serverIds($model));
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof User && array_diff(array_keys($model->getChanges()), self::IGNORED_USER_COLUMNS) !== []) {
            $this->cache->forget(...$this->serverIds($model));
        }
    }

    public function deleted(Model $model): void
    {
        if (! $model instanceof User) {
            $this->cache->forget(...$this->serverIds($model));
        }
    }

    /**
     * A deleted user's memberships go with them (ON DELETE CASCADE), so
     * their servers are looked up before.
     */
    public function deleting(Model $model): void
    {
        if ($model instanceof User) {
            $this->cache->forget(...$this->serverIds($model));
        }
    }

    /**
     * @return list<int>
     */
    private function serverIds(Model $model): array
    {
        $ids = match (true) {
            $model instanceof Role, $model instanceof ServerMember => [$model->server_id],
            $model instanceof Server => [$model->getKey()],
            $model instanceof ChannelPermissionOverride => Channel::query()->whereKey($model->channel_id)->pluck('server_id')->all(),
            $model instanceof RoleUser => Role::query()->whereKey($model->role_id)->pluck('server_id')->all(),
            $model instanceof User => ServerMember::query()->where('user_id', $model->getKey())->pluck('server_id')->all(),
            default => [],
        };

        return array_values(array_map('intval', array_filter($ids, fn (mixed $id): bool => $id !== null)));
    }
}
