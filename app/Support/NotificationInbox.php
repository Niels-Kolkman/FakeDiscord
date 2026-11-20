<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Services\PermissionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The notification inbox: the bell with its unread count and
 * the panel listing the user's recent mentions
 * (App\Notifications\MentionedInMessage), newest first.
 *
 * Notifications are snapshots, so before they are shown the ones that no
 * longer lead anywhere are dropped and deleted: the message is gone, its
 * author is now blocked, or the user can no longer see the channel (they
 * left the server, it was deleted, or a role or override hides it).
 * Deleting a message and leaving a server remove them straight away
 * (App\Support\MentionNotifier); this catches the rest, so the count heals
 * once the inbox has been opened.
 *
 * Opening a channel marks its mentions read up to where it was read
 * (markChannelRead(), from App\Actions\Channels\MarkChannelRead).
 */
final class NotificationInbox
{
    /** How many notifications the panel and the page show. */
    public const LIMIT = 50;

    /** The bell shows "99+" past this. */
    public const MAX_BADGE = 99;

    public static function unreadCount(User $user): int
    {
        return self::query($user)->whereNull('read_at')->count();
    }

    /** "3", or "99+" past MAX_BADGE; "" for none. */
    public static function badge(int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count > self::MAX_BADGE => self::MAX_BADGE.'+',
            default => (string) $count,
        };
    }

    /**
     * The newest LIMIT notifications that still lead somewhere, as
     * present() arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(User $user): array
    {
        /** @var EloquentCollection<int, DatabaseNotification> $notifications */
        $notifications = self::query($user)->latest()->latest('id')->limit(self::LIMIT)->get();

        if ($notifications->isEmpty()) {
            return [];
        }

        $messageIds = $notifications->map(fn (DatabaseNotification $n): int => self::messageId($n))->unique()->values()->all();
        $messages = Message::query()
            ->whereKey($messageIds)
            ->whereNotNull('channel_id')
            ->visibleTo($user)
            ->with(['author', 'channel.server'])
            ->get()
            ->keyBy('id');

        /** @var Collection<int, Channel> $channels */
        $channels = $messages->pluck('channel')->filter();
        $visibleChannels = self::visibleChannelIds($user, $channels);

        $items = [];
        $stale = [];

        foreach ($notifications as $notification) {
            $message = $messages->get(self::messageId($notification));

            if ($message === null || ! isset($visibleChannels[(int) $message->channel_id])) {
                $stale[] = $notification->id;

                continue;
            }

            $items[] = self::present(
                (string) $notification->id,
                (array) $notification->data,
                $notification->read_at,
                $notification->created_at,
                $message->author,
            );
        }

        if ($stale !== []) {
            DatabaseNotification::query()->whereKey($stale)->delete();
        }

        return $items;
    }

    /**
     * What the panel shows for one notification: the stored snapshot
     * (MentionedInMessage::toArray()) plus labels and links. The JSON of
     * NotificationController::index and of the live broadcast; rendered by
     * notifications/item.blade.php and resources/js/notifications.js.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, read: bool, created_at: string|null, reason: string, label: string, author_name: string, avatar_url: string|null, server_name: string, channel_name: string, excerpt: string, channel_id: int, message_id: int, url: string, open_url: string}
     */
    public static function present(string $id, array $data, ?CarbonInterface $readAt, ?CarbonInterface $createdAt, ?User $author): array
    {
        $reason = (string) ($data['reason'] ?? MentionedInMessage::REASON_USER);
        $serverId = (int) ($data['server_id'] ?? 0);
        $channelId = (int) ($data['channel_id'] ?? 0);
        $messageId = (int) ($data['message_id'] ?? 0);
        $storedAuthor = is_array($data['author'] ?? null) ? (string) ($data['author']['name'] ?? '') : '';

        return [
            'id' => $id,
            'read' => $readAt !== null,
            'created_at' => $createdAt?->toIso8601String(),
            'reason' => $reason,
            'label' => self::label($reason, $data['role_name'] ?? null),
            'author_name' => $author !== null ? (string) $author->name : ($storedAuthor !== '' ? $storedAuthor : __('Deleted User')),
            'avatar_url' => $author?->avatarUrl(),
            'server_name' => (string) ($data['server_name'] ?? ''),
            'channel_name' => (string) ($data['channel_name'] ?? ''),
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'url' => route('channels.show', [$serverId, $channelId]).'#message-'.$messageId,
            'open_url' => route('notifications.open', $id),
        ];
    }

    /** "mentioned you", "mentioned @Mods" or "mentioned @everyone". */
    public static function label(string $reason, mixed $roleName = null): string
    {
        return match ($reason) {
            MentionedInMessage::REASON_ROLE => 'mentioned @'.(is_string($roleName) && $roleName !== '' ? $roleName : 'a role'),
            MentionedInMessage::REASON_EVERYONE => 'mentioned @everyone',
            default => 'mentioned you',
        };
    }

    /**
     * Marks the user's mentions in the channel read up to (and with)
     * message $upTo: they have seen them by opening it.
     */
    public static function markChannelRead(User $user, Channel $channel, int $upTo): int
    {
        return self::query($user)
            ->whereNull('read_at')
            ->where('data->channel_id', (int) $channel->id)
            ->where('data->message_id', '<=', $upTo)
            ->update(['read_at' => now()]);
    }

    public static function markAllRead(User $user): int
    {
        return self::query($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * The user's mention notifications.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public static function query(User $user): MorphMany
    {
        return $user->notifications()->where('type', MentionedInMessage::class);
    }

    private static function messageId(DatabaseNotification $notification): int
    {
        return (int) (((array) $notification->data)['message_id'] ?? 0);
    }

    /**
     * Ids of the channels (among $channels) the user can still see, as
     * keys. One permission check per server.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<int, true>
     */
    private static function visibleChannelIds(User $user, Collection $channels): array
    {
        $permissions = app(PermissionService::class);
        $visible = [];

        foreach ($channels->unique('id')->groupBy('server_id') as $serverChannels) {
            /** @var Channel $first */
            $first = $serverChannels->first();
            $seen = $permissions->visibleChannels($user, $first->server, new EloquentCollection($serverChannels->all()));
            foreach ($seen as $channel) {
                $visible[(int) $channel->id] = true;
            }
        }

        return $visible;
    }
}
