<?php

namespace App\Actions\Moderation;

use App\Actions\Servers\LeaveServer;
use App\Events\MemberModerated;
use App\Models\Server;
use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Removes a member from the server (ISSUE-29). They can rejoin with an
 * invite. Who may kick whom is ServerPolicy::kick.
 *
 * The membership and the member's roles on the server go the same way as
 * when they leave (LeaveServer); their messages stay. They are told over
 * App\Events\MemberModerated and their websocket connections are closed
 * (App\Support\RealtimeSessions), so they stop receiving the server's
 * live events straight away.
 */
class KickMember
{
    public function __construct(
        private readonly LeaveServer $members,
        private readonly RealtimeSessions $sessions,
    ) {}

    /**
     * @throws AuthorizationException when $member owns the server
     */
    public function kick(Server $server, User $member): void
    {
        $this->members->leave($server, $member);

        MemberModerated::dispatchFor(MemberModerated::KICKED, $server, $member);
        $this->sessions->disconnect($member);
    }
}
