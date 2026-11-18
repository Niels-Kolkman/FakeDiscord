<?php

namespace App\Actions\Moderation;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Servers\LeaveServer;
use App\Events\MemberModerated;
use App\Models\Ban;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bans a member (ISSUE-29): they are removed like a kick and cannot rejoin
 * through any invite until the ban is lifted (AcceptInvite checks
 * Server::hasBanned()). Who may ban whom is ServerPolicy::ban.
 *
 * Optionally the member's recent messages in the server's channels are
 * deleted, like Discord's "delete message history": every message they
 * sent in the last $deleteMessageSeconds is soft deleted through
 * DeleteMessage, so everyone watching sees it disappear. DMs are not
 * touched.
 *
 * The reason is kept for moderators (the ban list) and not shown to the
 * banned user.
 */
class BanMember
{
    /**
     * Seconds of message history that can be deleted => label, as on Discord.
     */
    public const DELETE_MESSAGE_OPTIONS = [
        0 => "Don't delete any",
        3600 => 'Previous hour',
        21600 => 'Previous 6 hours',
        43200 => 'Previous 12 hours',
        86400 => 'Previous 24 hours',
        259200 => 'Previous 3 days',
        604800 => 'Previous 7 days',
    ];

    public const MAX_REASON_LENGTH = 512;

    public function __construct(
        private readonly LeaveServer $members,
        private readonly DeleteMessage $messages,
        private readonly RealtimeSessions $sessions,
    ) {}

    /**
     * @return int how many messages were deleted
     *
     * @throws AuthorizationException when $member owns the server
     */
    public function ban(Server $server, User $member, User $moderator, ?string $reason = null, int $deleteMessageSeconds = 0): int
    {
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, self::MAX_REASON_LENGTH);

        DB::transaction(function () use ($server, $member, $moderator, $reason): void {
            // Ban and removal together: removed but not banned, they could
            // rejoin in between.
            Ban::query()->updateOrCreate(
                ['server_id' => $server->getKey(), 'user_id' => $member->getKey()],
                ['banned_by_id' => $moderator->getKey(), 'reason' => $reason],
            );

            $this->members->leave($server, $member);
        });

        $deleted = $deleteMessageSeconds > 0 ? $this->deleteRecentMessages($server, $member, $deleteMessageSeconds) : 0;

        MemberModerated::dispatchFor(MemberModerated::BANNED, $server, $member);
        $this->sessions->disconnect($member);

        return $deleted;
    }

    private function deleteRecentMessages(Server $server, User $member, int $seconds): int
    {
        $deleted = 0;

        Message::query()
            ->whereIn('channel_id', Channel::query()->where('server_id', $server->getKey())->select('id'))
            ->where('user_id', $member->getKey())
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->chunkById(100, function (Collection $messages) use (&$deleted): void {
                foreach ($messages as $message) {
                    $this->messages->delete($message);
                    $deleted++;
                }
            });

        return $deleted;
    }
}
