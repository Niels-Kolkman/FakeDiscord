<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;

/**
 * Closes a user's websocket connections on the Reverb server (ISSUE-29).
 *
 * Channel authorization (routes/channels.php) only runs when a browser
 * subscribes, so someone who is kicked or banned would otherwise keep
 * receiving a server's messages until they reload. Reverb implements
 * Pusher's "terminate user connections" API; pusher-js then reconnects
 * and subscribes again, and every server channel is authorized afresh,
 * which a former member fails.
 *
 * Reverb knows which user a connection belongs to from presence channels
 * (the typing channels the message panel always joins), so that is what
 * gets closed. resources/js/moderation.js also leaves the server's
 * channels in the browser when App\Events\MemberModerated arrives.
 *
 * Only the Pusher-compatible broadcasters (reverb, pusher) can do this;
 * with "log" or "null" (tests, no websockets) it does nothing. A Reverb
 * server that is down is reported and ignored: the moderation itself has
 * already happened.
 */
class RealtimeSessions
{
    public function __construct(private readonly BroadcastFactory $broadcast) {}

    public function disconnect(User $user): void
    {
        rescue(function () use ($user): void {
            $broadcaster = $this->broadcast->connection();

            if ($broadcaster instanceof PusherBroadcaster) {
                $broadcaster->getPusher()->terminateUserConnections((string) $user->getKey());
            }
        });
    }
}
