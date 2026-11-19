<?php

namespace Tests\Feature\Layout;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ISSUE-35: the four-pane app shell (components/layouts/app.blade.php):
 * server rail, channel (or friends / DM) sidebar, messages, member list.
 */
class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Server $alpha;

    private Server $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->alpha = app(CreateServer::class)->create($this->alice, 'Alpha Base');
        $this->beta = app(CreateServer::class)->create($this->bob, 'Beta Camp');
        ServerMember::factory()->create([
            'server_id' => $this->beta->id,
            'user_id' => $this->alice->id,
            'joined_at' => now()->subDay(),
        ]);
    }

    private function generalOf(Server $server): Channel
    {
        /** @var Channel $channel */
        $channel = $server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();

        return $channel;
    }

    /** The HTML of the rail alone. */
    private function rail(string $html): string
    {
        $this->assertSame(1, preg_match('#<nav\s+aria-label="Servers".*?</nav>#s', $html, $match), 'No server rail on the page.');

        return $match[0];
    }

    private function page(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    public function test_a_channel_page_has_the_four_panes_in_order(): void
    {
        $html = $this->page($this->alice, route('channels.show', [$this->alpha, $this->generalOf($this->alpha)]));

        $positions = array_map(fn (string $needle) => strpos($html, $needle), [
            'data-server-rail',
            'data-channel-sidebar',
            '<main',
            'data-member-list',
        ]);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'The panes are not rail, channels, main, members.');
    }

    public function test_the_rail_lists_only_the_viewers_servers_by_name_and_marks_the_open_one(): void
    {
        $other = app(CreateServer::class)->create($this->bob, 'Gamma Secret');

        $rail = $this->rail($this->page($this->alice, route('servers.show', $this->beta)));

        $this->assertStringContainsString('title="Alpha Base"', $rail);
        $this->assertStringContainsString('title="Beta Camp"', $rail);
        $this->assertStringNotContainsString('Gamma Secret', $rail);
        $this->assertStringNotContainsString('href="'.route('servers.show', $other).'"', $rail);
        $this->assertLessThan(strpos($rail, 'Beta Camp'), strpos($rail, 'Alpha Base'));

        // Only the open server is the current page.
        $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('servers.show', $this->beta), '#').'"\s+title="Beta Camp"\s+aria-current="page"#', $rail);
    }

    public function test_server_subpages_mark_their_server(): void
    {
        $rail = $this->rail($this->page($this->alice, route('servers.search', [$this->alpha, 'q' => 'hello'])));

        $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#title="Alpha Base"\s+aria-current="page"#', $rail);
    }

    public function test_home_is_current_on_the_friends_dm_and_notification_pages(): void
    {
        $dm = Conversation::factory()->between($this->alice, $this->bob)->create();

        foreach ([route('friends.index'), route('dms.show', $dm), route('notifications.index')] as $url) {
            $rail = $this->rail($this->page($this->alice, $url));

            $this->assertSame(1, substr_count($rail, 'aria-current="page"'), $url);
            $this->assertMatchesRegularExpression('#title="Home"\s+aria-current="page"#', $rail, $url);
            $this->assertStringContainsString('data-brand-mark', $rail);
            $this->assertStringContainsString('Home: friends and direct messages', $rail);
        }
    }

    public function test_the_rail_shows_mention_badges_then_unread_dots(): void
    {
        $general = $this->generalOf($this->beta);
        Message::factory()->inChannel($general)->create(['user_id' => $this->bob->id, 'content' => 'hey @alice']);

        $rail = $this->rail($this->page($this->alice, route('friends.index')));
        $this->assertStringContainsString('data-server-mentions>1<span class="sr-only"> mention</span>', $rail);
        $this->assertStringNotContainsString('data-server-unread', $rail);

        $this->actingAs($this->alice)->postJson(route('channels.read', [$this->beta, $general]))->assertOk();
        Message::factory()->inChannel($general)->create(['user_id' => $this->bob->id, 'content' => 'no ping']);

        $rail = $this->rail($this->page($this->alice, route('friends.index')));
        $this->assertStringNotContainsString('data-server-mentions', $rail);
        $this->assertSame(1, substr_count($rail, 'data-server-unread'));
        $this->assertStringContainsString('(unread)', $rail);
    }

    public function test_the_open_server_gets_no_unread_dot(): void
    {
        Message::factory()->inChannel($this->generalOf($this->beta))->create(['user_id' => $this->bob->id, 'content' => 'no ping']);
        $random = $this->beta->channels()->create(['name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 50]);

        // Opening #random leaves #general unread, but you are in the server already.
        $rail = $this->rail($this->page($this->alice, route('channels.show', [$this->beta, $random])));

        $this->assertStringNotContainsString('data-server-unread', $rail);
    }

    public function test_the_rail_links_to_creating_a_server(): void
    {
        $rail = $this->rail($this->page($this->alice, route('friends.index')));

        $this->assertStringContainsString('href="'.route('servers.create').'"', $rail);
        $this->assertStringContainsString('data-rail-create', $rail);
    }

    public function test_the_rail_is_there_without_servers(): void
    {
        $rail = $this->rail($this->page(User::factory()->create(), route('friends.index')));

        $this->assertStringNotContainsString('data-rail-servers', $rail);
        $this->assertStringContainsString('data-rail-home', $rail);
        $this->assertStringContainsString('data-rail-create', $rail);
    }

    public function test_unverified_users_and_guests_get_no_rail(): void
    {
        $render = fn (): string => Blade::render('<x-layouts.app title="Test">content</x-layouts.app>');

        $this->assertStringNotContainsString('data-server-rail', $render());

        $this->actingAs(User::factory()->unverified()->create());
        $this->assertStringNotContainsString('data-server-rail', $render());

        $this->actingAs($this->alice);
        $this->assertStringContainsString('data-server-rail', $render());
    }

    public function test_the_friends_sidebar_no_longer_repeats_the_server_list(): void
    {
        $html = $this->page($this->alice, route('friends.index'));

        $this->assertStringNotContainsString('Your servers', $html);
        // Each server is linked once: from the rail.
        $this->assertSame(1, substr_count($html, 'href="'.route('servers.show', $this->alpha).'"'));
    }

    public function test_server_names_are_escaped_in_the_rail(): void
    {
        $this->alpha->forceFill(['name' => '<b>"Bold"</b>'])->save();

        $rail = $this->rail($this->page($this->alice, route('friends.index')));

        $this->assertStringNotContainsString('<b>', $rail);
        $this->assertStringContainsString('title="&lt;b&gt;&quot;Bold&quot;&lt;/b&gt;"', $rail);
    }
}
