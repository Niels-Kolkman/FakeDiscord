<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE-9: the account settings page.
 */
class AccountSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/account')->assertRedirect('/login');
        $this->put('/settings/account/email', ['email' => 'x@example.test'])->assertRedirect('/login');
        $this->delete('/settings/account', ['password' => 'password'])->assertRedirect('/login');
        $this->put('/user/password', [])->assertRedirect('/login');
    }

    public function test_page_renders_all_three_forms_with_password_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/account')
            ->assertOk()
            ->assertViewIs('settings.account')
            ->assertSee($user->email)
            ->assertSee('action="'.route('settings.account.email').'"', false)
            ->assertSee('action="'.route('user-password.update').'"', false)
            ->assertSee('action="'.route('settings.account.destroy').'"', false)
            ->assertSee('id="email_current_password"', false)
            ->assertSee('id="password_current_password"', false)
            ->assertSee('id="delete_password"', false);
    }

    public function test_unverified_users_can_open_settings_to_fix_their_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/settings/account')
            ->assertOk()
            ->assertSee('not verified');
    }

    public function test_dashboard_links_to_settings(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertSee(route('settings.account'), false);
    }

    public function test_errors_show_only_under_the_form_that_failed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->delete('/settings/account', ['password' => 'wrong'])
            ->assertSee('id="delete_password-error"', false)
            ->assertDontSee('id="password-error"', false)
            ->assertDontSee('id="email_current_password-error"', false);
    }
}
