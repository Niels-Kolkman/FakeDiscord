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
 * ISSUE-32: read markers per user per channel, unread dots and mention
 * badges in the channel sidebar and the server lists, and marking a
 * channel read when it is opened or while it is open.
 */
class UnreadTrackingTest extends TestCase
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
        $this->server = app(CreateServer::class)->create($this->owner, 'Unread');
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
        return UnreadState::forChannels($viewer ?? $this->bob, $this->server->channelLayout($viewer ?? $this->bob)->channels());
    }

    private function marker(Channel $channel, ?User $user = null): ?int
    {
        $value = ChannelRead::query()
            ->where('user_id', ($user ?? $this->bob)->id)
            ->where('channel_id', $channel->id)
            ->value('last_read_message_id');

        return $value === null ? null : (int) $value;
    }

    // --- Read markers -------------------------------------------------------

    public function test_opening_a_channel_marks_it_read_up_to_the_newest_message(): void
    {
        $this->say($this->random);
        $last = $this->say($this->random, 'second');

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertOk();

        $this->assertSame((int) $last->id, $this->marker($this->random));
        $this->assertFalse($this->state()->isUnread($this->random));
    }

    public function test_opening_an_empty_channel_stores_a_zero_marker(): void
    {
        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertOk();

        $this->assertSame(0, $this->marker($this->random));

        $this->say($this->random);
        $this->assertTrue($this->state()->isUnread($this->random));
    }

    public function test_opening_the_server_marks_its_default_channel_read(): void
    {
        $this->say($this->general);
        $this->say($this->random);

        $this->actingAs($this->bob)->get(route('servers.show', $this->server))->assertOk();

        $this->assertFalse($this->state()->isUnread($this->general));
        $this->assertTrue($this->state()->isUnread($this->random));
    }

    public function test_voice_channels_are_never_marked_or_unread(): void
    {
        $voice = $this->server->channels()->create(['name' => 'Lounge', 'type' => Channel::TYPE_VOICE, 'position' => 60]);

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $voice]))->assertOk();

        $this->assertNull($this->marker($voice));
        $this->assertFalse($this->state()->isUnread($voice));
    }

    public function test_the_marker_never_moves_back(): void
    {
        $first = $this->say($this->random);
        $this->say($this->random);
        app(MarkChannelRead::class)->markRead($this->bob, $this->random);
        $marker = $this->marker($this->random);

        app(MarkChannelRead::class)->markRead($this->bob, $this->random, (int) $first->id);

        $this->assertSame($marker, $this->marker($this->random));
        $this->assertSame(1, ChannelRead::query()->count());
    }

    // --- What counts as unread ---------------------------------------------

    public function test_new_messages_from_others_make_a_channel_unread(): void
    {
        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertOk();
        $this->say($this->random);
        $this->say($this->random);

        $state = $this->state();

        $this->assertTrue($state->isUnread($this->random));
        $this->assertSame(2, $state->unreadCount($this->random));
        $this->assertTrue($state->hasUnread());
    }

    public function test_your_own_messages_never_make_a_channel_unread(): void
    {
        $this->say($this->random, 'mine', $this->bob);

        $this->assertFalse($this->state()->isUnread($this->random));
    }

    public function test_without_a_marker_only_messages_since_joining_count(): void
    {
        $old = $this->say($this->random, 'before you came');
        $old->forceFill(['created_at' => now()->subWeek()])->save();

        $this->assertFalse($this->state()->isUnread($this->random));

        $this->say($this->random, 'after');
        $this->assertSame(1, $this->state()->unreadCount($this->random));
    }

    public function test_deleted_messages_and_blocked_authors_do_not_count(): void
    {
        $carol = $this->joinServer($this->server);
        $this->say($this->random, 'gone')->delete();
        $this->say($this->random, 'spam', $carol);
        Friendship::factory()->create([
            'user_id' => $this->bob->id, 'friend_id' => $carol->id, 'status' => Friendship::STATUS_BLOCKED,
        ]);

        $this->assertFalse($this->state()->isUnread($this->random));
        // Carol's other reader is not affected by Bob's block.
        $this->assertTrue($this->state($this->owner)->isUnread($this->random));
    }

    public function test_messages_of_deleted_accounts_count(): void
    {
        Message::factory()->inChannel($this->random)->create(['user_id' => null, 'content' => 'ghost']);

        $this->assertTrue($this->state()->isUnread($this->random));
    }

    public function test_one_users_reads_do_not_affect_another(): void
    {
        $carol = $this->joinServer($this->server);
        $this->say($this->random);

        app(MarkChannelRead::class)->markRead($carol, $this->random);

        $this->assertFalse($this->state($carol)->isUnread($this->random));
        $this->assertTrue($this->state()->isUnread($this->random));
    }

    // --- Mentions ----------------------------------------------------------

    public function test_unread_mentions_are_counted_per_channel(): void
    {
        $this->say($this->random, 'hey @bob');
        $this->say($this->random, '@BOB look at this.');
        $this->say($this->random, 'thanks @bob.');
        $this->say($this->random, 'not for @bobby');
        $this->say($this->random, 'mail bob@bob.com or me@bob');
        $this->say($this->general, '@bob in general');

        $state = $this->state();

        $this->assertSame(3, $state->mentions($this->random));
        $this->assertSame(1, $state->mentions($this->general));
        $this->assertSame(4, $state->totalMentions());
    }

    public function test_read_mentions_and_your_own_mentions_of_yourself_do_not_count(): void
    {
        $this->say($this->random, '@bob early');
        app(MarkChannelRead::class)->markRead($this->bob, $this->random);
        $this->say($this->random, 'note to self @bob', $this->bob);

        $this->assertSame(0, $this->state()->mentions($this->random));
    }

    public function test_like_wildcards_in_usernames_are_not_wildcards(): void
    {
        $underscored = User::factory()->create(['username' => 'a_b']);
        $this->joinServer($this->server, $underscored);
        $this->say($this->random, 'hello @axb');

        $this->assertSame(0, $this->state($underscored)->mentions($this->random));

        $this->say($this->random, 'hello @a_b');
        $this->assertSame(1, $this->state($underscored)->mentions($this->random));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function mentionCases(): array
    {
        return [
            'plain' => ['@bob', true],
            'in a sentence' => ['hi @bob how are you', true],
            'upper case' => ['@Bob', true],
            'trailing period' => ['see you @bob.', true],
            'punctuation' => ['(@bob), @bob!', true],
            'longer name' => ['@bobby', false],
            'name with a dot' => ['@bob.smith', false],
            'email' => ['bob@bob', false],
            'double at' => ['@@bob', false],
            'no at' => ['bob', false],
        ];
    }

    #[DataProvider('mentionCases')]
    public function test_what_counts_as_a_mention(string $content, bool $expected): void
    {
        $this->assertSame($expected, Mentions::mentionsUser($content, $this->bob));
    }

    // --- Sidebar ------------------------------------------------------------

    public function test_the_sidebar_shows_unread_channels_and_mention_badges(): void
    {
        $this->say($this->random, 'hey @bob');
        $this->say($this->random, 'and again @bob');

        $html = $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-channel-id="'.$this->random->id.'"\s+data-channel-type="text"\s+data-unread/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-channel-id="'.$this->general->id.'"[^>]*data-unread/', $html);
        $this->assertMatchesRegularExpression('/data-mention-badge\s*>\s*<span data-mention-count>2<\/span>/', $html);
    }

    public function test_the_open_channel_is_never_shown_unread(): void
    {
        $this->say($this->random, '@bob');

        $html = $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/data-channel-id="'.$this->random->id.'"[^>]*data-unread/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-mention-badge\s*>/', $html);
    }

    public function test_the_page_tells_the_script_where_to_mark_read(): void
    {
        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->random]))
            ->assertOk()
            ->assertSee('data-read-url="'.route('channels.read', [$this->server, $this->random]).'"', false)
            ->assertSee('<meta name="username" content="bob">', false);
    }

    public function test_hidden_channels_never_count(): void
    {
        $secret = $this->server->channels()->create([
            'name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 70, 'is_private' => true,
        ]);
        $this->say($secret, '@bob secret');

        $this->assertFalse($this->state()->isUnread($secret));
        $servers = UnreadState::forServers($this->bob, [$this->server]);
        $this->assertFalse($servers[$this->server->id]->hasUnread());

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))
            ->assertOk()
            ->assertDontSee('data-channel-id="'.$secret->id.'"', false);
    }

    // --- Server lists --------------------------------------------------------

    public function test_the_dashboard_shows_unread_servers_and_mention_totals(): void
    {
        $quiet = app(CreateServer::class)->create($this->owner, 'Quiet');
        $this->joinServer($quiet, $this->bob);
        $loud = app(CreateServer::class)->create($this->owner, 'Loud');
        $this->joinServer($loud, $this->bob);
        /** @var Channel $loudGeneral */
        $loudGeneral = $loud->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();
        $this->say($this->random, 'just chatting');
        $this->say($loudGeneral, '@bob one');
        $this->say($loudGeneral, '@bob two');

        $states = UnreadState::forServers($this->bob, [$quiet, $loud, $this->server]);

        $this->assertFalse($states[$quiet->id]->hasUnread());
        $this->assertTrue($states[$this->server->id]->hasUnread());
        $this->assertSame(0, $states[$this->server->id]->totalMentions());
        $this->assertSame(2, $states[$loud->id]->totalMentions());

        $this->actingAs($this->bob)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-server-unread', false)
            ->assertSeeInOrder(['data-server-mentions', '2'], false);
    }

    public function test_the_friends_sidebar_shows_unread_servers(): void
    {
        $this->say($this->random, '@bob');

        $this->actingAs($this->bob)->get(route('friends.index'))
            ->assertOk()
            ->assertSee('data-server-mentions', false);
    }

    public function test_unread_state_is_two_queries_however_many_channels(): void
    {
        foreach (range(1, 10) as $i) {
            $channel = $this->server->channels()->create(['name' => "c{$i}", 'type' => Channel::TYPE_TEXT, 'position' => 100 + $i]);
            $this->say($channel, "@bob {$i}");
        }
        $channels = $this->server->channelLayout($this->bob)->channels();

        DB::enableQueryLog();
        $state = UnreadState::forChannels($this->bob, $channels);
        $queries = count(DB::getQueryLog());

        $this->assertSame(10, $state->totalMentions());
        $this->assertSame(2, $queries);
    }

    // --- POST read -----------------------------------------------------------

    public function test_the_read_endpoint_marks_up_to_the_given_message(): void
    {
        $first = $this->say($this->random);
        $second = $this->say($this->random);

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => $first->id])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $first->id]);

        $this->assertSame(1, $this->state()->unreadCount($this->random));

        $this->postJson(route('channels.read', [$this->server, $this->random]))
            ->assertOk()
            ->assertJson(['last_read_message_id' => $second->id]);
        $this->assertFalse($this->state()->isUnread($this->random));
    }

    public function test_the_read_endpoint_cannot_skip_messages_still_to_come(): void
    {
        $last = $this->say($this->random);

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => $last->id + 1000])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $last->id]);

        $this->say($this->random);
        $this->assertTrue($this->state()->isUnread($this->random));
    }

    public function test_the_read_endpoint_validates_the_message_id(): void
    {
        foreach (['abc', 0, -5] as $invalid) {
            $this->actingAs($this->bob)
                ->postJson(route('channels.read', [$this->server, $this->random]), ['message_id' => $invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('message_id');
        }

        $this->assertSame(0, ChannelRead::query()->count());
    }

    public function test_only_members_who_can_see_the_channel_may_mark_it_read(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->postJson(route('channels.read', [$this->server, $this->random]))
            ->assertForbidden();

        $this->overrideChannel($this->random, $this->server->defaultRole(), deny: Permission::ViewChannels->value);
        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->random]))
            ->assertForbidden();

        $this->assertSame(0, ChannelRead::query()->count());
    }

    public function test_a_channel_of_another_server_is_a_404(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        /** @var Channel $elsewhere */
        $elsewhere = $other->channels()->firstOrFail();

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $elsewhere]))
            ->assertNotFound();
    }

    public function test_guests_cannot_mark_channels_read(): void
    {
        $this->postJson(route('channels.read', [$this->server, $this->random]))->assertUnauthorized();
    }

    // --- Deletes -------------------------------------------------------------

    public function test_markers_go_with_the_channel_and_the_user(): void
    {
        $carol = $this->joinServer($this->server);
        app(MarkChannelRead::class)->markRead($this->bob, $this->random);
        app(MarkChannelRead::class)->markRead($carol, $this->general);

        $this->random->delete();
        $this->assertSame(1, ChannelRead::query()->count());

        $carol->delete();
        $this->assertSame(0, ChannelRead::query()->count());
    }
}
