<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the signed-in user's password (account settings).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        /** @var array{current_password: string, password: string} $validated */
        $validated = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
            'password.different' => __('The new password must be different from your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();
    }
}
