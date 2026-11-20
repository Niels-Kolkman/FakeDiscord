<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Mentions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        // Usernames are case-insensitive: "Niels" and "niels" are the same handle.
        if (isset($input['username']) && is_string($input['username'])) {
            $input['username'] = mb_strtolower(trim($input['username']));
        }

        /** @var array{name: string, username: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:2',
                'max:32',
                // Discord-style handles: lowercase letters, digits, "_" and ".".
                'regex:/^[a-z0-9_.]+$/',
                // "@everyone" is a mass mention, never a user.
                Rule::notIn(Mentions::RESERVED_USERNAMES),
                Rule::unique(User::class),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ], [
            'username.regex' => 'The username may only contain lowercase letters, numbers, underscores and periods.',
            'username.not_in' => 'This username is reserved.',
        ])->validate();

        return User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);
    }
}
