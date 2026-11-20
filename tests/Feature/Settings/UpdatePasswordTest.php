<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\DocumentsKnownBugs;
use Tests\TestCase;

/**
 * Changing the password from account settings (Fortify PUT /user/password).
 */
class UpdatePasswordTest extends TestCase
{
    use DocumentsKnownBugs;
    use RefreshDatabase;

    public function test_password_can_be_changed_with_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertRedirect('/settings/account')
            ->assertSessionHas('status', 'password-updated')
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->put('/user/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertRedirect('/settings/account')
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_missing_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_confirmation_must_match(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_new_password_follows_the_default_password_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_user_can_log_in_with_the_new_password_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ]);
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'new-secret-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_success_message_is_shown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertSee('Your password has been changed.');
    }

    public function test_unverified_users_can_change_their_password(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
    }

    public function test_errors_are_shown_under_the_password_form_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->put('/user/password', [
                'current_password' => 'wrong',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertSee('id="password_current_password-error"', false)
            ->assertSee('The provided password does not match your current password.')
            ->assertDontSee('id="email_current_password-error"', false)
            ->assertDontSee('id="delete_password-error"', false);
    }

    public function test_same_password_shows_the_must_differ_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSee('The new password must be different from your current password.');
    }

    public function test_passwords_are_never_echoed_back_into_the_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->put('/user/password', [
                'current_password' => 'my-current-secret',
                'password' => 'new-secret-123',
                'password_confirmation' => 'mismatch-secret',
            ])
            ->assertDontSee('my-current-secret')
            ->assertDontSee('new-secret-123')
            ->assertDontSee('mismatch-secret');
    }

    public function test_non_string_current_password_is_a_validation_error_not_a_crash(): void
    {
        $user = User::factory()->create();

        $this->knownBug('array current_password reaches current_password rule and password_verify() throws a TypeError (500); the rule list needs `bail`.', function () use ($user) {
            $this->actingAs($user)
                ->from('/settings/account')
                ->put('/user/password', [
                    'current_password' => ['password'],
                    'password' => 'new-secret-123',
                    'password_confirmation' => 'new-secret-123',
                ])
                ->assertRedirect('/settings/account')
                ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        });

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
