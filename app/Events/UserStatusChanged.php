<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcast;
use App\Models\User;
use App\Support\ServerPresence;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user picked another status: online, idle or dnd.
 *
 * Sent on the presence channel "server.{id}" of every server they are in,
 * so open member lists recolour them, and on their own private
 * "App.Models.User.{id}" channel, so their other tabs update the status
 * picker. resources/js/presence.js listens for ".user.status".
 *
 * Presence member info is fixed when a tab subscribes (and Reverb keeps
 * the first tab's), so it can be stale; this event is what keeps open
 * pages right.
 */
class UserStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcast;

    public const NAME = 'user.status';

    /**
     * @param  list<int>  $serverIds
     */
    public function __construct(
        public User $user,
        public array $serverIds,
    ) {}

    public static function dispatchFor(User $user): void
    {
        // Saved either way; if Reverb is down, report it and carry on.
        rescue(function () use ($user): void {
            $serverIds = $user->servers()->pluck('servers.id')->map(fn ($id): int => (int) $id)->all();

            broadcast(new self($user, $serverIds));
        });
    }

    /**
     * @return list<PresenceChannel|PrivateChannel>
     */
    protected function channels(): array
    {
        $channels = [new PrivateChannel('App.Models.User.'.$this->user->getKey())];

        foreach ($this->serverIds as $serverId) {
            $channels[] = new PresenceChannel(ServerPresence::channelName($serverId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{user_id: int, status: string}
     */
    protected function payload(): array
    {
        return [
            'user_id' => (int) $this->user->getKey(),
            'status' => $this->user->chosenStatus(),
        ];
    }
}
