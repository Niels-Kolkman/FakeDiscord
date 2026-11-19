<?php

namespace Tests\Feature\Notifications;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Events\DirectMessageReceived;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\NotificationMute;
use App\Models\Server;
use App\Models\User;
use App\Support\NotificationMutes;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-34: browser notifications and mute settings. Mutes per server and
 * per channel are stored per user; a muted place still stores mention
 * notifications but broadcasts them with `push: false`. DMs send
 * DirectMessageReceived to the other participants for a browser
 * notification. The browser side is tests/js/push-notifications.test.js.
 */
class PushNotificationsTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private User $carol;

    private Server $server;

    private Channel $general;

    private Channel $random;

    /**
     * What reached the broadcaster: [channels, event name, payload].
     *
     * @var list<array{list<string>, string, array<string, mixed>}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner', 'name' => 'Owner']);
        $this->bob = User::factory()->create(['username' => 'bob', 'name' => 'Bob']);
        $this->carol = User::factory()->create(['username' => 'carol', 'name' => 'Carol']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Pings');
        $this->joinServer($this->server, $this->bob);
        $this->joinServer($this->server, $this->carol);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
        $this->random = $this->server->channels()->create(['name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 5]);
    }

    private function spyBroadcaster(): void
    {
        $record = function (array $channels, string $event, array $payload): void {
            $this->sent[] = [array_values(array_map(fn ($c): string => (string) $c, $channels)), $event, $payload];
        };

        $spy = new class($record) extends BaseBroadcaster
        {
            public function __construct(private \Closure $record) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                ($this->record)($channels, (string) $event, $payload);
            }
        };

        Broadcast::extend('spy', fn () => $spy);
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);
    }

    /**
     * The mention broadcasts that went out: user id => payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mentionBroadcasts(): array
    {
        $byUser = [];
        foreach ($this->sent as [$channels, $event, $payload]) {
            if ($event === 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated') {
                $byUser[(int) str_replace('private-App.Models.User.', '', $channels[0])] = $payload;
            }
        }
        ksort($byUser);

        return $byUser;
    }

    /**
     * The DM broadcasts that went out: user id => payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dmBroadcasts(): array
    {
        $byUser = [];
        foreach ($this->sent as [$channels, $event, $payload]) {
            if ($event === DirectMessageReceived::NAME) {
                $this->assertCount(1, $channels);
                // Laravel adds the sender's socket id (toOthers()).
                unset($payload['socket']);
                $byUser[(int) str_replace('private-App.Models.User.', '', $channels[0])] = $payload;
            }
        }
        ksort($byUser);

        return $byUser;
    }

    private function say(User $as, string $content, ?Channel $channel = null): void
    {
        $channel ??= $this->general;

        $this->actingAs($as)
            ->postJson(route('messages.store', [$channel->server_id, $channel]), ['content' => $content])
            ->assertCreated();
    }

    // --- Muting --------------------------------------------------------------------

    public function test_a_member_mutes_and_unmutes_a_server(): void
    {
        $this->actingAs($this->bob)->putJson(route('servers.mute', $this->server))
            ->assertOk()->assertExactJson(['muted' => true]);
        $this->assertTrue(NotificationMutes::isServerMuted($this->bob, $this->server));
        $this->assertTrue(NotificationMutes::isMuted($this->bob, $this->general));
        $this->assertFalse(NotificationMutes::isServerMuted($this->carol, $this->server));

        $this->actingAs($this->bob)->deleteJson(route('servers.unmute', $this->server))
            ->assertOk()->assertExactJson(['muted' => false]);
        $this->assertFalse(NotificationMutes::isServerMuted($this->bob, $this->server));
        $this->assertSame(0, NotificationMute::count());
    }

    public function test_muting_twice_keeps_one_row_and_unmuting_nothing_is_fine(): void
    {
        $this->actingAs($this->bob)->putJson(route('servers.mute', $this->server))->assertOk();
        $this->actingAs($this->bob)->putJson(route('servers.mute', $this->server))->assertOk();
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $this->general]))->assertOk();
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $this->general]))->assertOk();

        $this->assertSame(2, NotificationMute::count());

        $this->actingAs($this->carol)->deleteJson(route('servers.unmute', $this->server))->assertOk()->assertExactJson(['muted' => false]);
        $this->actingAs($this->carol)->deleteJson(route('channels.unmute', [$this->server, $this->general]))->assertOk();
        $this->assertSame(2, NotificationMute::count());
    }

    public function test_a_member_mutes_and_unmutes_one_channel(): void
    {
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $this->general]))
            ->assertOk()->assertExactJson(['muted' => true]);

        $this->assertTrue(NotificationMutes::isChannelMuted($this->bob, $this->general));
        $this->assertTrue(NotificationMutes::isMuted($this->bob, $this->general));
        $this->assertFalse(NotificationMutes::isMuted($this->bob, $this->random));
        $this->assertFalse(NotificationMutes::isServerMuted($this->bob, $this->server));

        $mute = NotificationMute::sole();
        $this->assertSame($this->server->id, $mute->server_id);
        $this->assertSame($this->general->id, $mute->channel_id);

        $this->actingAs($this->bob)->deleteJson(route('channels.unmute', [$this->server, $this->general]))
            ->assertOk()->assertExactJson(['muted' => false]);
        $this->assertSame(0, NotificationMute::count());
    }

    public function test_unmuting_a_channel_keeps_the_server_muted_and_the_other_way_round(): void
    {
        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->general);

        NotificationMutes::unmuteChannel($this->bob, $this->general);
        $this->assertTrue(NotificationMutes::isMuted($this->bob, $this->general));

        NotificationMutes::muteChannel($this->bob, $this->general);
        NotificationMutes::unmuteServer($this->bob, $this->server);
        $this->assertTrue(NotificationMutes::isMuted($this->bob, $this->general));
        $this->assertFalse(NotificationMutes::isMuted($this->bob, $this->random));
    }

    public function test_without_javascript_muting_goes_back_with_a_status(): void
    {
        $from = route('channels.show', [$this->server, $this->general]);

        $this->actingAs($this->bob)->from($from)->put(route('servers.mute', $this->server))
            ->assertRedirect($from)->assertSessionHas('status', 'Muted Pings.');
        $this->actingAs($this->bob)->from($from)->put(route('channels.mute', [$this->server, $this->random]))
            ->assertRedirect($from)->assertSessionHas('status', 'Muted #random.');
        $this->actingAs($this->bob)->from($from)->delete(route('channels.unmute', [$this->server, $this->random]))
            ->assertRedirect($from)->assertSessionHas('status', 'Unmuted #random.');
        $this->actingAs($this->bob)->from($from)->delete(route('servers.unmute', $this->server))
            ->assertRedirect($from)->assertSessionHas('status', 'Unmuted Pings.');

        $this->assertSame(0, NotificationMute::count());
    }

    public function test_only_members_who_can_see_the_channel_can_mute_it(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->putJson(route('servers.mute', $this->server))->assertForbidden();
        $this->actingAs($stranger)->putJson(route('channels.mute', [$this->server, $this->general]))->assertForbidden();

        $secret = $this->server->channels()->create(['name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 9, 'is_private' => true]);
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $secret]))->assertForbidden();
        $this->actingAs($this->owner)->putJson(route('channels.mute', [$this->server, $secret]))->assertOk();

        // Scoped bindings: another server's channel is a 404.
        $other = app(CreateServer::class)->create($this->bob, 'Other');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $otherChannel]))->assertNotFound();

        $this->assertSame([(int) $this->owner->id], NotificationMute::query()->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_guests_and_unverified_users_cannot_mute(): void
    {
        $this->putJson(route('servers.mute', $this->server))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->joinServer($this->server, $unverified);
        $this->actingAs($unverified)->putJson(route('servers.mute', $this->server))->assertForbidden();

        $this->assertSame(0, NotificationMute::count());
    }

    public function test_leaving_the_server_clears_its_mutes_only(): void
    {
        $other = app(CreateServer::class)->create($this->carol, 'Other');
        $this->joinServer($other, $this->bob);

        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->random);
        NotificationMutes::muteServer($this->bob, $other);
        NotificationMutes::muteServer($this->carol, $this->server);

        app(LeaveServer::class)->leave($this->server, $this->bob);

        $this->assertSame(['servers' => [(int) $other->id], 'channels' => []], NotificationMutes::forUser($this->bob));
        $this->assertTrue(NotificationMutes::isServerMuted($this->carol, $this->server));
    }

    public function test_mutes_go_with_the_channel_and_the_server(): void
    {
        NotificationMutes::muteChannel($this->bob, $this->random);
        NotificationMutes::muteServer($this->carol, $this->server);

        $this->random->delete();
        $this->assertSame(1, NotificationMute::count());

        $this->server->delete();
        $this->assertSame(0, NotificationMute::count());
    }

    public function test_muted_user_ids_answers_for_many_people_at_once(): void
    {
        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->general);
        NotificationMutes::muteChannel($this->carol, $this->random);

        $ids = [(int) $this->owner->id, (int) $this->bob->id, (int) $this->carol->id];
        $this->assertSame([(int) $this->bob->id], NotificationMutes::mutedUserIds($this->general, $ids));
        $this->assertSame([(int) $this->bob->id, (int) $this->carol->id], NotificationMutes::mutedUserIds($this->random, $ids));
        $this->assertSame([], NotificationMutes::mutedUserIds($this->random, []));
    }

    // --- Mentions in muted places ----------------------------------------------------

    public function test_a_mention_in_a_muted_server_is_stored_but_not_pushed(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteServer($this->bob, $this->server);

        $this->say($this->owner, 'hey @bob and @carol');

        $this->assertSame(2, DatabaseNotification::count());
        $broadcasts = $this->mentionBroadcasts();
        $this->assertSame([(int) $this->bob->id, (int) $this->carol->id], array_keys($broadcasts));
        $this->assertFalse($broadcasts[(int) $this->bob->id]['push']);
        $this->assertTrue($broadcasts[(int) $this->carol->id]['push']);
    }

    public function test_a_muted_channel_only_silences_that_channel(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteChannel($this->bob, $this->random);

        $this->say($this->owner, 'hey @bob', $this->random);
        $this->say($this->owner, 'hey @bob', $this->general);

        $pushes = array_map(
            fn (array $sent): bool => $sent[2]['push'],
            array_values(array_filter($this->sent, fn (array $sent): bool => str_contains($sent[1], 'BroadcastNotificationCreated'))),
        );
        $this->assertSame([false, true], $pushes);
    }

    public function test_everyone_and_role_mentions_follow_the_mutes_too(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteServer($this->carol, $this->server);

        $this->say($this->owner, '@everyone listen up');

        $broadcasts = $this->mentionBroadcasts();
        $this->assertTrue($broadcasts[(int) $this->bob->id]['push']);
        $this->assertFalse($broadcasts[(int) $this->carol->id]['push']);
        $this->assertSame('mentioned @everyone', $broadcasts[(int) $this->carol->id]['label']);
    }

    public function test_the_stored_notification_does_not_keep_the_push_flag(): void
    {
        NotificationMutes::muteServer($this->bob, $this->server);

        $this->say($this->owner, 'hey @bob');

        $this->assertArrayNotHasKey('push', DatabaseNotification::sole()->data);
    }

    // --- DMs ---------------------------------------------------------------------------

    public function test_a_dm_is_announced_to_the_other_person_only(): void
    {
        $this->spyBroadcaster();
        Friendship::factory()->accepted()->create(['user_id' => $this->bob->id, 'friend_id' => $this->carol->id]);
        $conversation = Conversation::factory()->between($this->bob, $this->carol)->create();

        $this->actingAs($this->bob)
            ->postJson(route('dms.messages.store', $conversation), ['content' => "Lunch\n  tomorrow?"])
            ->assertCreated();

        $message = Message::sole();
        $this->assertSame([(int) $this->carol->id => [
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) $message->id,
            'is_group' => false,
            'title' => 'Bob',
            'author_name' => 'Bob',
            'avatar_url' => $this->bob->avatarUrl(),
            'excerpt' => 'Lunch tomorrow?',
            'has_attachments' => false,
            'url' => route('dms.show', $conversation).'#message-'.$message->id,
        ]], $this->dmBroadcasts());
    }

    public function test_a_group_dm_is_named_for_each_recipient_and_skips_blockers(): void
    {
        $this->spyBroadcaster();
        $dave = User::factory()->create(['name' => 'Dave']);
        $conversation = Conversation::factory()->group()->create(['name' => null, 'owner_id' => $this->bob->id]);
        $conversation->users()->attach([$this->bob->id, $this->carol->id, $this->owner->id, $dave->id]);
        // Dave blocked Bob: he does not see Bob's messages.
        Friendship::factory()->blocked()->create(['user_id' => $dave->id, 'friend_id' => $this->bob->id]);

        $this->actingAs($this->bob)
            ->postJson(route('dms.messages.store', $conversation), ['content' => 'Hi all'])
            ->assertCreated();

        $broadcasts = $this->dmBroadcasts();
        $this->assertSame([(int) $this->owner->id, (int) $this->carol->id], array_keys($broadcasts));
        $this->assertTrue($broadcasts[(int) $this->carol->id]['is_group']);
        $this->assertSame('Bob, Dave, Owner', $broadcasts[(int) $this->carol->id]['title']);
        $this->assertSame('Bob, Carol, Dave', $broadcasts[(int) $this->owner->id]['title']);
    }

    public function test_channel_messages_send_no_dm_event(): void
    {
        $this->spyBroadcaster();

        $this->say($this->owner, 'hey @bob');

        $this->assertSame([], $this->dmBroadcasts());
    }

    // --- Pages -------------------------------------------------------------------------

    public function test_the_settings_page_lists_servers_and_muted_channels(): void
    {
        $secret = $this->server->channels()->create(['name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 9, 'is_private' => true]);
        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->random);
        // A channel Bob can no longer see is not listed.
        NotificationMute::factory()->forChannel($secret)->create(['user_id' => $this->bob->id]);

        $page = $this->actingAs($this->bob)->get(route('settings.notifications'));
        $this->assertMatchesRegularExpression('/data-mute-server="'.$this->server->id.'"\s+data-muted/', (string) $page->getContent());

        $page->assertOk()
            ->assertSee('data-push-settings', false)
            ->assertSee('data-mute-channel="'.$this->random->id.'"', false)
            ->assertDontSee('data-mute-channel="'.$secret->id.'"', false)
            ->assertSee(route('servers.unmute', $this->server), false)
            ->assertSee(route('channels.unmute', [$this->server, $this->random]), false);

        $this->actingAs($this->carol)->get(route('settings.notifications'))
            ->assertOk()
            ->assertSee(route('servers.mute', $this->server), false)
            ->assertSee('No muted channels.');
    }

    public function test_the_settings_page_needs_a_verified_user(): void
    {
        $this->get(route('settings.notifications'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('settings.notifications'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_the_sidebar_shows_the_mute_toggles(): void
    {
        NotificationMutes::muteChannel($this->bob, $this->random);

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))
            ->assertOk()
            ->assertSee(route('servers.mute', $this->server), false)
            ->assertSee(route('channels.mute', [$this->server, $this->general]), false)
            ->assertSee(route('channels.unmute', [$this->server, $this->random]), false)
            ->assertSee('data-channel-id="'.$this->random->id.'"', false);

        NotificationMutes::muteServer($this->bob, $this->server);

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))
            ->assertOk()
            ->assertSee(route('servers.unmute', $this->server), false)
            ->assertSee('Unmute server');
    }

    public function test_the_dashboard_links_the_settings_page(): void
    {
        $this->actingAs($this->bob)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('settings.notifications'), false);
    }
}
