<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Users log in with email + password and can log out.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders_the_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee(route('password.request'), false)
            ->assertSee(route('register'), false);
    }

    public function test_users_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'niels@example.test']);

        $this->post('/login', ['email' => 'NIELS@example.test', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_log_in_with_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_cannot_log_in_with_an_unknown_email(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->post('/login', [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    public function test_failed_login_shows_the_error_on_the_form(): void
    {
        $user = User::factory()->create();

        $this->followingRedirects()
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee(trans('auth.failed'))
            ->assertSee('value="'.$user->email.'"', false);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_shows_the_logged_in_user(): void
    {
        $user = User::factory()->create(['name' => 'Niels', 'username' => 'niels']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Niels')
            ->assertSee('@niels')
            ->assertSee('action="'.route('logout').'"', false);
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_login_regenerates_the_session(): void
    {
        $user = User::factory()->create();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_remember_me_sets_a_remember_token_and_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $this->assertNotNull($user->fresh()?->remember_token);
        $response->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_without_remember_me_no_recaller_cookie_is_set(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_login_redirects_to_the_intended_url(): void
    {
        $user = User::factory()->create();

        $this->get('/dashboard')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(url('/dashboard'));
    }

    public function test_logged_in_users_cannot_see_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/dashboard');
    }

    public function test_rate_limit_ignores_email_casing(): void
    {
        $user = User::factory()->create(['email' => 'niels@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $email = $i % 2 === 0 ? 'NIELS@example.test' : 'niels@example.test';
            $this->post('/login', ['email' => $email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_per_email(): void
    {
        $locked = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $locked->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $other->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($other);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['secret' => 'value']);
        $this->get('/dashboard');
        $tokenBefore = session()->token();

        $this->post('/logout');

        $this->assertGuest();
        $this->assertNull(session('secret'));
        $this->assertNotSame($tokenBefore, session()->token());
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_guests_cannot_log_out(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/logout')
            ->assertMethodNotAllowed();

        $this->assertAuthenticated();
    }
}
