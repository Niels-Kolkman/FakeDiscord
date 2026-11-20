<?php

/**
 * The sign-up / sign-in / sign-out flow end to end (Pest).
 */

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada',
        'username' => 'ada',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ], $overrides);
}

it('registers a visitor, signs them in and sends a verification email', function () {
    Notification::fake();

    $this->post('/register', registration())->assertRedirect('/dashboard');

    $user = User::where('username', 'ada')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('lowercases the username on registration', function () {
    $this->post('/register', registration(['username' => '  Ada.Lovelace ']));

    expect(User::sole()->username)->toBe('ada.lovelace');
});

it('rejects invalid registrations', function (array $overrides, string $field) {
    User::factory()->create(['username' => 'taken', 'email' => 'taken@example.test']);

    $this->from('/register')
        ->post('/register', registration($overrides))
        ->assertRedirect('/register')
        ->assertSessionHasErrors($field);

    $this->assertGuest();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'username with spaces' => [['username' => 'a b'], 'username'],
    'username too short' => [['username' => 'a'], 'username'],
    'taken username' => [['username' => 'taken'], 'username'],
    'taken email' => [['email' => 'taken@example.test'], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'unconfirmed password' => [['password_confirmation' => 'something-else'], 'password'],
]);

it('signs a user in with the right password', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('refuses a wrong password', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs a user out', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

it('sends guests to the login page', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with(['/dashboard', '/friends', '/servers/create', '/settings/profile']);

it('keeps unverified users out of the app until they verify', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/servers/create')->assertRedirect(route('verification.notice'));

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $this->actingAs($user)->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->actingAs($user->fresh())->get('/servers/create')->assertOk();
});
