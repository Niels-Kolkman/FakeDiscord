<?php

namespace App\Support;

use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\ChannelPermissionOverride;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the server audit log (ISSUE-30). Most entries come from the model
 * observers in App\Observers (settings, channels, roles, bans, timeouts,
 * deleted messages); a kick and handing out a role change no model with
 * events of its own, so KickMember and AssignRole call record() directly.
 *
 * The actor is the signed-in user unless one is passed. A change nobody is
 * signed in for (seeders, artisan, queued work) is not recorded: there is
 * no "who" to show.
 *
 * Bound as a scoped singleton so withoutRecording() holds for the whole
 * request (CreateServer's default role and channel, a ban's message purge).
 */
class AuditLog
{
    private const TARGET_TYPES = [
        User::class => 'user',
        Server::class => 'server',
        Channel::class => 'channel',
        ChannelCategory::class => 'category',
        ChannelPermissionOverride::class => 'channel_override',
        Role::class => 'role',
        Invite::class => 'invite',
        Message::class => 'message',
    ];

    private int $paused = 0;

    /**
     * @param  array<string, array{old?: mixed, new?: mixed}>  $changes
     */
    public function record(
        Server|int $server,
        string $action,
        ?Model $target = null,
        ?string $targetLabel = null,
        array $changes = [],
        ?string $reason = null,
        ?User $actor = null,
    ): ?AuditLogEntry {
        $actor ??= Auth::user();

        if ($this->paused > 0 || ! $actor instanceof User) {
            return null;
        }

        return AuditLogEntry::query()->create([
            'server_id' => $server instanceof Server ? $server->getKey() : $server,
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'target_type' => $target === null ? null : (self::TARGET_TYPES[$target::class] ?? class_basename($target)),
            'target_id' => $target?->getKey(),
            'target_label' => $targetLabel === null ? null : mb_substr($targetLabel, 0, 255),
            'details' => $changes === [] ? null : $changes,
            'reason' => $reason,
        ]);
    }

    /**
     * False inside withoutRecording(), so observers can skip lookups.
     */
    public function isRecording(): bool
    {
        return $this->paused === 0;
    }

    /**
     * Run $callback without writing entries.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutRecording(callable $callback): mixed
    {
        $this->paused++;

        try {
            return $callback();
        } finally {
            $this->paused--;
        }
    }

    /**
     * Inside an `updated` observer: {field: {old, new}} for the $fields that
     * just changed. Empty when none of them did (a position-only change).
     *
     * @param  list<string>  $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function changesOf(Model $model, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (! $model->wasChanged($field)) {
                continue;
            }

            $changes[$field] = ['old' => $model->getOriginal($field), 'new' => $model->getAttribute($field)];
        }

        return $changes;
    }

    /**
     * {field: {new}} for the $fields of a model that was just created,
     * leaving out empty ones.
     *
     * @param  list<string>  $fields
     * @return array<string, array{new: mixed}>
     */
    public static function valuesOf(Model $model, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            if ($value !== null && $value !== '' && $value !== false) {
                $values[$field] = ['new' => $value];
            }
        }

        return $values;
    }
}
