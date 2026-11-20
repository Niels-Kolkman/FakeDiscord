<?php

namespace Tests\Feature\Notifications;

use App\Actions\Moderation\BanMember;
use App\Actions\Moderation\KickMember;
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
use App\Support\DirectMessageNotifier;
use App\Support\NotificationInbox;
use App\Support\NotificationMutes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases on top of PushNotificationsTest: mutes cleared by
 * kick and ban, rejoining unmuted, edits in muted places, the inbox and
 * bell left alone by muting, mutes that are per user and per server, DM
 * payloads for attachments / deleted authors / named groups, blocks in
 * the other direction, a failed DM broadcast, and what the settings page
 * leaves out.
 */
class PushNotificationEdgeCasesTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private User $carol;

    private Server $server;

    private Channel $general;

    private Channel $random;

    /** @var list<array{list<string>, string, array<string, mixed>}> */
    private array $sent = [];

    /** @var list<int> user ids whose channel makes the spy broadcaster throw */
    private array $failFor = [];

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
            $names = array_values(array_map(fn ($c): string => (string) $c, $channels));
            foreach ($this->failFor as $id) {
                if (in_array('private-App.Models.User.'.$id, $names, true)) {
                    throw new \RuntimeException('Reverb is down for '.$id);
                }
            }
            $this->sent[] = [$names, $event, $payload];
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

    /** @return list<bool> the push flags of the mention broadcasts, in order */
    private function pushFlags(): array
    {
        return array_values(array_map(
            fn (array $sent): bool => $sent[2]['push'],
            array_filter($this->sent, fn (array $sent): bool => str_contains($sent[1], 'BroadcastNotificationCreated')),
        ));
    }

    /** @return array<int, array<string, mixed>> user id => DM payload */
    private function dmBroadcasts(): array
    {
        $byUser = [];
        foreach ($this->sent as [$channels, $event, $payload]) {
            if ($event === DirectMessageReceived::NAME) {
                unset($payload['socket']);
                $byUser[(int) str_replace('private-App.Models.User.', '', $channels[0])] = $payload;
            }
        }
        ksort($byUser);

        return $byUser;
    }

    private function groupOf(User ...$users): Conversation
    {
        $conversation = Conversation::factory()->group()->create(['name' => null, 'owner_id' => $users[0]->id]);
        $conversation->users()->attach(array_map(fn (User $u): int => (int) $u->id, $users));

        return $conversation;
    }

    private function mention(string $content, ?Channel $channel = null): void
    {
        $channel ??= $this->general;

        $this->actingAs($this->owner)
            ->postJson(route('messages.store', [$channel->server_id, $channel]), ['content' => $content])
            ->assertCreated();
    }

    // --- Mutes and membership ------------------------------------------------------

    public function test_kicking_clears_the_members_mutes_for_that_server(): void
    {
        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->random);
        NotificationMutes::muteServer($this->carol, $this->server);

        app(KickMember::class)->kick($this->server, $this->bob);

        $this->assertSame(['servers' => [], 'channels' => []], NotificationMutes::forUser($this->bob));
        $this->assertTrue(NotificationMutes::isServerMuted($this->carol, $this->server));
    }

    public function test_banning_clears_the_members_mutes_for_that_server(): void
    {
        NotificationMutes::muteChannel($this->bob, $this->general);

        app(BanMember::class)->ban($this->server, $this->bob, $this->owner, 'spam');

        $this->assertSame(0, NotificationMute::query()->where('user_id', $this->bob->id)->count());
    }

    public function test_rejoining_starts_unmuted(): void
    {
        $this->actingAs($this->bob)->putJson(route('servers.mute', $this->server))->assertOk();
        $this->actingAs($this->bob)->delete(route('servers.leave', $this->server))->assertRedirect();
        $this->assertSame(0, NotificationMute::count());

        $this->joinServer($this->server, $this->bob);
        $this->assertFalse(NotificationMutes::isMuted($this->bob, $this->general));
    }

    public function test_a_refused_leave_by_the_owner_keeps_their_mutes(): void
    {
        NotificationMutes::muteServer($this->owner, $this->server);

        try {
            app(LeaveServer::class)->leave($this->server, $this->owner);
            $this->fail('The owner should not be able to leave.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertTrue(NotificationMutes::isServerMuted($this->owner, $this->server));
    }

    public function test_muting_through_the_routes_is_per_user(): void
    {
        $this->actingAs($this->bob)->putJson(route('channels.mute', [$this->server, $this->general]))->assertOk();
        $this->actingAs($this->carol)->deleteJson(route('channels.unmute', [$this->server, $this->general]))->assertOk();

        $this->assertTrue(NotificationMutes::isChannelMuted($this->bob, $this->general));
        $this->assertFalse(NotificationMutes::isChannelMuted($this->carol, $this->general));
    }

    public function test_a_server_mute_does_not_leak_into_another_server(): void
    {
        $other = app(CreateServer::class)->create($this->carol, 'Other');
        $this->joinServer($other, $this->bob);
        /** @var Channel $otherGeneral */
        $otherGeneral = $other->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();

        NotificationMutes::muteServer($this->bob, $this->server);

        $this->assertFalse(NotificationMutes::isMuted($this->bob, $otherGeneral));
        $this->assertSame([], NotificationMutes::mutedUserIds($otherGeneral, [(int) $this->bob->id]));
    }

    public function test_for_user_lists_both_kinds_sorted(): void
    {
        $other = app(CreateServer::class)->create($this->carol, 'Other');
        $this->joinServer($other, $this->bob);

        NotificationMutes::muteServer($this->bob, $other);
        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->random);
        NotificationMutes::muteChannel($this->bob, $this->general);

        $servers = [(int) $this->server->id, (int) $other->id];
        sort($servers);
        $channels = [(int) $this->general->id, (int) $this->random->id];
        sort($channels);

        $this->assertSame(['servers' => $servers, 'channels' => $channels], NotificationMutes::forUser($this->bob));
        $this->assertSame(['servers' => [], 'channels' => []], NotificationMutes::forUser($this->carol));
    }

    public function test_a_channel_mute_is_not_a_server_mute(): void
    {
        NotificationMutes::muteChannel($this->bob, $this->random);

        $this->assertSame((int) $this->server->id, NotificationMute::sole()->server_id);
        $this->assertFalse(NotificationMutes::isServerMuted($this->bob, $this->server));
        // Unmuting the server leaves the channel mute alone.
        NotificationMutes::unmuteServer($this->bob, $this->server);
        $this->assertTrue(NotificationMutes::isChannelMuted($this->bob, $this->random));
    }

    // --- Mentions in muted places ------------------------------------------------------

    public function test_a_mention_added_by_an_edit_in_a_muted_channel_is_not_pushed(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteChannel($this->bob, $this->general);

        $this->mention('hi');
        $message = Message::sole();

        $this->actingAs($this->owner)
            ->patchJson(route('messages.update', [$this->server, $this->general, $message]), ['content' => 'hi @bob and @carol'])
            ->assertOk();

        $this->assertSame([false, true], $this->pushFlags());
        $this->assertSame(2, DatabaseNotification::count());
    }

    public function test_muted_mentions_still_count_in_the_bell(): void
    {
        NotificationMutes::muteServer($this->bob, $this->server);

        $this->mention('hey @bob');

        $this->assertSame(1, NotificationInbox::unreadCount($this->bob));
        $this->assertNull(DatabaseNotification::sole()->read_at);
    }

    public function test_muting_later_leaves_earlier_mentions_unread(): void
    {
        $this->mention('hey @bob');

        NotificationMutes::muteServer($this->bob, $this->server);
        NotificationMutes::muteChannel($this->bob, $this->general);

        $this->assertSame(1, NotificationInbox::unreadCount($this->bob));
    }

    public function test_unmuting_lets_the_next_mention_push_again(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteServer($this->bob, $this->server);
        $this->mention('@bob one');

        NotificationMutes::unmuteServer($this->bob, $this->server);
        $this->mention('@bob two');

        $this->assertSame([false, true], $this->pushFlags());
    }

    public function test_server_mutes_do_not_silence_dms(): void
    {
        $this->spyBroadcaster();
        NotificationMutes::muteServer($this->carol, $this->server);
        NotificationMutes::muteChannel($this->carol, $this->general);
        $conversation = Conversation::factory()->between($this->bob, $this->carol)->create();

        $this->actingAs($this->bob)
            ->postJson(route('dms.messages.store', $conversation), ['content' => 'still here'])
            ->assertCreated();

        $this->assertSame([(int) $this->carol->id], array_keys($this->dmBroadcasts()));
    }

    // --- DM payloads -------------------------------------------------------------------

    public function test_an_attachment_only_dm_says_it_has_attachments(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::fake('public');
        $this->spyBroadcaster();
        $conversation = Conversation::factory()->between($this->bob, $this->carol)->create();

        $this->actingAs($this->bob)
            ->post(route('dms.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $payload = $this->dmBroadcasts()[(int) $this->carol->id];
        $this->assertTrue($payload['has_attachments']);
        $this->assertSame('', $payload['excerpt']);
    }

    public function test_a_named_group_dm_has_the_same_title_for_everyone(): void
    {
        $this->spyBroadcaster();
        $conversation = $this->groupOf($this->bob, $this->carol, $this->owner);
        $conversation->update(['name' => 'Lunch crew']);

        $this->actingAs($this->bob)
            ->postJson(route('dms.messages.store', $conversation), ['content' => 'noon?'])
            ->assertCreated();

        $broadcasts = $this->dmBroadcasts();
        $this->assertSame([(int) $this->owner->id, (int) $this->carol->id], array_keys($broadcasts));
        $this->assertSame(['Lunch crew', 'Lunch crew'], array_column($broadcasts, 'title'));
        $this->assertSame([true, true], array_column($broadcasts, 'is_group'));
    }

    public function test_a_dm_event_for_a_deleted_author_falls_back(): void
    {
        $conversation = Conversation::factory()->between($this->bob, $this->carol)->create();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => null, 'content' => 'ghost']);

        $event = new DirectMessageReceived($message, $conversation, $this->carol);
        $payload = $event->broadcastWith();

        $this->assertSame('Deleted User', $payload['author_name']);
        $this->assertNull($payload['avatar_url']);
        $this->assertFalse($payload['has_attachments']);
        $this->assertSame('ghost', $payload['excerpt']);
        $this->assertSame('dm.received', $event->broadcastAs());
        $this->assertSame(
            ['private-App.Models.User.'.$this->carol->id],
            array_map(fn ($c): string => (string) $c, $event->broadcastOn()),
        );
    }

    public function test_the_notifier_sends_to_everyone_when_the_author_is_gone(): void
    {
        $this->spyBroadcaster();
        $conversation = Conversation::factory()->between($this->bob, $this->carol)->create();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => null, 'content' => 'ghost']);

        $sent = app(DirectMessageNotifier::class)->notify($message, $conversation);

        $this->assertSame([(int) $this->bob->id, (int) $this->carol->id], $sent);
    }

    public function test_only_blocks_of_the_author_by_the_recipient_count(): void
    {
        $this->spyBroadcaster();
        $conversation = $this->groupOf($this->bob, $this->carol, $this->owner);
        // The author blocked Carol (not the other way round) ...
        Friendship::factory()->blocked()->create(['user_id' => $this->bob->id, 'friend_id' => $this->carol->id]);
        // ... and the owner blocked someone other than the author.
        Friendship::factory()->blocked()->create(['user_id' => $this->owner->id, 'friend_id' => $this->carol->id]);
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'hi']);

        $sent = app(DirectMessageNotifier::class)->notify($message, $conversation);

        // In id order.
        $this->assertSame([(int) $this->owner->id, (int) $this->carol->id], $sent);
    }

    public function test_a_failed_dm_broadcast_still_reaches_the_others(): void
    {
        $this->spyBroadcaster();
        $conversation = $this->groupOf($this->bob, $this->carol, $this->owner);
        $this->failFor = [(int) $this->carol->id];

        $this->actingAs($this->bob)
            ->postJson(route('dms.messages.store', $conversation), ['content' => 'anyone?'])
            ->assertCreated();

        $this->assertSame([(int) $this->owner->id], array_keys($this->dmBroadcasts()));
        $this->assertSame(1, Message::count());
    }

    public function test_a_conversation_of_one_sends_nothing(): void
    {
        $this->spyBroadcaster();
        $conversation = $this->groupOf($this->bob);
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'alone']);

        $this->assertSame([], app(DirectMessageNotifier::class)->notify($message, $conversation));
        $this->assertSame([], $this->dmBroadcasts());
    }

    // --- Settings page -------------------------------------------------------------------

    public function test_the_settings_page_lists_servers_by_name(): void
    {
        $alpha = app(CreateServer::class)->create($this->carol, 'Alpha');
        $this->joinServer($alpha, $this->bob);

        $content = (string) $this->actingAs($this->bob)->get(route('settings.notifications'))->assertOk()->getContent();

        $alphaAt = strpos($content, 'data-mute-server="'.$alpha->id.'"');
        $pingsAt = strpos($content, 'data-mute-server="'.$this->server->id.'"');
        $this->assertNotFalse($alphaAt);
        $this->assertNotFalse($pingsAt);
        $this->assertLessThan($pingsAt, $alphaAt);
    }

    public function test_the_settings_page_ignores_mutes_for_servers_you_are_not_in(): void
    {
        $other = app(CreateServer::class)->create($this->carol, 'Elsewhere');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();
        // A stale row (only possible by hand: leaving clears them).
        NotificationMute::factory()->forChannel($otherChannel)->create(['user_id' => $this->bob->id]);

        $this->actingAs($this->bob)->get(route('settings.notifications'))
            ->assertOk()
            ->assertDontSee('data-mute-channel="'.$otherChannel->id.'"', false)
            ->assertDontSee('data-mute-server="'.$other->id.'"', false)
            ->assertSee('No muted channels.');
    }

    public function test_the_settings_page_without_servers_still_renders(): void
    {
        $loner = User::factory()->create();

        $this->actingAs($loner)->get(route('settings.notifications'))
            ->assertOk()
            ->assertSee('data-push-settings', false)
            ->assertSee('No muted channels.');
    }
}
