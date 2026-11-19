<?php

namespace Tests\Feature\Layout;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
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
}
