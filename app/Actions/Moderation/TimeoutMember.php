<?php

namespace App\Actions\Moderation;

use App\Events\MemberModerated;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Times a member out, or ends a timeout early (ISSUE-29). Who may do this
 * to whom is ServerPolicy::timeout.
 *
 * While server_members.timed_out_until is in the future the member keeps
 * only View Channels (PermissionService::TIMED_OUT_MASK): they can read,
 * but not post, attach, react, invite or moderate. Nothing has to run when
 * it ends; a timeout in the past counts as none.
 *
 * The member stays connected; App\Events\MemberModerated makes their open
 * page of the server reload so it shows the right composer.
 */
class TimeoutMember
{
    /**
     * Seconds => label, as on Discord (which allows up to 28 days).
     */
    public const DURATIONS = [
        60 => '60 seconds',
        300 => '5 minutes',
        600 => '10 minutes',
        3600 => '1 hour',
        86400 => '1 day',
        604800 => '1 week',
    ];

    public function timeout(Server $server, User $member, int $seconds): CarbonInterface
    {
        $until = now()->addSeconds($seconds)->startOfSecond();

        $server->memberships()->where('user_id', $member->getKey())->update(['timed_out_until' => $until]);

        MemberModerated::dispatchFor(MemberModerated::TIMED_OUT, $server, $member, $until);

        return $until;
    }

    /**
     * @return bool whether the member was timed out
     */
    public function remove(Server $server, User $member): bool
    {
        $removed = $server->memberships()
            ->where('user_id', $member->getKey())
            ->where('timed_out_until', '>', now())
            ->update(['timed_out_until' => null]) > 0;

        if ($removed) {
            MemberModerated::dispatchFor(MemberModerated::TIMEOUT_REMOVED, $server, $member);
        }

        return $removed;
    }
}
