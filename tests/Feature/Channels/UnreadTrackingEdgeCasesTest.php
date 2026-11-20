<?php

namespace Tests\Feature\Channels;

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\Mentions;
use App\Support\UnreadState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases on top of UnreadTrackingTest: markers on deleted
 * messages, clamping, marker versus join date, viewers who are not (or no
 * longer) members, query counts when little is unread, mentions from
 * deleted / blocked authors and in deleted messages, dotted usernames,
 * forServers() boundaries and the singular / plural badge labels.
 */
class UnreadTrackingEdgeCasesTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private Server $server;

    private Channel $general;

    private Channel $random;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Edges');
        ServerMember::factory()->create([
            'server_id' => $this->server->id,
            'user_id' => $this->bob->id,
            'joined_at' => now()->subDay(),
        ]);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
        $this->random = $this->server->channels()->create([
            'name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 50,
        ]);
    }

    private function say(Channel $channel, string $content = 'hello', ?User $author = null): Message
    {
        return Message::factory()->inChannel($channel)->create([
            'user_id' => ($author ?? $this->owner)->id,
            'content' => $content,
        ]);
    }

    private function state(?User $viewer = null): UnreadState
    {
        $viewer ??= $this->bob;

        return UnreadState::forChannels($viewer, $this->server->channelLayout($viewer)->channels());
    }

    private function marker(Channel $channel, ?User $user = null): ?int
    {
        $value = ChannelRead::query()
            ->where('user_id', ($user ?? $this->bob)->id)
            ->where('channel_id', $channel->id)
            ->value('last_read_message_id');

        return $value === null ? null : (int) $value;
    }

    private function reads(): MarkChannelRead
    {
        return app(MarkChannelRead::class);
    }

    // --- MarkChannelRead -----------------------------------------------------

    public function test_a_deleted_newest_message_is_still_a_position(): void
    {
        $this->say($this->random);
        $gone = $this->say($this->random, 'oops');
        $gone->delete();

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertOk();

        $this->assertSame((int) $gone->id, $this->marker($this->random));

        $this->say($this->random, 'after');
        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    public function test_marking_up_to_a_deleted_message_is_allowed(): void
    {
        $gone = $this->say($this->random);
        $this->say($this->random);
        $gone->delete();

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => $gone->id])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $gone->id]);

        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    public function test_asking_to_go_back_returns_the_marker_that_is_kept(): void
    {
        $first = $this->say($this->random);
        $last = $this->say($this->random);
        $this->reads()->markRead($this->bob, $this->random);

        $this->assertSame((int) $last->id, $this->reads()->markRead($this->bob, $this->random, (int) $first->id));
        $this->assertSame((int) $last->id, $this->marker($this->random));
    }

    public function test_an_existing_marker_moves_forward_and_is_touched(): void
    {
        $first = $this->say($this->random);
        $last = $this->say($this->random);
        $read = ChannelRead::factory()->create([
            'user_id' => $this->bob->id,
            'channel_id' => $this->random->id,
            'last_read_message_id' => $first->id,
        ]);
        $before = $read->updated_at;

        $this->travel(5)->minutes();
        $this->assertSame((int) $last->id, $this->reads()->markRead($this->bob, $this->random));

        $read->refresh();
        $this->assertSame((int) $last->id, $read->last_read_message_id);
        $this->assertTrue($read->updated_at->greaterThan($before));
        $this->assertSame(1, ChannelRead::query()->count());
    }

    public function test_an_unchanged_marker_is_not_touched(): void
    {
        $this->say($this->random);
        $this->reads()->markRead($this->bob, $this->random);
        /** @var ChannelRead $read */
        $read = ChannelRead::query()->firstOrFail();
        $before = $read->updated_at;

        $this->travel(5)->minutes();
        $this->reads()->markRead($this->bob, $this->random);

        $this->assertEquals($before, $read->refresh()->updated_at);
    }

    public function test_up_to_is_clamped_between_zero_and_the_newest_message(): void
    {
        $this->say($this->random);

        $this->assertSame(0, $this->reads()->markRead($this->bob, $this->random, -5));
        $this->assertSame(0, $this->marker($this->random));
    }

    public function test_marking_one_channel_leaves_the_others_alone(): void
    {
        $this->say($this->general);
        $this->say($this->random);

        $this->reads()->markRead($this->bob, $this->random);

        $this->assertNull($this->marker($this->general));
        $this->assertTrue($this->state()->isUnread($this->general));
    }

    // --- Marker versus join date ---------------------------------------------

    public function test_with_a_marker_the_join_date_no_longer_matters(): void
    {
        $old = $this->say($this->random, 'before you came');
        $old->forceFill(['created_at' => now()->subWeek()])->save();
        ChannelRead::factory()->create([
            'user_id' => $this->bob->id, 'channel_id' => $this->random->id, 'last_read_message_id' => 0,
        ]);

        // Without a marker this message predates Bob; with marker 0 it is past it.
        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    public function test_messages_up_to_the_marker_are_read_however_new(): void
    {
        $this->say($this->random);
        $this->say($this->random);
        $this->reads()->markRead($this->bob, $this->random);
        $this->say($this->random);

        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    public function test_a_message_sent_exactly_when_you_joined_counts(): void
    {
        $joinedAt = ServerMember::query()->where('user_id', $this->bob->id)->value('joined_at');
        $message = $this->say($this->random);
        $message->forceFill(['created_at' => $joinedAt])->save();

        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    // --- Viewers --------------------------------------------------------------

    public function test_a_viewer_who_is_not_a_member_has_nothing_unread(): void
    {
        $outsider = User::factory()->create(['username' => 'outsider']);
        $this->say($this->random, '@outsider hi');

        $state = UnreadState::forChannels($outsider, [$this->random, $this->general]);

        $this->assertFalse($state->hasUnread());
        $this->assertSame(0, $state->totalMentions());
    }

    public function test_leaving_the_server_clears_its_unread_state(): void
    {
        $this->say($this->random, '@bob');
        $this->assertTrue(UnreadState::forServers($this->bob, [$this->server])[$this->server->id]->hasUnread());

        ServerMember::query()->where('user_id', $this->bob->id)->delete();

        $state = UnreadState::forChannels($this->bob, [$this->random]);
        $this->assertFalse($state->hasUnread());
        $this->assertSame(0, $state->totalMentions());
    }

    public function test_a_viewer_without_a_username_gets_dots_but_no_mentions(): void
    {
        $nameless = User::factory()->create();
        $nameless->forceFill(['username' => ''])->save();
        $this->joinServer($this->server, $nameless);
        $this->say($this->random, '@ hello @');

        DB::enableQueryLog();
        $state = UnreadState::forChannels($nameless, [$this->random]);
        $queries = count(DB::getQueryLog());

        $this->assertTrue($state->isUnread($this->random));
        $this->assertSame(0, $state->mentions($this->random));
        // Roles and @everyone can mention anyone, so the mention
        // query always runs once something is unread; no role lookup.
        $this->assertSame(2, $queries);
    }

    // --- Query counts ---------------------------------------------------------

    public function test_nothing_unread_skips_the_mention_query(): void
    {
        $channels = $this->server->channelLayout($this->bob)->channels();

        DB::enableQueryLog();
        $state = UnreadState::forChannels($this->bob, $channels);

        $this->assertFalse($state->hasUnread());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_no_text_channels_means_no_queries(): void
    {
        $voice = $this->server->channels()->create(['name' => 'Lounge', 'type' => Channel::TYPE_VOICE, 'position' => 60]);

        DB::enableQueryLog();
        $empty = UnreadState::forChannels($this->bob, []);
        $voiceOnly = UnreadState::forChannels($this->bob, [$voice]);

        $this->assertCount(0, DB::getQueryLog());
        $this->assertFalse($empty->hasUnread());
        $this->assertFalse($voiceOnly->hasUnread());
        $this->assertSame([], UnreadState::forServers($this->bob, []));
    }

    // --- UnreadState accessors ------------------------------------------------

    public function test_lookups_work_by_id_and_default_to_zero(): void
    {
        $this->say($this->random, '@bob');

        $state = $this->state();

        $this->assertSame(1, $state->unreadCount((int) $this->random->id));
        $this->assertSame(1, $state->mentions((int) $this->random->id));
        $this->assertTrue($state->isUnread((int) $this->random->id));
        $this->assertSame(0, $state->unreadCount(999_999));
        $this->assertSame(0, $state->mentions(999_999));

        $blank = new UnreadState;
        $this->assertFalse($blank->hasUnread());
        $this->assertSame(0, $blank->totalMentions());
    }

    public function test_only_channels_passed_in_are_looked_at(): void
    {
        $this->say($this->random, '@bob');
        $this->say($this->general, '@bob');

        $state = UnreadState::forChannels($this->bob, [$this->general]);

        $this->assertFalse($state->isUnread($this->random));
        $this->assertSame(1, $state->totalMentions());
    }

    // --- Mentions -------------------------------------------------------------

    public function test_mentions_from_deleted_accounts_count(): void
    {
        Message::factory()->inChannel($this->random)->create(['user_id' => null, 'content' => '@bob boo']);

        $this->assertSame(1, $this->state()->mentions($this->random));
    }

    public function test_mentions_in_deleted_messages_or_by_blocked_users_do_not_count(): void
    {
        $carol = $this->joinServer($this->server);
        $this->say($this->random, '@bob gone')->delete();
        $this->say($this->random, '@bob spam', $carol);
        Friendship::factory()->create([
            'user_id' => $this->bob->id, 'friend_id' => $carol->id, 'status' => Friendship::STATUS_BLOCKED,
        ]);

        $this->assertSame(0, $this->state()->mentions($this->random));
    }

    public function test_one_message_mentioning_you_twice_is_one_mention(): void
    {
        $this->say($this->random, '@bob @bob @BOB!!');

        $this->assertSame(1, $this->state()->mentions($this->random));
    }

    public function test_a_like_match_that_is_not_a_mention_is_not_counted(): void
    {
        // Passes the LIKE pre-filter ('%@bob%') but not the pattern.
        $this->say($this->random, 'ask @bobby or mail x@bob');

        $state = $this->state();
        $this->assertTrue($state->isUnread($this->random));
        $this->assertSame(0, $state->mentions($this->random));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function dottedMentionCases(): array
    {
        return [
            'full name' => ['@bob.smith', true],
            'full name ending a sentence' => ['thanks @bob.smith.', true],
            'upper case' => ['hey @Bob.Smith', true],
            'only the first part' => ['@bob', false],
            'a longer name' => ['@bob.smiths', false],
            'more parts' => ['@bob.smith.jr', false],
            'dot wildcard' => ['@bobxsmith', false],
        ];
    }

    #[DataProvider('dottedMentionCases')]
    public function test_usernames_with_dots(string $content, bool $expected): void
    {
        $dotted = User::factory()->make(['username' => 'bob.smith']);

        $this->assertSame($expected, Mentions::mentionsUser($content, $dotted));
    }

    public function test_an_empty_username_is_never_mentioned(): void
    {
        $nameless = User::factory()->make(['username' => '']);

        $this->assertFalse(Mentions::mentionsUser('@ hi', $nameless));
        $this->assertFalse(Mentions::mentionsUser('@', $nameless));
    }

    public function test_usernames_are_picked_out_lower_case_once_each(): void
    {
        $this->assertSame(['bob', 'a_b', 'bob.smith'], Mentions::usernames('@Bob @a_b, @BOB and thanks @bob.smith.'));
        $this->assertSame([], Mentions::usernames('mail me@bob, @@bob, @everyone and @here'));
    }

    // --- forServers ------------------------------------------------------------

    public function test_server_states_do_not_leak_between_servers(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $this->joinServer($other, $this->bob);
        $this->say($this->random, '@bob here');

        $states = UnreadState::forServers($this->bob, [$this->server, $other]);

        $this->assertSame([$this->server->id, $other->id], array_keys($states));
        $this->assertTrue($states[$this->server->id]->hasUnread());
        $this->assertFalse($states[$other->id]->hasUnread());
        $this->assertSame(0, $states[$other->id]->totalMentions());
        $this->assertFalse($states[$other->id]->isUnread($this->random));
    }

    public function test_private_channels_opened_by_a_role_count_for_the_server(): void
    {
        $secret = $this->server->channels()->create([
            'name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 70, 'is_private' => true,
        ]);
        $role = $this->makeRole($this->server, 'Insiders', 0);
        $this->giveRole($this->bob, $role);
        $this->overrideChannel($secret, $role, allow: Permission::ViewChannels->value);
        $this->say($secret, '@bob psst');

        $state = UnreadState::forServers($this->bob, [$this->server])[$this->server->id];

        $this->assertTrue($state->isUnread($secret));
        $this->assertSame(1, $state->totalMentions());
    }

    public function test_a_server_you_are_not_in_has_nothing_unread(): void
    {
        $stranger = User::factory()->create(['username' => 'stranger']);
        $this->say($this->random, '@stranger');

        $state = UnreadState::forServers($stranger, [$this->server])[$this->server->id];

        $this->assertFalse($state->hasUnread());
    }

    // --- Pages ---------------------------------------------------------------

    public function test_opening_a_hidden_channel_is_forbidden_and_leaves_no_marker(): void
    {
        $this->overrideChannel($this->random, $this->server->defaultRole(), deny: Permission::ViewChannels->value);

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertForbidden();

        $this->assertNull($this->marker($this->random));
    }

    public function test_the_channel_badge_says_mention_for_one(): void
    {
        $this->say($this->random, '@bob');

        $html = $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<span data-mention-count>1<\/span><span class="sr-only" data-mention-label> mention<\/span>/', $html);
    }

    public function test_an_unread_channel_without_mentions_has_a_hidden_badge(): void
    {
        $this->say($this->random, 'no ping');

        $html = $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-channel-id="'.$this->random->id.'"\s+data-channel-type="text"\s+data-unread/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-mention-badge\s*>/', $html);
    }

    public function test_the_dashboard_shows_a_dot_without_mentions_and_nothing_when_read(): void
    {
        $this->actingAs($this->bob)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-server-unread', false)
            ->assertDontSee('data-server-mentions', false);

        $this->say($this->random, 'no ping');

        $this->actingAs($this->bob)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-server-unread', false)
            ->assertDontSee('data-server-mentions', false);
    }

    public function test_the_dashboard_badge_says_mention_for_one_and_hides_the_dot(): void
    {
        $this->say($this->random, '@bob');

        $this->actingAs($this->bob)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-server-mentions>1<span class="sr-only"> mention</span>', false)
            ->assertDontSee('data-server-unread', false);
    }

    public function test_reading_everything_clears_the_dashboard(): void
    {
        $this->say($this->general, '@bob');
        $this->say($this->random, '@bob');

        $this->actingAs($this->bob)->postJson(route('channels.read', [$this->server, $this->general]))->assertOk();
        $this->postJson(route('channels.read', [$this->server, $this->random]))->assertOk();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-server-unread', false)
            ->assertDontSee('data-server-mentions', false);
    }

    // --- POST read -------------------------------------------------------------

    public function test_the_read_endpoint_accepts_a_numeric_string_and_null(): void
    {
        $first = $this->say($this->random);
        $last = $this->say($this->random);

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => (string) $first->id])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $first->id]);

        $this->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => null])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $last->id]);
    }

    public function test_the_read_endpoint_reports_the_kept_marker_when_asked_to_go_back(): void
    {
        $first = $this->say($this->random);
        $last = $this->say($this->random);
        $this->reads()->markRead($this->bob, $this->random);

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => $first->id])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $last->id]);
    }

    public function test_the_read_endpoint_on_an_empty_channel_stores_zero(): void
    {
        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => 42])
            ->assertOk()
            ->assertJson(['last_read_message_id' => 0]);

        $this->assertSame(0, $this->marker($this->random));
    }
}
