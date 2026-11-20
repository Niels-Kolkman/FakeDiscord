<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\User;
use App\Support\MentionNotifier;
use App\Support\NotificationMutes;
use App\Support\ServerCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member from a server, together with their roles on it.
 *
 * role_user rows are keyed by user, not by membership, so they do not
 * cascade when the server_members row goes and are deleted here. Invites the
 * member created stay usable, like on Discord. Their mention notifications
 * from the server (ISSUE-33) go too: they could no longer open them. So
 * do their mute settings for it (ISSUE-34): rejoining starts unmuted.
 */
class LeaveServer
{
    /**
     * @throws AuthorizationException when the user owns the server
     */
    public function leave(Server $server, User $user): void
    {
        DB::transaction(function () use ($server, $user): void {
            // Re-read the owner inside the transaction: ownership may have
            // been handed to this user since the policy check.
            $ownerId = Server::query()->whereKey($server->getKey())->lockForUpdate()->value('owner_id');

            if ((int) $ownerId === (int) $user->getKey()) {
                throw new AuthorizationException('Transfer ownership to another member before leaving this server.');
            }

            $server->memberships()->where('user_id', $user->getKey())->delete();

            DB::table('role_user')
                ->where('user_id', $user->getKey())
                ->whereIn('role_id', $server->roles()->select('id'))
                ->delete();

            MentionNotifier::forgetServer($user, $server);
            NotificationMutes::forgetServer($user, $server);

            // Plain query deletes: no model events (ISSUE-42).
            app(ServerCache::class)->forget((int) $server->getKey());
        });
    }
}
