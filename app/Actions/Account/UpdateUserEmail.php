<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateUserEmail
{
    /**
     * Change the user's email address after confirming their password.
     *
     * The new address starts unverified and a fresh verification link is
     * sent, so `verified` routes stay closed until the user confirms it.
     *
     * @param  array<string, mixed>  $input
     * @return bool Whether the address actually changed.
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): bool
    {
        // Same normalisation as registration/login (fortify.lowercase_usernames).
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        /** @var array{email: string, current_password: string} $validated */
        $validated = Validator::make($input, [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updateEmail');

        if ($validated['email'] === $user->email) {
            return false;
        }

        $user->forceFill([
            'email' => $validated['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        return true;
    }
}
