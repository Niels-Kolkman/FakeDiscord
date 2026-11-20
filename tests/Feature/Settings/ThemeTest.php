<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The dark / light theme, saved per user (users.theme) and put
 * on <html data-theme> by both layouts; <x-theme-toggle> switches it.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function htmlTheme(string $html): ?string
    {
        return preg_match('#<html\b[^>]*\bdata-theme="([^"]*)"#', $html, $m) ? $m[1] : null;
    }

    public function test_new_users_get_the_dark_theme(): void
    {
        $user = User::factory()->create();

        $this->assertSame('dark', $user->fresh()->theme);
        $this->assertSame('dark', $user->preferredTheme());
        $this->assertSame('dark', $this->htmlTheme((string) $this->actingAs($user)->get(route('friends.index'))->getContent()));
    }

    public function test_guests_get_the_dark_theme(): void
    {
        $this->assertSame('dark', $this->htmlTheme((string) $this->get(route('login'))->assertOk()->getContent()));
    }

    public function test_a_plain_form_saves_the_theme_and_goes_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('friends.index'))
            ->put(route('theme.update'), ['theme' => 'light'])
            ->assertRedirect(route('friends.index'))
            ->assertSessionHas('status', 'Switched to the light theme.');

        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_the_script_gets_the_saved_theme_as_json(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('theme.update'), ['theme' => 'light'])
            ->assertOk()
            ->assertExactJson(['theme' => 'light']);

        $this->actingAs($user)
            ->putJson(route('theme.update'), ['theme' => 'dark'])
            ->assertExactJson(['theme' => 'dark']);

        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_the_saved_theme_is_on_every_page_of_both_layouts(): void
    {
        $user = User::factory()->create(['theme' => 'light']);

        foreach ([route('friends.index'), route('notifications.index'), route('dashboard'), route('settings.account')] as $url) {
            $html = (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame('light', $this->htmlTheme($html), $url);
        }
    }

    public function test_the_theme_is_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->putJson(route('theme.update'), ['theme' => 'light'])->assertOk();

        $this->assertSame('light', $alice->fresh()->theme);
        $this->assertSame('dark', $bob->fresh()->theme);
        $this->assertSame('dark', $this->htmlTheme((string) $this->actingAs($bob)->get(route('friends.index'))->getContent()));
    }

    public function test_unknown_or_missing_themes_are_refused(): void
    {
        $user = User::factory()->create();

        foreach ([['theme' => 'neon'], ['theme' => ''], [], ['theme' => ['light']], ['theme' => 'LIGHT']] as $payload) {
            $this->actingAs($user)
                ->putJson(route('theme.update'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('theme');
        }

        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_guests_cannot_save_a_theme(): void
    {
        $this->put(route('theme.update'), ['theme' => 'light'])->assertRedirect(route('login'));
        $this->putJson(route('theme.update'), ['theme' => 'light'])->assertUnauthorized();
    }

    public function test_unverified_users_can_still_pick_a_theme(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->putJson(route('theme.update'), ['theme' => 'light'])->assertOk();

        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_a_stored_unknown_theme_reads_as_dark(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['theme' => 'sepia']);

        $this->assertSame('dark', $user->fresh()->preferredTheme());
        $this->assertSame('dark', $this->htmlTheme((string) $this->actingAs($user->fresh())->get(route('friends.index'))->getContent()));
    }

    public function test_the_rail_toggle_offers_the_other_theme(): void
    {
        $user = User::factory()->create();

        $html = (string) $this->actingAs($user)->get(route('friends.index'))->getContent();
        $this->assertSame(1, preg_match('#<nav\s+aria-label="Servers".*?</nav>#s', $html, $rail));
        $this->assertSame(1, preg_match('#<form[^>]*data-theme-toggle.*?</form>#s', $rail[0], $toggle), 'No theme toggle in the rail.');

        $this->assertStringContainsString('action="'.route('theme.update').'"', $toggle[0]);
        $this->assertStringContainsString('name="_method" value="PUT"', $toggle[0]);
        $this->assertStringContainsString('name="_token"', $toggle[0]);
        $this->assertMatchesRegularExpression('#name="theme" value="light"#', $toggle[0]);
        $this->assertStringContainsString('title="Switch to light theme"', $toggle[0]);
        $this->assertStringContainsString('<span class="sr-only" data-theme-label>Switch to light theme</span>', $toggle[0]);

        $user->forceFill(['theme' => 'light'])->save();
        $html = (string) $this->actingAs($user)->get(route('friends.index'))->getContent();
        $this->assertMatchesRegularExpression('#name="theme" value="dark"[^>]*data-theme-next#', $html);
        $this->assertStringContainsString('title="Switch to dark theme"', $html);
    }

    public function test_the_dashboard_has_a_visible_toggle(): void
    {
        $user = User::factory()->create();

        $html = (string) $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-theme-toggle'));
        $this->assertStringContainsString('<span class="" data-theme-label>Switch to light theme</span>', $html);
    }

    public function test_guests_get_no_toggle(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-theme-toggle', false);
    }
}
