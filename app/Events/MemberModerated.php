<?php

namespace App\Events;

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator acted on the recipient in a server. Sent to the
 * recipient only, on their private "App.Models.User.{id}" channel, which
 * they keep after leaving the server. resources/js/moderation.js listens
 * for ".member.moderated".
 *
 * type:
 * - "kicked" / "banned": the recipient is no longer a member. If they are
 *   looking at the server, the page leaves its channels and goes to the
 *   dashboard. Their sockets are also closed on the server side
 *   (App\Support\RealtimeSessions) so every subscription is re-checked.
 * - "timed_out" / "timeout_removed": the page of that server reloads, so
 *   the composer and reaction buttons match what they may do now.
 *
 * The ban reason is not sent: like on Discord, it is for moderators.
 */
class MemberModerated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'member.moderated';

    public const KICKED = 'kicked';

    public const BANNED = 'banned';

    public const TIMED_OUT = 'timed_out';

    public const TIMEOUT_REMOVED = 'timeout_removed';

    public function __construct(
        public string $type,
        public Server $server,
        public User $recipient,
        public ?CarbonInterface $until = null,
    ) {}

    /**
     * Broadcast, but never fail the moderation action when Reverb is down.
     */
    public static function dispatchFor(string $type, Server $server, User $recipient, ?CarbonInterface $until = null): void
    {
        rescue(function () use ($type, $server, $recipient, $until): void {
            broadcast(new self($type, $server, $recipient, $until));
        });
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipient->getKey());
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{type: string, server: array{id: int, name: string}, until: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'server' => [
                'id' => (int) $this->server->getKey(),
                'name' => $this->server->name,
            ],
            'until' => $this->until?->toIso8601String(),
        ];
    }
}
