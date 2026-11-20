<?php

namespace Tests\Feature\Layout;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Edge cases for the server rail (components/server-rail.blade.php)
 * and the app shell around it; the basics are in AppLayoutTest.
 */
class ServerRailEdgeCaseTest extends TestCase
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
        $this->join($this->beta, $this->alice);
    }

    private function join(Server $server, User $user): void
    {
        ServerMember::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'joined_at' => now()->subDay(),
        ]);
    }

    private function generalOf(Server $server): Channel
    {
        /** @var Channel $channel */
        $channel = $server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();

        return $channel;
    }

    private function rail(string $html): string
    {
        $this->assertSame(1, preg_match('#<nav\s+aria-label="Servers".*?</nav>#s', $html, $match), 'No server rail on the page.');

        return $match[0];
    }

    private function page(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    /** The <li> of one server in the rail. */
    private function railItem(string $rail, Server $server): string
    {
        $this->assertSame(
            1,
            preg_match('#<li[^>]*data-rail-server="'.$server->id.'".*?</li>#s', $rail, $match),
            "Server {$server->id} is not in the rail."
        );

        return $match[0];
    }

    /** The height class (h-N) of the pill: the first aria-hidden span in $html. */
    private function pillHeight(string $html): string
    {
        $this->assertSame(1, preg_match('#<span aria-hidden="true" class="[^"]*?\s(h-\d+)[\s"]#', $html, $match));

        return $match[1];
    }

    /** The Home item: from its pill to the end of its link. */
    private function homeItem(string $rail): string
    {
        $this->assertSame(1, preg_match('#<span aria-hidden="true"[^>]*></span>\s*<a\s[^>]*data-rail-home.*?</a>#s', $rail, $match));

        return $match[0];
    }

    public function test_pins_and_audit_log_pages_mark_their_server(): void
    {
        $urls = [
            route('pins.index', [$this->alpha, $this->generalOf($this->alpha)]),
            route('audit-log.index', $this->alpha),
        ];

        foreach ($urls as $url) {
            $rail = $this->rail($this->page($this->alice, $url));

            $this->assertSame(1, substr_count($rail, 'aria-current="page"'), $url);
            $this->assertMatchesRegularExpression('#title="Alpha Base"\s+aria-current="page"#', $rail, $url);
            $this->assertDoesNotMatchRegularExpression('#title="Home"\s+aria-current#', $rail, $url);
        }
    }

    public function test_a_group_dm_marks_home(): void
    {
        $group = Conversation::factory()->group()->create(['owner_id' => $this->alice->id, 'name' => 'Squad']);
        $group->users()->attach([$this->alice->id, $this->bob->id]);

        $rail = $this->rail($this->page($this->alice, route('dms.show', $group)));

        $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#title="Home"\s+aria-current="page"#', $rail);
    }

    public function test_the_rail_is_sorted_by_name_not_by_join_order(): void
    {
        $zulu = app(CreateServer::class)->create($this->alice, 'Zulu Zone');
        $aaron = app(CreateServer::class)->create($this->bob, 'Aaron Arena');
        $this->join($aaron, $this->alice);

        $rail = $this->rail($this->page($this->alice, route('friends.index')));

        preg_match_all('#data-rail-server="(\d+)"#', $rail, $matches);
        $this->assertSame(
            [$aaron->id, $this->alpha->id, $this->beta->id, $zulu->id],
            array_map('intval', $matches[1])
        );
    }

    public function test_mentions_across_channels_add_up_and_use_the_plural(): void
    {
        $general = $this->generalOf($this->beta);
        $random = $this->beta->channels()->create(['name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 50]);
        Message::factory()->inChannel($general)->create(['user_id' => $this->bob->id, 'content' => 'hey @alice']);
        Message::factory()->inChannel($random)->create(['user_id' => $this->bob->id, 'content' => '@alice look']);

        $item = $this->railItem($this->rail($this->page($this->alice, route('friends.index'))), $this->beta);

        $this->assertStringContainsString('data-server-mentions>2<span class="sr-only"> mentions</span>', $item);
        $this->assertStringNotContainsString('data-server-unread', $item);
    }

    public function test_the_open_server_keeps_its_mention_badge(): void
    {
        // A mention in #general while you have #random open.
        Message::factory()->inChannel($this->generalOf($this->beta))->create(['user_id' => $this->bob->id, 'content' => 'hey @alice']);
        $random = $this->beta->channels()->create(['name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 50]);

        $item = $this->railItem($this->rail($this->page($this->alice, route('channels.show', [$this->beta, $random]))), $this->beta);

        $this->assertStringContainsString('aria-current="page"', $item);
        $this->assertStringContainsString('data-server-mentions>1<', $item);
    }

    public function test_the_pill_is_tall_when_open_short_when_unread_and_hidden_otherwise(): void
    {
        $gamma = app(CreateServer::class)->create($this->bob, 'Gamma Grove');
        $this->join($gamma, $this->alice);
        Message::factory()->inChannel($this->generalOf($gamma))->create(['user_id' => $this->bob->id, 'content' => 'no ping']);

        $rail = $this->rail($this->page($this->alice, route('servers.show', $this->alpha)));

        $this->assertSame('h-10', $this->pillHeight($this->railItem($rail, $this->alpha)));
        $this->assertSame('h-0', $this->pillHeight($this->railItem($rail, $this->beta)));
        $this->assertSame('h-2', $this->pillHeight($this->railItem($rail, $gamma)));
        $this->assertStringContainsString('data-server-unread', $this->railItem($rail, $gamma));
        $this->assertStringNotContainsString('data-server-unread', $this->railItem($rail, $this->beta));
    }

    public function test_the_home_pill_is_tall_only_on_home_pages(): void
    {
        $onHome = $this->homeItem($this->rail($this->page($this->alice, route('friends.index'))));
        $onServer = $this->homeItem($this->rail($this->page($this->alice, route('servers.show', $this->alpha))));

        $this->assertSame('h-10', $this->pillHeight($onHome));
        $this->assertSame('h-0', $this->pillHeight($onServer));
        $this->assertStringNotContainsString('aria-current', $onServer);
    }

    public function test_a_server_you_leave_drops_out_of_the_rail(): void
    {
        app(LeaveServer::class)->leave($this->beta, $this->alice);

        $rail = $this->rail($this->page($this->alice, route('friends.index')));

        $this->assertStringNotContainsString('Beta Camp', $rail);
        $this->assertStringNotContainsString('data-rail-server="'.$this->beta->id.'"', $rail);
        $this->assertStringContainsString('data-rail-server="'.$this->alpha->id.'"', $rail);
    }

    public function test_a_dm_page_has_rail_sidebar_and_main_but_no_server_panes(): void
    {
        $dm = Conversation::factory()->between($this->alice, $this->bob)->create();

        $html = $this->page($this->alice, route('dms.show', $dm));

        $rail = strpos($html, 'data-server-rail');
        $main = strpos($html, '<main');
        $this->assertNotFalse($rail);
        $this->assertNotFalse($main);
        $this->assertLessThan($main, $rail);
        $this->assertStringNotContainsString('data-member-list', $html);
        $this->assertStringNotContainsString('data-channel-sidebar', $html);
    }

    /** On small screens the rail is in the navigation drawer, still a column. */
    public function test_the_rail_is_a_72px_column_on_every_screen_size(): void
    {
        $rail = $this->rail($this->page($this->alice, route('friends.index')));

        $this->assertSame(1, preg_match('#<nav\s[^>]*class="([^"]*)"#', $rail, $m));
        $classes = explode(' ', $m[1]);
        foreach (['flex-col', 'w-[72px]', 'overflow-y-auto'] as $class) {
            $this->assertContains($class, $classes, $class);
        }
        foreach (['flex-row', 'overflow-x-auto', 'md:flex-col'] as $class) {
            $this->assertNotContains($class, $classes, $class);
        }
    }

    /**
     * The rail's own queries (its server list, the channels, unread counts
     * and mentions) are batched. Not covered: the permission lookups
     * (server_members, roles, channel_permission_overrides) still run once
     * per server you do not own, because UnreadState::forServers calls
     * PermissionService::visibleChannels per server (shared with
     * the dashboard). That is a known N+1 and is excluded here.
     */
    public function test_rail_list_and_unread_queries_do_not_grow_with_the_number_of_servers(): void
    {
        $permissionLookup = '#from "(server_members|roles|channel_permission_overrides)" where#';

        $count = function () use ($permissionLookup): int {
            $this->actingAs($this->alice);
            DB::flushQueryLog();
            DB::enableQueryLog();
            Blade::render('<x-server-rail />');
            $queries = array_filter(
                array_column(DB::getQueryLog(), 'query'),
                fn (string $sql): bool => preg_match($permissionLookup, $sql) !== 1,
            );
            DB::disableQueryLog();

            return count($queries);
        };

        // A mention up front too, so both counts include the mentions query.
        Message::factory()->inChannel($this->generalOf($this->beta))->create(['user_id' => $this->bob->id, 'content' => 'hey @alice']);
        $few = $count();

        foreach (range(1, 5) as $i) {
            $server = app(CreateServer::class)->create($this->bob, "Extra {$i}");
            $this->join($server, $this->alice);
            Message::factory()->inChannel($this->generalOf($server))->create(['user_id' => $this->bob->id, 'content' => 'hey @alice']);
        }

        $this->assertSame($few, $count(), 'The rail runs list or unread queries per server.');
    }

    public function test_the_brand_mark_is_decorative_and_sized(): void
    {
        $svg = Blade::render('<x-brand-mark :size="40" class="text-red-500" />');

        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('focusable="false"', $svg);
        $this->assertStringContainsString('width="40"', $svg);
        $this->assertStringContainsString('height="40"', $svg);
        $this->assertStringContainsString('class="shrink-0 text-red-500"', $svg);
        $this->assertStringContainsString('data-brand-mark', $svg);

        $this->assertStringContainsString('width="28"', Blade::render('<x-brand-mark />'));
    }

    public function test_the_shell_uses_original_branding(): void
    {
        $rail = $this->rail($this->page($this->alice, route('friends.index')));
        $home = $this->homeItem($rail);

        $this->assertStringNotContainsStringIgnoringCase('discord', $rail);
        $this->assertStringNotContainsStringIgnoringCase('discord', Blade::render('<x-brand-mark />'));
        // Home's logo is the inline SVG mark, not an external image.
        $this->assertStringNotContainsString('<img', $home);
        $this->assertStringContainsString('<svg', $home);
    }
}
