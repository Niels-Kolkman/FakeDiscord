<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\JoinClause;

/**
 * Unread channels and mention counts for one viewer: the dots
 * and badges in the channel sidebar and in the server lists.
 *
 * A message is unread when it is newer than the viewer's marker in
 * channel_reads (App\Actions\Channels\MarkChannelRead), or, without a
 * marker, was sent after they joined the server. Their own messages,
 * deleted messages and those of people they blocked (Message::visibleTo())
 * never count. A mention is an unread message that mentions them
 * (App\Support\Mentions, stored in messages.mentions): by username, by a
 * role they hold now, or @everyone.
 *
 * One aggregate query for all channels, plus one for the unread messages
 * that mention anyone when anything is unread, plus one for the viewer's
 * roles when one of those mentions a role.
 */
final readonly class UnreadState
{
    /**
     * @param  array<int, int>  $unread  channel id => unread messages
     * @param  array<int, int>  $mentions  channel id => unread mentions
     */
    public function __construct(
        private array $unread = [],
        private array $mentions = [],
    ) {}

    /**
     * @param  iterable<Channel>  $channels  only those the viewer can see
     */
    public static function forChannels(User $viewer, iterable $channels): self
    {
        $ids = [];
        foreach ($channels as $channel) {
            if (! $channel->isVoice()) {
                $ids[] = (int) $channel->id;
            }
        }

        if ($ids === []) {
            return new self;
        }

        /** @var array<int, int> $unread */
        $unread = self::unreadMessages($viewer, $ids)
            ->groupBy('messages.channel_id')
            ->selectRaw('messages.channel_id as channel_id, count(*) as unread_count')
            ->toBase()
            ->pluck('unread_count', 'channel_id')
            ->mapWithKeys(fn ($count, $channelId): array => [(int) $channelId => (int) $count])
            ->all();

        $mentions = [];
        $viewerId = (int) $viewer->id;

        if ($unread !== []) {
            $candidates = self::unreadMessages($viewer, array_keys($unread))
                ->whereNotNull('messages.mentions')
                ->toBase()
                ->get(['messages.channel_id', 'messages.mentions']);

            // The viewer's roles are only looked up once a role was mentioned.
            $roleIds = null;

            foreach ($candidates as $row) {
                $set = json_decode((string) $row->mentions, true);
                $set = is_array($set) ? $set : null;
                $mentioned = Mentions::includes($set, $viewerId);

                if (! $mentioned && ($set['roles'] ?? []) !== []) {
                    $roleIds ??= $viewer->roles()->pluck('roles.id')->map(fn (mixed $id): int => (int) $id)->all();
                    $mentioned = Mentions::includes($set, $viewerId, $roleIds);
                }

                if ($mentioned) {
                    $channelId = (int) $row->channel_id;
                    $mentions[$channelId] = ($mentions[$channelId] ?? 0) + 1;
                }
            }
        }

        return new self($unread, $mentions);
    }

    /**
     * One state per server (keyed by server id), over the channels the
     * viewer can see in each.
     *
     * @param  iterable<Server>  $servers
     * @return array<int, self>
     */
    public static function forServers(User $viewer, iterable $servers): array
    {
        $servers = collect($servers)->keyBy(fn (Server $server): int => (int) $server->id);

        if ($servers->isEmpty()) {
            return [];
        }

        $channels = Channel::query()
            ->whereIn('server_id', $servers->keys()->all())
            ->where('type', '!=', Channel::TYPE_VOICE)
            ->get()
            ->groupBy('server_id');

        $permissions = app(PermissionService::class);
        $visible = [];

        foreach ($servers as $id => $server) {
            /** @var EloquentCollection<int, Channel> $serverChannels */
            $serverChannels = $channels->get($id, new EloquentCollection);
            foreach ($serverChannels as $channel) {
                $channel->setRelation('server', $server);
            }
            $visible[$id] = $permissions->visibleChannels($viewer, $server, $serverChannels);
        }

        $all = self::forChannels($viewer, collect($visible)->flatten(1));

        return $servers->map(fn (Server $server, int $id): self => $all->only(
            $visible[$id]->map(fn (Channel $channel): int => (int) $channel->id)->all(),
        ))->all();
    }

    public function isUnread(Channel|int $channel): bool
    {
        return $this->unreadCount($channel) > 0;
    }

    public function unreadCount(Channel|int $channel): int
    {
        return $this->unread[self::id($channel)] ?? 0;
    }

    public function mentions(Channel|int $channel): int
    {
        return $this->mentions[self::id($channel)] ?? 0;
    }

    /** Any channel unread: the server's dot. */
    public function hasUnread(): bool
    {
        return $this->unread !== [];
    }

    /** All mentions together: the server's badge. */
    public function totalMentions(): int
    {
        return array_sum($this->mentions);
    }

    /**
     * @param  list<int>  $channelIds
     */
    private function only(array $channelIds): self
    {
        $keep = array_flip($channelIds);

        return new self(
            array_intersect_key($this->unread, $keep),
            array_intersect_key($this->mentions, $keep),
        );
    }

    private static function id(Channel|int $channel): int
    {
        return $channel instanceof Channel ? (int) $channel->id : $channel;
    }

    /**
     * The viewer's unread messages in these channels.
     *
     * @param  list<int>  $channelIds
     * @return Builder<Message>
     */
    private static function unreadMessages(User $viewer, array $channelIds): Builder
    {
        $viewerId = (int) $viewer->id;

        return Message::query()
            ->whereIn('messages.channel_id', $channelIds)
            ->where(fn (Builder $query) => $query
                ->whereNull('messages.user_id')
                ->orWhere('messages.user_id', '!=', $viewerId))
            ->visibleTo($viewer)
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->join('server_members', fn (JoinClause $join) => $join
                ->on('server_members.server_id', '=', 'channels.server_id')
                ->where('server_members.user_id', '=', $viewerId))
            ->leftJoin('channel_reads', fn (JoinClause $join) => $join
                ->on('channel_reads.channel_id', '=', 'messages.channel_id')
                ->where('channel_reads.user_id', '=', $viewerId))
            ->where(fn (Builder $query) => $query
                ->whereColumn('messages.id', '>', 'channel_reads.last_read_message_id')
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('channel_reads.id')
                    ->whereColumn('messages.created_at', '>=', 'server_members.joined_at')));
    }
}
