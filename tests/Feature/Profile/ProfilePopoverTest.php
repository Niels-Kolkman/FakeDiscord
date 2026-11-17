<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE-8: clicking an avatar opens a profile popover fed by a JSON endpoint.
 */
class ProfilePopoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_profiles(): void
    {
        $user = User::factory()->create();

        $this->get("/users/{$user->username}/profile")->assertRedirect('/login');
        $this->getJson("/users/{$user->username}/profile")->assertUnauthorized();
    }

    public function test_signed_in_users_can_read_another_users_profile_card(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create([
            'name' => 'Niels',
            'username' => 'niels',
            'bio' => 'Hi <b>there</b>',
            'status' => 'online',
        ]);

        $this->actingAs($viewer)
            ->getJson('/users/niels/profile')
            ->assertOk()
            ->assertExactJson([
                'name' => 'Niels',
                'username' => 'niels',
                'bio' => 'Hi <b>there</b>',
                'status' => 'online',
                'avatar_url' => $user->avatarUrl(),
                'member_since' => $user->created_at?->toIso8601String(),
            ]);
    }

    public function test_the_profile_card_never_exposes_private_fields(): void
    {
        $user = User::factory()->create(['email' => 'secret@example.test']);

        $response = $this->actingAs($user)->getJson("/users/{$user->username}/profile");

        $response->assertJsonMissingPath('email')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token')
            ->assertDontSee('secret@example.test');
    }

    public function test_unknown_usernames_return_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/users/nobody-here/profile')
            ->assertNotFound();
    }

    public function test_the_dashboard_avatar_opens_the_popover(): void
    {
        $user = User::factory()->create(['username' => 'niels']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-profile-url="'.route('users.profile', $user).'"', false)
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('src="data:image/svg+xml;base64,', false)
            ->assertSee('href="'.route('profile.edit').'"', false);
    }

    public function test_the_popover_script_is_part_of_the_app_bundle(): void
    {
        $this->assertStringContainsString("import './profile-popover';", (string) file_get_contents(resource_path('js/app.js')));

        $script = (string) file_get_contents(resource_path('js/profile-popover.js'));
        $this->assertStringContainsString('[data-profile-url]', $script);
        // User-supplied text must never be injected as HTML.
        $this->assertStringNotContainsString('innerHTML', $script);
    }
}
