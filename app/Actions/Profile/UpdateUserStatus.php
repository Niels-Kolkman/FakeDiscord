<?php

namespace App\Actions\Profile;

use App\Events\UserStatusChanged;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sets the status a user shows while connected: online, idle or
 * dnd (User::ONLINE_STATUSES). Picking the status you already have saves
 * nothing and broadcasts nothing.
 */
class UpdateUserStatus
{
    /**
     * @return bool whether the status changed
     */
    public function update(User $user, string $status): bool
    {
        if (! in_array($status, User::ONLINE_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown status [{$status}].");
        }

        if ($user->status === $status) {
            return false;
        }

        $user->forceFill(['status' => $status])->save();

        UserStatusChanged::dispatchFor($user);

        return true;
    }
}
