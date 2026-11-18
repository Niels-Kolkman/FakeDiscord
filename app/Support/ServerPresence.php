<?php

namespace App\Support;

use App\Models\Server;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Throwable;

/**
 * Who is connected to a server right now (ISSUE-31).
 *
 * Every page of a signed-in user joins the presence channel
 * "server.{id}" of each server they are in (resources/js/presence.js,
 * authorised in routes/channels.php), so Reverb knows who is online. When
 * a page renders a member list, this asks Reverb for the channel's users
 * (Pusher's GET /channels/{channel}/users), so the list is right before
 * the browser has subscribed. The browser then keeps it up to date.
 *
 * Reverb counts a user once however many tabs they have open, and only
 * drops them when their last tab leaves. Brief gaps (moving to another
 * page) are smoothed over in the browser (PRESENCE_GRACE_MS in
 * presence.js), not here.
 *
 * - With a broadcaster that is not Pusher-compatible ("null", "log":
 *   tests, no websockets) presence is unknown: null, and MemberList falls
 *   back to the stored status.
 * - When Reverb cannot be reached the error is reported and only the
 *   viewer, who is obviously here, counts as connected.
 * - The viewer always counts as connected: their page is about to join.
 */
class ServerPresence
{
    public function __construct(private readonly BroadcastFactory $broadcast) {}

    /** The channel name as Echo joins it; Pusher prefixes "presence-". */
    public static function channelName(int $serverId): string
    {
        return "server.{$serverId}";
    }

    /**
     * @return list<int>|null user ids, or null when presence is unknown
     */
    public function connectedUserIds(Server $server, ?User $viewer = null): ?array
    {
        $broadcaster = $this->broadcast->connection();

        if (! $broadcaster instanceof PusherBroadcaster) {
            return null;
        }

        $viewerIds = $viewer === null ? [] : [(int) $viewer->getKey()];

        try {
            $response = $broadcaster->getPusher()->getPresenceUsers('presence-'.self::channelName((int) $server->getKey()));
        } catch (Throwable $e) {
            report($e);

            return $viewerIds;
        }

        $ids = [];
        foreach ((array) ($response->users ?? []) as $user) {
            $id = is_object($user) ? ($user->id ?? null) : (is_array($user) ? ($user['id'] ?? null) : null);
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique([...$ids, ...$viewerIds]));
    }
}
