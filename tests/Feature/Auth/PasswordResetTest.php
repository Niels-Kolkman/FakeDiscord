<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Users who forgot their password can reset it via an emailed link.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertViewIs('auth.forgot-password')
            ->assertSee('name="email"', false);
    }

    public function test_a_reset_link_is_emailed(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', trans('passwords.sent'));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_shows_a_validation_error(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_reset_password_screen_renders_with_token_and_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))
                ->assertOk()
                ->assertViewIs('auth.reset-password')
                ->assertSee('value="'.$notification->token.'"', false)
                ->assertSee('value="'.$user->email.'"', false);

            return true;
        });
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/login');

            return true;
        });

        $this->assertTrue(Hash::check('brand-new-password', (string) $user->fresh()?->password));
    }

    /**
     * Requests a reset link for the user and returns the emailed token.
     */
    private function requestResetToken(User $user): string
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return (string) $token;
    }

    public function test_forgot_password_requires_an_email(): void
    {
        $this->post('/forgot-password', [])->assertSessionHasErrors('email');
    }

    public function test_reset_link_request_ignores_email_casing(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'niels@example.test']);

        $this->post('/forgot-password', ['email' => 'NIELS@Example.test'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_status_message_is_shown_after_requesting_a_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->followingRedirects()
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertSee(trans('passwords.sent'));
    }

    public function test_after_a_reset_the_new_password_works_and_the_old_does_not(): void
    {
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'brand-new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertNotSame('old-remember-token', $user->fresh()?->remember_token);
    }

    public function test_reset_tokens_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('brand-new-password', (string) $user->fresh()?->password));
    }

    public function test_reset_works_with_differently_cased_email(): void
    {
        $user = User::factory()->create(['email' => 'niels@example.test']);
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'NIELS@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', (string) $user->fresh()?->password));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNewPasswords(): array
    {
        return [
            'too short' => ['short', 'short'],
            'not confirmed' => ['brand-new-password', 'something-else'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('invalidNewPasswords')]
    public function test_new_password_is_validated(string $password, string $confirmation): void
    {
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()?->password));
    }

    public function test_logged_in_users_cannot_see_the_forgot_password_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/forgot-password')
            ->assertRedirect('/dashboard');
    }

    public function test_password_cannot_be_reset_with_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()?->password));
    }
}
