<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\Server;
use App\Support\ServerCache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a role one place up or down the hierarchy.
 *
 * The roles other than @everyone are renumbered 1..n in their new order, so
 * duplicate or missing positions (from older data) sort themselves out. The
 * default role (@everyone) stays at 0, below every other role.
 */
class MoveRole
{
    public const UP = 'up';

    public const DOWN = 'down';

    /**
     * The role that move() would swap $role with, or null at that end (and
     * for @everyone). RoleController uses it so a member cannot move a role
     * past their own highest role.
     */
    public function neighbour(Role $role, string $direction): ?Role
    {
        if ($role->is_default) {
            return null;
        }

        $ids = $this->orderedIds((int) $role->server_id);
        $index = array_search((int) $role->getKey(), $ids, true);
        if ($index === false) {
            return null;
        }

        $target = $direction === self::UP ? $index - 1 : $index + 1;

        return isset($ids[$target]) ? Role::query()->find($ids[$target]) : null;
    }

    /**
     * @return bool false when the role is already at that end, or is @everyone
     */
    public function move(Role $role, string $direction): bool
    {
        if (! in_array($direction, [self::UP, self::DOWN], true)) {
            throw new InvalidArgumentException("Unknown direction [{$direction}].");
        }

        if ($role->is_default) {
            return false;
        }

        return DB::transaction(function () use ($role, $direction): bool {
            Server::query()->whereKey($role->server_id)->lockForUpdate()->first();

            $ids = $this->orderedIds((int) $role->server_id);

            $index = array_search((int) $role->getKey(), $ids, true);
            if ($index === false) {
                return false;
            }

            $target = $direction === self::UP ? $index - 1 : $index + 1;
            if (! isset($ids[$target])) {
                return false;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            $count = count($ids);
            foreach ($ids as $i => $id) {
                Role::query()->whereKey($id)->update(['position' => $count - $i]);
            }
            // Plain query updates: no model events (ISSUE-42).
            app(ServerCache::class)->forget((int) $role->server_id);

            $role->refresh();

            return true;
        });
    }

    /**
     * The server's roles other than @everyone, top of the hierarchy first.
     *
     * @return list<int>
     */
    private function orderedIds(int $serverId): array
    {
        return Role::query()
            ->where('server_id', $serverId)
            ->where('is_default', false)
            ->orderByDesc('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
