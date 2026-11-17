<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-6: visitors register with a username, email and password.
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

    public function test_logged_in_users_cannot_see_the_registration_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/register')
            ->assertRedirect('/dashboard');
    }
}
