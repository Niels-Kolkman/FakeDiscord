<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Visitors register with a username, email and password.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Niels',
            'username' => 'niels',
            'email' => 'niels@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
    }

    public function test_registration_screen_renders_the_form(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertViewIs('auth.register')
            ->assertSee('name="username"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        $this->post('/register', $this->validPayload())
            ->assertRedirect('/dashboard');

        $user = User::where('username', 'niels')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('niels@example.test', $user->email);
        $this->assertSame('Niels', $user->name);
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->post('/register', $this->validPayload());

        $user = User::where('username', 'niels')->sole();

        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_usernames_are_normalised_to_lowercase(): void
    {
        $this->post('/register', $this->validPayload(['username' => '  Niels_K ']));

        $this->assertDatabaseHas('users', ['username' => 'niels_k']);
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'niels']);

        $this->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect('/register')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_username_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create(['username' => 'niels']);

        $this->post('/register', $this->validPayload(['username' => 'NIELS']))
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::count());
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'niels@example.test']);

        $this->post('/register', $this->validPayload())
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'missing username' => [['username' => ''], 'username'],
            'username too short' => [['username' => 'a'], 'username'],
            'username too long' => [['username' => str_repeat('a', 33)], 'username'],
            'username with spaces' => [['username' => 'niels k'], 'username'],
            'username with symbols' => [['username' => 'niels!'], 'username'],
            'missing email' => [['email' => ''], 'email'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'missing password' => [['password' => '', 'password_confirmation' => ''], 'password'],
            'password too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'password not confirmed' => [['password_confirmation' => 'something-else'], 'password'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $this->post('/register', $this->validPayload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_validation_errors_are_shown_on_the_form(): void
    {
        User::factory()->create(['username' => 'niels']);

        $this->followingRedirects()
            ->from('/register')
            ->post('/register', $this->validPayload(['email' => 'not-an-email']))
            ->assertOk()
            ->assertViewIs('auth.register')
            ->assertSee('The username has already been taken.')
            ->assertSee('The email field must be a valid email address.')
            // Previously entered values (but never passwords) are kept.
            ->assertSee('value="not-an-email"', false)
            ->assertDontSee('secret-password');
    }

    public function test_emails_are_stored_lowercase(): void
    {
        $this->post('/register', $this->validPayload(['email' => 'Niels@Example.TEST']));

        $this->assertDatabaseHas('users', ['email' => 'niels@example.test']);
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'niels@example.test']);

        $this->post('/register', $this->validPayload(['email' => 'NIELS@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_username_collision_is_detected_after_trimming(): void
    {
        User::factory()->create(['username' => 'niels']);

        $this->post('/register', $this->validPayload(['username' => '  Niels  ']))
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validUsernames(): array
    {
        return [
            'minimum length' => ['ab'],
            'maximum length' => [str_repeat('a', 32)],
            'digits only' => ['1234'],
            'underscore' => ['niels_k'],
            'period' => ['niels.k'],
        ];
    }

    #[DataProvider('validUsernames')]
    public function test_valid_usernames_are_accepted(string $username): void
    {
        $this->post('/register', $this->validPayload(['username' => $username]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', ['username' => $username]);
    }

    public function test_name_longer_than_255_characters_is_rejected(): void
    {
        $this->post('/register', $this->validPayload(['name' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, User::count());
    }

    public function test_non_string_username_is_rejected_without_crashing(): void
    {
        $payload = $this->validPayload();
        $payload['username'] = ['niels'];

        $this->post('/register', $payload)
            ->assertSessionHasErrors('username');

        $this->assertSame(0, User::count());
    }

    public function test_invalid_username_shows_the_custom_message(): void
    {
        $this->followingRedirects()
            ->from('/register')
            ->post('/register', $this->validPayload(['username' => 'niels!']))
            ->assertSee('The username may only contain lowercase letters, numbers, underscores and periods.');
    }

    public function test_registration_regenerates_the_session(): void
    {
        $this->get('/register');
        $before = session()->getId();

        $this->post('/register', $this->validPayload());

        $this->assertNotSame($before, session()->getId());
    }

    public function test_extra_fields_cannot_be_mass_assigned(): void
    {
        $this->post('/register', $this->validPayload([
            'remember_token' => 'injected-token',
            'email_verified_at' => '2020-01-01 00:00:00',
        ]));

        $user = User::where('username', 'niels')->sole();
        $this->assertNotSame('injected-token', $user->remember_token);
        $this->assertNull($user->email_verified_at);
    }

    public function test_logged_in_users_cannot_see_the_registration_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/register')
            ->assertRedirect('/dashboard');
    }
}
