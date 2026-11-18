<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\Server;
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

            // Top of the hierarchy first.
            /** @var list<int> $ids */
            $ids = Role::query()
                ->where('server_id', $role->server_id)
                ->where('is_default', false)
                ->orderByDesc('position')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

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

            $role->refresh();

            return true;
        });
    }
}
