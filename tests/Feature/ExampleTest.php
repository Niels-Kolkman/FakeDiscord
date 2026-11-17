<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE-44: the root URL never shows the default Laravel page.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_visiting_the_root_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_signed_in_users_visiting_the_root_are_redirected_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_following_the_root_redirect_as_a_guest_shows_the_login_page(): void
    {
        $this->withoutVite();

        $this->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertDontSee('laravel.com', false);
    }

    public function test_the_default_welcome_view_is_gone(): void
    {
        $this->assertFalse(view()->exists('welcome'));
    }
}
