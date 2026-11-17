<?php

namespace App\Actions\Servers;

use App\Exceptions\InviteUnavailableException;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Joins a user to the invite's server.
 */
class AcceptInvite
{
    /**
     * @return bool true if the user joined, false if they were already a member
     *
     * @throws InviteUnavailableException
     */
    public function accept(Invite $invite, User $user): bool
    {
        $this->check($invite, $user);

        if ($invite->server->hasMember($user)) {
            return false;
        }

        try {
            DB::transaction(function () use ($invite, $user): void {
                // Count the use with a guarded UPDATE so two people cannot both
                // take the last use of a limited invite.
                $taken = Invite::query()->whereKey($invite->getKey())->usable()->increment('uses');

                if ($taken === 0) {
                    $invite->refresh();
                    throw new InviteUnavailableException(
                        $invite->isFull() ? InviteUnavailableException::FULL : InviteUnavailableException::EXPIRED,
                    );
                }

                $invite->server->memberships()->create([
                    'user_id' => $user->getKey(),
                    'joined_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel request joined first; the rollback gave the use back.
            return false;
        }

        $invite->refresh();

        return true;
    }

    /**
     * Throws if the user may not join through this invite. Checks, in order:
     * banned, already a member (fine, no use is taken), expired, full.
     *
     * @throws InviteUnavailableException
     */
    public function check(Invite $invite, User $user): void
    {
        $server = $invite->server;

        if ($server->hasBanned($user)) {
            throw new InviteUnavailableException(InviteUnavailableException::BANNED);
        }

        if ($server->hasMember($user)) {
            return;
        }

        if ($invite->isExpired()) {
            throw new InviteUnavailableException(InviteUnavailableException::EXPIRED);
        }

        if ($invite->isFull()) {
            throw new InviteUnavailableException(InviteUnavailableException::FULL);
        }
    }
}
