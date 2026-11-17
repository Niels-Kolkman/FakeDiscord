<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ISSUE-6: users who forgot their password can reset it via an emailed link.
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
