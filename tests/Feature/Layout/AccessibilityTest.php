<?php

namespace Tests\Feature\Layout;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE-38: the markup the accessibility scripts rely on: the skip link,
 * the new-message announcer (resources/js/live-announcer.js) and the
 * shortcut help for resources/js/keyboard-shortcuts.js. Colours are in
 * ContrastTest.
 */
class AccessibilityTest extends TestCase
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

    public function test_the_skip_link_is_the_first_thing_in_the_body_and_targets_main(): void
    {
        $html = $this->channelPage();

        $this->assertSame(1, preg_match('#<body[^>]*>\s*<a href="\#main-content"([^>]*)>Skip to content</a>#s', $html, $link));
        $this->assertStringContainsString('sr-only', $link[1]);
        $this->assertStringContainsString('focus:not-sr-only', $link[1]);
        $this->assertStringContainsString('data-skip-link', $link[1]);

        $this->assertMatchesRegularExpression('#<main id="main-content" tabindex="-1"[^>]*data-app-main>#', $html);
        $this->assertSame(1, substr_count($html, 'id="main-content"'));
    }

    public function test_the_message_list_is_quiet_and_new_messages_go_to_the_announcer(): void
    {
        $html = $this->channelPage();

        $this->assertMatchesRegularExpression('#<ol role="log" aria-live="off" aria-label="Messages in \#[^"]+" data-message-list>#', $html);
        $this->assertSame(1, substr_count($html, '<div class="sr-only" aria-live="polite" aria-relevant="additions" data-message-announcer></div>'));

        // Inside the panel that channel-messages.js drives.
        $this->assertGreaterThan(strpos($html, 'data-channel-messages'), strpos($html, 'data-message-announcer'));
    }

    public function test_dms_have_the_announcer_too(): void
    {
        $dm = Conversation::factory()->between($this->alice, User::factory()->create())->create();

        $html = $this->page(route('dms.show', $dm));

        $this->assertStringContainsString('data-message-announcer', $html);
        $this->assertStringContainsString('role="log" aria-live="off"', $html);
    }

    public function test_the_sidebars_describe_the_channel_shortcuts(): void
    {
        $html = $this->channelPage();
        $this->assertMatchesRegularExpression('#<nav\s+aria-label="Alpha Base channels"\s+aria-describedby="channel-shortcuts-help"#', $html);
        $this->assertStringContainsString('<p id="channel-shortcuts-help" class="sr-only">Alt+Up and Alt+Down switch channels, Alt+Shift+Up and Alt+Shift+Down go to unread channels.</p>', $html);

        $html = $this->page(route('friends.index'));
        $this->assertStringContainsString('aria-describedby="dm-shortcuts-help"', $html);
        $this->assertStringContainsString('<p id="dm-shortcuts-help" class="sr-only">Alt+Up and Alt+Down switch between direct messages.</p>', $html);
    }

    // --- Further edge cases (ISSUE-38 test pass) ---

    /**
     * Every aria-describedby / aria-labelledby on the page points at an id
     * that is there exactly once.
     */
    private function assertAriaReferencesResolve(string $html): void
    {
        preg_match_all('#aria-(?:describedby|labelledby)="([^"]+)"#', $html, $refs);
        $this->assertNotEmpty($refs[1]);
        foreach (array_unique(preg_split('/\s+/', implode(' ', $refs[1]))) as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "id \"{$id}\" should exist exactly once");
        }
    }

    public function test_the_skip_link_and_its_target_are_on_every_app_page_once(): void
    {
        $dm = Conversation::factory()->between($this->alice, User::factory()->create())->create();

        // (The dashboard uses the auth layout: no navigation to skip.)
        $voice = $this->server->channels()->create(['name' => 'Lounge', 'type' => Channel::TYPE_VOICE, 'position' => 60]);
        foreach ([route('friends.index'), route('dms.show', $dm), route('channels.show', [$this->server, $voice])] as $url) {
            $html = $this->page($url);
            $this->assertSame(1, substr_count($html, 'data-skip-link'), $url);
            $this->assertSame(1, substr_count($html, 'href="#main-content"'), $url);
            $this->assertSame(1, substr_count($html, 'id="main-content"'), $url);
        }
    }

    public function test_the_skip_link_comes_before_the_navigation_drawer(): void
    {
        $html = $this->channelPage();

        $this->assertLessThan(strpos($html, 'data-drawer="nav"'), strpos($html, 'data-skip-link'));
        $this->assertLessThan(strpos($html, 'data-channel-sidebar'), strpos($html, 'data-skip-link'));
    }

    public function test_the_shortcut_help_and_other_aria_references_resolve_on_channel_friends_and_dm_pages(): void
    {
        $dm = Conversation::factory()->between($this->alice, User::factory()->create())->create();

        $this->assertAriaReferencesResolve($this->channelPage());
        $this->assertAriaReferencesResolve($this->page(route('friends.index')));

        $html = $this->page(route('dms.show', $dm));
        $this->assertAriaReferencesResolve($html);
        $this->assertStringContainsString('aria-describedby="dm-shortcuts-help"', $html);
    }

    public function test_members_who_cannot_reorder_get_the_shortcut_help_too(): void
    {
        $bob = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $bob->id, 'nickname' => null]);
        $channel = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();

        $html = (string) $this->actingAs($bob)->get(route('channels.show', [$this->server, $channel]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-layout-url', $html, 'Alt+arrows are never reorder keys here');
        $this->assertStringContainsString('aria-describedby="channel-shortcuts-help"', $html);
        $this->assertStringContainsString('id="channel-shortcuts-help"', $html);
    }

    public function test_the_open_channel_link_is_marked_current_for_the_shortcuts(): void
    {
        $channel = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $href = preg_quote(route('channels.show', [$this->server, $channel]), '#');
        $html = $this->channelPage();

        // keyboard-shortcuts.js steps from the channel link with
        // aria-current="page" (the server rail marks its icon too).
        $start = strpos($html, 'data-channel-sidebar');
        $sidebar = substr($html, $start, strpos($html, '</nav>', $start) - $start);
        $this->assertSame(1, preg_match_all('#<a[^>]*aria-current="page"#', $sidebar));
        $this->assertMatchesRegularExpression('#<a[^>]*href="'.$href.'"[^>]*aria-current="page"|<a[^>]*aria-current="page"[^>]*href="'.$href.'"#', $sidebar);
    }

    public function test_a_voice_channel_has_no_message_announcer(): void
    {
        $voice = $this->server->channels()->create(['name' => 'Lounge', 'type' => Channel::TYPE_VOICE, 'position' => 60]);

        $html = $this->page(route('channels.show', [$this->server, $voice]));

        $this->assertStringNotContainsString('data-message-announcer', $html);
        $this->assertStringContainsString('data-skip-link', $html);
    }

    public function test_one_announcer_per_message_panel(): void
    {
        $this->assertSame(1, substr_count($this->channelPage(), 'data-message-announcer'));
    }

    public function test_the_inbox_is_a_labelled_dialog_hidden_until_opened(): void
    {
        $html = $this->channelPage();

        $this->assertSame(1, preg_match('#<div\s+id="inbox-panel"\s+role="dialog"\s+aria-labelledby="inbox-panel-title"[^>]*data-inbox-panel\s+hidden\s*>#', $html));
        $this->assertStringContainsString('id="inbox-panel-title"', $html);
    }
}
