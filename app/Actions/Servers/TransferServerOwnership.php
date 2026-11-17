<?php

namespace App\Actions\Servers;

use App\Http\Requests\TransferServerOwnershipRequest;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes another member the owner. The previous owner stays a member.
 */
class TransferServerOwnership
{
    /**
     * @throws ValidationException when the new owner is no longer a member
     */
    public function transfer(Server $server, User $newOwner): void
    {
        DB::transaction(function () use ($server, $newOwner): void {
            // Lock the membership so the new owner cannot leave halfway.
            $isMember = $server->memberships()
                ->where('user_id', $newOwner->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $isMember) {
                throw ValidationException::withMessages([
                    'new_owner_id' => 'The new owner must be a member of this server.',
                ])->errorBag(TransferServerOwnershipRequest::ERROR_BAG);
            }

            $server->update(['owner_id' => $newOwner->getKey()]);
        });
    }
}
