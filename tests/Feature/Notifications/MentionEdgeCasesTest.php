<?php

namespace Tests\Feature\Notifications;

use App\Actions\Messages\EditMessage;
use App\Actions\Messages\SendMessage;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Support\Mentions;
use App\Support\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases: mention parsing boundaries, role scoping, kicks and
 * bans, edits that keep their mentions, snapshots, deleted authors and
 * servers, and the inbox / suggestion routes' odd inputs.
 */
class MentionEdgeCasesTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private User $carol;

    private Server $server;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner', 'name' => 'Owner']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->carol = User::factory()->create(['username' => 'carol', 'name' => 'Carol']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Edges');
        $this->joinServer($this->server, $this->bob);
        $this->joinServer($this->server, $this->carol);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
    }

    private function send(string $content, ?User $author = null, ?Channel $channel = null): Message
    {
        return app(SendMessage::class)->send($author ?? $this->owner, $channel ?? $this->general, $content);
    }

    /**
     * @return list<int>
     */
    private function notified(): array
    {
        return DatabaseNotification::query()->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all();
    }

    // --- Parsing boundaries ----------------------------------------------------------

    public function test_usernames_are_lower_cased_and_listed_once(): void
    {
        $this->assertSame(['bob'], Mentions::usernames('@Bob @BOB @bob'));
    }

    public function test_punctuation_ends_a_username_but_inner_dots_do_not(): void
    {
        $this->assertSame(['bob', 'carol'], Mentions::usernames('thanks @bob, and (@carol)!'));
        $this->assertSame(['bob.smith'], Mentions::usernames('ping @bob.smith.'));
        $this->assertSame(['bob_'], Mentions::usernames('@bob_ there'));
    }

    public function test_at_signs_inside_words_and_doubled_at_signs_are_not_mentions(): void
    {
        $this->assertSame([], Mentions::usernames('me@bob x@@bob a.@bob _@bob'));
    }

    public function test_a_longer_name_does_not_mention_its_prefix(): void
    {
        $this->assertFalse(Mentions::mentionsUser('@bobby @bob_x @bob.x', $this->bob));
        $this->assertTrue(Mentions::mentionsUser('see @BOB.', $this->bob));
        $this->assertTrue(Mentions::mentionsUser("line\n@bob", $this->bob));
    }

    public function test_a_user_without_a_username_is_never_mentioned(): void
    {
        $nameless = new User(['username' => '']);

        $this->assertFalse(Mentions::mentionsUser('@ hello', $nameless));
    }

    public function test_everyone_must_stand_alone(): void
    {
        $this->assertTrue(Mentions::mentionsEveryone('hi @Everyone.'));
        $this->assertFalse(Mentions::mentionsEveryone('hi @everyones'));
        $this->assertFalse(Mentions::mentionsEveryone('me@everyone'));
    }

    // --- Roles ----------------------------------------------------------------------

    public function test_role_names_with_regex_characters_are_matched_literally(): void
    {
        $cpp = $this->makeRole($this->server, 'C++', 0);
        $dot = $this->makeRole($this->server, 'a.b', 0);

        $this->assertSame([$cpp->id], $this->send('ping @c++ folks')->mentions['roles'] ?? null);
        // "." in a.b must not match any character.
        $this->assertNull($this->send('ping @axb')->mentions);
        $this->assertSame([$dot->id], $this->send('ping @A.B')->mentions['roles'] ?? null);
    }

    public function test_a_role_name_that_is_a_prefix_of_the_word_is_not_mentioned(): void
    {
        $this->makeRole($this->server, 'Mods', 0);

        $this->assertNull($this->send('hi @modsquad')->mentions);
    }

    public function test_roles_of_another_server_are_never_stored(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Elsewhere');
        $this->makeRole($other, 'Raiders', 0);

        $this->assertNull($this->send('hey @raiders')->mentions);
    }

    public function test_the_author_holding_a_mentioned_role_is_not_notified(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->carol, $mods);
        $this->giveRole($this->bob, $mods);

        $this->send('@mods look', $this->carol);

        $this->assertSame([$this->bob->id], $this->notified());
    }

    public function test_the_role_name_is_a_snapshot(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $this->send('@mods look');

        $mods->update(['name' => 'Moderators']);

        $this->actingAs($this->bob)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('notifications.0.label', 'mentioned @Mods');
    }

    // --- @everyone ------------------------------------------------------------------

    public function test_everyone_skips_members_a_channel_override_hides_it_from(): void
    {
        $secret = $this->server->channels()->create(['name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 9]);
        $outsiders = $this->makeRole($this->server, 'Outsiders', 0);
        $this->giveRole($this->carol, $outsiders);
        $this->overrideChannel($secret, $outsiders, deny: Permission::ViewChannels->value);

        $this->send('@everyone listen', channel: $secret);

        $this->assertSame([$this->bob->id], $this->notified());
    }

    // --- Edits ----------------------------------------------------------------------

    public function test_an_edit_that_keeps_the_mentions_does_not_ping_again(): void
    {
        // Faked before the first send: the notification channel manager
        // keeps the broadcast driver (and its dispatcher) once created.
        Event::fake([BroadcastNotificationCreated::class]);
        $message = $this->send('hi @bob');
        Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);

        app(EditMessage::class)->edit($message, 'hello again @bob');

        Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
        $this->assertSame([$this->bob->id], $this->notified());
    }

    public function test_an_edit_only_broadcasts_to_the_newly_mentioned(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);
        $message = $this->send('hi @bob');

        app(EditMessage::class)->edit($message, 'hi @bob and @carol');

        $to = Event::dispatched(BroadcastNotificationCreated::class)
            ->map(fn (array $args): int => (int) $args[0]->notifiable->getKey())
            ->values()
            ->all();
        $this->assertSame([$this->bob->id, $this->carol->id], $to);
    }

    public function test_a_kept_notification_keeps_its_original_reason_after_an_edit(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $message = $this->send('hi @bob');

        app(EditMessage::class)->edit($message, 'hi @mods');

        /** @var DatabaseNotification $notification */
        $notification = $this->bob->notifications()->sole();
        $this->assertSame(MentionedInMessage::REASON_USER, $notification->data['reason']);
    }

    public function test_an_edit_that_drops_every_mention_takes_all_notifications_back(): void
    {
        $message = $this->send('@bob @carol');

        app(EditMessage::class)->edit($message, 'never mind');

        $this->assertNull($message->fresh()?->mentions);
        $this->assertSame([], $this->notified());
    }

    // --- Kicks, bans, deleted servers and authors -------------------------------------

    public function test_a_kicked_member_loses_the_servers_notifications(): void
    {
        $this->send('@bob @carol');

        $this->actingAs($this->owner)->post(route('members.kick', [$this->server, $this->bob]))->assertRedirect();

        $this->assertSame([$this->carol->id], $this->notified());
    }

    public function test_a_banned_member_loses_the_servers_notifications(): void
    {
        $this->send('@bob @carol');

        $this->actingAs($this->owner)->post(route('members.ban', [$this->server, $this->bob]), ['delete_messages' => 0])->assertRedirect();

        $this->assertSame([$this->carol->id], $this->notified());
    }

    public function test_a_deleted_server_drops_out_of_the_inbox_once_it_is_opened(): void
    {
        $this->send('@bob before');
        $this->server->delete();

        $this->actingAs($this->bob)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $this->bob->notifications()->count());
    }

    public function test_a_deleted_author_shows_the_stored_name(): void
    {
        $this->send('@bob from carol', $this->carol);
        $this->carol->delete();

        $response = $this->actingAs($this->bob)->getJson(route('notifications.index'))->assertOk();

        $response->assertJsonCount(1, 'notifications');
        $response->assertJsonPath('notifications.0.author_name', 'Carol');
        $response->assertJsonPath('notifications.0.avatar_url', null);
    }

    public function test_present_falls_back_to_deleted_user_without_any_author(): void
    {
        $item = NotificationInbox::present((string) Str::uuid(), ['author' => null, 'server_id' => 1, 'channel_id' => 2, 'message_id' => 3], null, null, null);

        $this->assertSame('Deleted User', $item['author_name']);
        $this->assertFalse($item['read']);
        $this->assertNull($item['created_at']);
        $this->assertSame('mentioned you', $item['label']);
        $this->assertStringEndsWith('#message-3', $item['url']);
    }

    // --- Reading ---------------------------------------------------------------------

    public function test_opening_a_channel_only_marks_your_own_mentions_read(): void
    {
        $this->send('@bob @carol');

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))->assertOk();

        $this->assertSame(0, NotificationInbox::unreadCount($this->bob));
        $this->assertSame(1, NotificationInbox::unreadCount($this->carol));
    }

    public function test_reading_without_json_goes_back(): void
    {
        $this->send('@bob');
        $id = (string) $this->bob->notifications()->sole()->id;

        $this->actingAs($this->bob)
            ->from(route('dashboard'))
            ->post(route('notifications.read', $id))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, NotificationInbox::unreadCount($this->bob));
    }

    public function test_reading_all_without_json_or_a_previous_page_goes_to_the_inbox(): void
    {
        $this->send('@bob');

        $this->actingAs($this->bob)
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, NotificationInbox::unreadCount($this->bob));
    }

    public function test_marking_an_already_read_one_is_harmless(): void
    {
        $this->send('@bob');
        $id = (string) $this->bob->notifications()->sole()->id;

        $this->actingAs($this->bob)->postJson(route('notifications.read', $id))->assertOk()->assertJson(['unread_count' => 0]);
        $this->actingAs($this->bob)->postJson(route('notifications.read', $id))->assertOk()->assertJson(['unread_count' => 0]);
        $this->actingAs($this->bob)->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread_count' => 0]);
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $this->actingAs($this->bob)->get(route('notifications.open', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($this->bob)->get('/notifications/not-a-uuid')->assertNotFound();
        $this->actingAs($this->bob)->post('/notifications/not-a-uuid/read')->assertNotFound();
    }

    public function test_other_notification_types_are_not_in_the_inbox(): void
    {
        $this->bob->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SomethingElse',
            'data' => ['message_id' => 1],
        ]);

        $this->assertSame(0, NotificationInbox::unreadCount($this->bob));
        $this->actingAs($this->bob)->getJson(route('notifications.index'))->assertOk()->assertJsonCount(0, 'notifications');
        // Not stale-deleted either: it is not the inbox's to drop.
        $this->assertSame(1, $this->bob->notifications()->count());
    }

    public function test_the_badge_is_empty_for_none_and_exact_at_the_cap(): void
    {
        $this->assertSame('', NotificationInbox::badge(0));
        $this->assertSame('', NotificationInbox::badge(-1));
        $this->assertSame('99', NotificationInbox::badge(99));
        $this->assertSame('99+', NotificationInbox::badge(100));
    }

    // --- Suggestions -----------------------------------------------------------------

    public function test_suggestions_for_a_channel_of_another_server_are_refused(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Elsewhere');
        /** @var Channel $foreign */
        $foreign = $other->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();

        $status = $this->actingAs($this->owner)->getJson(route('mentions.index', [$this->server, $foreign]))->status();

        $this->assertContains($status, [403, 404]);
    }

    public function test_suggestions_show_the_server_nickname(): void
    {
        $this->server->memberships()->where('user_id', $this->bob->id)->update(['nickname' => 'Bobster']);

        $this->actingAs($this->owner)->getJson(route('mentions.index', [$this->server, $this->general]).'?q=bobs')
            ->assertOk()
            ->assertJsonPath('users.0.username', 'bob')
            ->assertJsonPath('users.0.name', 'Bobster');
    }

    public function test_the_everyone_suggestion_needs_a_prefix_of_everyone(): void
    {
        $url = route('mentions.index', [$this->server, $this->general]);

        $this->actingAs($this->owner)->getJson($url.'?q=EVE')->assertOk()->assertJsonPath('everyone', true);
        $this->actingAs($this->owner)->getJson($url.'?q=everyones')->assertOk()->assertJsonPath('everyone', false);
    }
}
