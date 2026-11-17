<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ISSUE-9: changing the password from account settings (Fortify PUT /user/password).
 */
class UpdatePasswordTest extends TestCase
{
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
}
