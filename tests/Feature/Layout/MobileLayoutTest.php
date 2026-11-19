<?php

namespace Tests\Feature\Layout;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ISSUE-37: below md the app shell's panes are drawers
 * (components/layouts/app.blade.php, resources/js/mobile-nav.js), and the
 * neutral colours are theme tokens (resources/css/app.css).
 */
class MobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->server = app(CreateServer::class)->create($this->alice, 'Alpha Base');
    }

    private function page(string $url): string
    {
        return (string) $this->actingAs($this->alice)->get($url)->assertOk()->getContent();
    }

    private function channelPage(): string
    {
        $channel = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();

        return $this->page(route('channels.show', [$this->server, $channel]));
    }

    public function test_the_rail_and_channel_sidebar_share_the_nav_drawer_and_the_members_have_their_own(): void
    {
        $html = $this->channelPage();

        $positions = [
            strpos($html, 'data-drawer="nav"'),
            strpos($html, 'data-server-rail'),
            strpos($html, 'data-channel-sidebar'),
            strpos($html, 'data-app-main'),
            strpos($html, 'data-drawer="members"'),
            strpos($html, 'data-member-list'),
            strpos($html, 'data-drawer-backdrop'),
        ];
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        $this->assertMatchesRegularExpression('#id="app-nav-drawer"[^>]*data-drawer="nav"#', $html);
        $this->assertMatchesRegularExpression('#id="app-members-drawer"[^>]*data-drawer="members"#', $html);
        $this->assertSame(1, substr_count($html, 'data-drawer="nav"'));
        $this->assertSame(1, substr_count($html, 'data-drawer="members"'));
    }

    public function test_the_mobile_bar_has_buttons_for_both_drawers(): void
    {
        $html = $this->channelPage();

        $this->assertSame(1, preg_match('#<div[^>]*data-mobile-bar>.*?</div>#s', $html, $bar));
        $this->assertMatchesRegularExpression('#<div class="hidden [^"]*" data-mobile-bar>#', $html, 'The bar must be hidden unless the small-screen CSS shows it.');

        $this->assertMatchesRegularExpression('#<button type="button"[^>]*aria-controls="app-nav-drawer" aria-expanded="false"\s+data-drawer-toggle="nav"#', $bar[0]);
        $this->assertStringContainsString('<span class="sr-only">Open navigation</span>', $bar[0]);
        $this->assertMatchesRegularExpression('#<button type="button"[^>]*aria-controls="app-members-drawer" aria-expanded="false"\s+data-drawer-toggle="members"#', $bar[0]);
        $this->assertStringContainsString('<span class="sr-only">Open member list</span>', $bar[0]);
        $this->assertStringContainsString('· Alpha Base</span>', $bar[0]);
    }

    public function test_pages_without_a_member_list_have_no_members_drawer_or_button(): void
    {
        $bob = User::factory()->create();
        $dm = Conversation::factory()->between($this->alice, $bob)->create();

        foreach ([route('friends.index'), route('dms.show', $dm), route('notifications.index')] as $url) {
            $html = $this->page($url);

            $this->assertStringContainsString('data-drawer="nav"', $html, $url);
            $this->assertStringContainsString('data-drawer-toggle="nav"', $html, $url);
            $this->assertStringNotContainsString('data-drawer="members"', $html, $url);
            $this->assertStringNotContainsString('data-drawer-toggle="members"', $html, $url);
        }
    }

    public function test_the_notifications_page_drawer_holds_just_the_rail(): void
    {
        $html = $this->page(route('notifications.index'));

        $drawer = strpos($html, 'data-drawer="nav"');
        $rail = strpos($html, 'data-server-rail');
        $this->assertNotFalse($drawer);
        $this->assertNotFalse($rail);
        $this->assertLessThan($rail, $drawer);
        $this->assertLessThan(strpos($html, 'data-app-main'), $rail);
        $this->assertStringNotContainsString('data-channel-sidebar', $html);
    }

    public function test_a_page_with_no_panes_has_no_drawers_bar_or_backdrop(): void
    {
        $html = Blade::render('<x-layouts.app title="Bare">content</x-layouts.app>');

        foreach (['data-drawer', 'data-mobile-bar', 'data-drawer-toggle', 'data-drawer-backdrop', 'data-server-rail'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('data-app-main', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
    }

    public function test_the_backdrop_starts_hidden_and_the_js_class_is_set_before_the_styles(): void
    {
        $html = $this->channelPage();

        $this->assertMatchesRegularExpression('#<div class="fixed inset-0 z-30 [^"]*md:hidden" hidden data-drawer-backdrop></div>#', $html);

        $script = strpos($html, "document.documentElement.classList.add('js')");
        $this->assertNotFalse($script);
        $this->assertLessThan(strpos($html, 'fonts.bunny.net/css'), $script);
        $this->assertStringContainsString('data-app-shell', $html);
    }

    public function test_sidebars_fill_the_drawer_beside_the_rail_and_are_240px_from_md(): void
    {
        $html = $this->channelPage();
        $this->assertSame(1, preg_match('#<nav\s+aria-label="Alpha Base channels"[^>]*?\s+class="([^"]*)"#', $html, $m));
        $classes = explode(' ', $m[1]);
        foreach (['min-w-0', 'flex-1', 'md:w-60', 'md:flex-none'] as $class) {
            $this->assertContains($class, $classes, $class);
        }
        $this->assertNotContains('w-full', $classes, 'w-full next to the 72px rail would overflow the drawer.');

        $html = $this->page(route('friends.index'));
        $this->assertSame(1, preg_match('#<nav aria-label="Navigation"[^>]*?\s+class="([^"]*)"#', $html, $m));
        $this->assertNotContains('w-full', explode(' ', $m[1]));
    }

    /**
     * The neutral greys that the light theme swaps must not come back as
     * hard-coded hex classes, or those spots stay dark in the light theme.
     */
    public function test_views_and_scripts_use_theme_tokens_for_neutral_colours(): void
    {
        $neutral = ['313338', '2b2d31', '1e1f22', '232428', '111214', '2e3035', '35373c', '35363c', '383a40', '404249', '41434a', '3f4147', '1f2023', 'dbdee1', 'b5bac1', '949ba4', '80848e', '00a8fc', 'fa777c', 'abb1b8', '8c9099', '37bbfd', 'fb9296'];
        $pattern = '/[a-z-]+-\[#('.implode('|', $neutral).')\]/i';

        $offenders = [];
        $files = Finder::create()->files()->in([resource_path('views'), resource_path('js')])->name(['*.blade.php', '*.js']);
        foreach ($files as $file) {
            if (preg_match_all($pattern, $file->getContents(), $m)) {
                $offenders[] = $file->getRelativePathname().': '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_css_defines_every_token_for_both_themes(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertSame(1, preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $dark));
        $this->assertSame(1, preg_match("/:root\[data-theme='light'\]\s*\{(.*?)\}/s", $css, $light));

        preg_match_all('/--color-([a-z0-9-]+):/', $dark[1], $darkTokens);
        preg_match_all('/--color-([a-z0-9-]+):/', $light[1], $lightTokens);

        $this->assertNotEmpty($darkTokens[1]);
        $this->assertEqualsCanonicalizing($darkTokens[1], $lightTokens[1]);

        // Every token class the views and scripts use is defined.
        $used = [];
        $files = Finder::create()->files()->in([resource_path('views'), resource_path('js')])->name(['*.blade.php', '*.js']);
        foreach ($files as $file) {
            preg_match_all('/(?<![\w-])(?:bg|text|border|ring|ring-offset|divide|placeholder)-((?:surface|hover|field|active|line|edge|fg|link|danger|warn)(?:-[a-z0-9]+)*)(?![\w-])/', $file->getContents(), $m);
            array_push($used, ...$m[1]);
        }
        $this->assertNotEmpty($used);
        foreach (array_unique($used) as $token) {
            $this->assertContains($token, $darkTokens[1], "No --color-{$token} for the class that uses it.");
        }
    }
}
