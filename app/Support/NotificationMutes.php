<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\NotificationMute;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mute settings, stored per user in notification_mutes: whole
 * servers (channel_id NULL) and single channels.
 *
 * A muted server or channel sends no browser notifications: mention
 * broadcasts from it carry `push: false` (MentionNotifier), so
 * resources/js/push-notifications.js stays quiet. The inbox, unread dots
 * and mention badges are unchanged, so nothing is lost by muting. DMs
 * cannot be muted.
 *
 * Muting twice is harmless (one row is kept) and so is unmuting what was
 * never muted. Leaving the server (forgetServer()) clears both kinds.
 */
class NotificationMutes
{
    public static function muteServer(User $user, Server $server): void
    {
        NotificationMute::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'server_id' => $server->getKey(),
            'channel_id' => null,
        ]);
    }

    public static function unmuteServer(User $user, Server $server): void
    {
        self::serverRows($user, $server)->delete();
    }

    public static function muteChannel(User $user, Channel $channel): void
    {
        NotificationMute::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'server_id' => $channel->server_id,
            'channel_id' => $channel->getKey(),
        ]);
    }

    public static function unmuteChannel(User $user, Channel $channel): void
    {
        NotificationMute::query()
            ->where('user_id', $user->getKey())
            ->where('channel_id', $channel->getKey())
            ->delete();
    }

    public static function isServerMuted(User $user, Server $server): bool
    {
        return self::serverRows($user, $server)->exists();
    }

    /** Only the channel's own setting, not its server's. */
    public static function isChannelMuted(User $user, Channel $channel): bool
    {
        return NotificationMute::query()
            ->where('user_id', $user->getKey())
            ->where('channel_id', $channel->getKey())
            ->exists();
    }

    /** Whether the channel is quiet for the user: muted itself or through its server. */
    public static function isMuted(User $user, Channel $channel): bool
    {
        return self::mutedUserIds($channel, [(int) $user->getKey()]) !== [];
    }

    /**
     * Which of $userIds muted the channel or its server, in one query.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public static function mutedUserIds(Channel $channel, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return NotificationMute::query()
            ->whereIn('user_id', $userIds)
            ->where('server_id', $channel->server_id)
            ->where(fn (Builder $query) => $query->whereNull('channel_id')->orWhere('channel_id', $channel->getKey()))
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The user's muted servers and channels, for the sidebar and the
     * settings page.
     *
     * @return array{servers: list<int>, channels: list<int>}
     */
    public static function forUser(User $user): array
    {
        $rows = NotificationMute::query()->where('user_id', $user->getKey())->get(['server_id', 'channel_id']);

        return [
            'servers' => $rows->whereNull('channel_id')->pluck('server_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all(),
            'channels' => $rows->whereNotNull('channel_id')->pluck('channel_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all(),
        ];
    }

    /** Someone who leaves (or is kicked or banned) loses the server's settings. */
    public static function forgetServer(User $user, Server $server): void
    {
        NotificationMute::query()
            ->where('user_id', $user->getKey())
            ->where('server_id', $server->getKey())
            ->delete();
    }

    /**
     * @return Builder<NotificationMute>
     */
    private static function serverRows(User $user, Server $server): Builder
    {
        return NotificationMute::query()
            ->where('user_id', $user->getKey())
            ->where('server_id', $server->getKey())
            ->whereNull('channel_id');
    }
}
