<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    /**
     * Permanently delete an account after confirming the password.
     *
     * Messages are anonymised, not removed: their author becomes NULL and
     * the UI shows "Deleted User" (see Message::authorName()). Memberships,
     * roles, reactions, friendships, bans and DM participation go with the
     * account through the schema's cascades.
     *
     * Deleting is refused while the user still owns servers: servers.owner_id
     * cascades, so it would wipe those servers and every member's messages.
     * Like Discord, ownership must be transferred (or the server deleted) first.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function delete(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['bail', 'required', 'string', 'current_password:web'],
        ], [
            'password.current_password' => __('The provided password is incorrect.'),
        ])->validateWithBag('deleteAccount');

        if ($user->ownedServers()->exists()) {
            throw ValidationException::withMessages([
                'password' => __('You own servers. Transfer ownership or delete them before deleting your account.'),
            ])->errorBag('deleteAccount');
        }

        DB::transaction(function () use ($user) {
            // The FK also nulls this on delete; doing it explicitly keeps the
            // guarantee even where foreign keys are not enforced.
            $user->messages()->update(['user_id' => null]);

            // Sign the account out everywhere (database session driver).
            DB::table('sessions')->where('user_id', $user->id)->delete();

            Password::broker(config('fortify.passwords'))->deleteToken($user);

            $user->delete();
        });
    }
}
