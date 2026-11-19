<?php

namespace Tests\Feature\Notifications;

use App\Actions\Messages\SendMessage;
use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-33: the notification inbox: the bell and its count, the JSON for
 * the panel, the page, opening and marking read, and dropping entries
 * that no longer lead anywhere.
 */
class NotificationInboxTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private Server $server;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner', 'name' => 'Owner']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Inbox');
        $this->joinServer($this->server, $this->bob);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
    }

    private function mentionBob(string $content = 'hey @bob', ?User $author = null, ?Channel $channel = null): Message
    {
        return app(SendMessage::class)->send($author ?? $this->owner, $channel ?? $this->general, $content);
    }

    private function notificationFor(Message $message): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $this->bob->notifications()->where('data->message_id', $message->id)->firstOrFail();
    }

    // --- JSON and page --------------------------------------------------------------

    public function test_the_json_lists_the_newest_first_with_the_unread_count(): void
    {
        $first = $this->mentionBob('first @bob');
        $this->travel(1)->minutes();
        $second = $this->mentionBob('second @bob');
        $this->notificationFor($first)->markAsRead();

        $response = $this->actingAs($this->bob)->getJson(route('notifications.index'))->assertOk();

        $response->assertJsonPath('unread_count', 1);
        $response->assertJsonCount(2, 'notifications');
        $response->assertJsonPath('notifications.0.message_id', $second->id);
        $response->assertJsonPath('notifications.0.read', false);
        $response->assertJsonPath('notifications.0.label', 'mentioned you');
        $response->assertJsonPath('notifications.0.author_name', 'Owner');
        $response->assertJsonPath('notifications.0.excerpt', 'second @bob');
        $response->assertJsonPath('notifications.0.avatar_url', $this->owner->avatarUrl());
        $response->assertJsonPath('notifications.1.read', true);
    }

    public function test_labels_name_the_role_or_everyone(): void
    {
        $this->assertSame('mentioned you', NotificationInbox::label('user'));
        $this->assertSame('mentioned @Mods', NotificationInbox::label('role', 'Mods'));
        $this->assertSame('mentioned @a role', NotificationInbox::label('role', null));
        $this->assertSame('mentioned @everyone', NotificationInbox::label('everyone'));
    }

    public function test_the_page_lists_them_without_javascript(): void
    {
        $message = $this->mentionBob('look here @bob');

        $this->actingAs($this->bob)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('look here @bob')
            ->assertSee(route('notifications.open', $this->notificationFor($message)->id), false)
            ->assertSee('1 unread')
            ->assertSee('Mark all read');
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $this->actingAs($this->bob)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No mentions yet')
            ->assertSee('Nothing unread');
    }

    public function test_guests_and_unverified_users_are_sent_away(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('notifications.index'))->assertRedirect(route('verification.notice'));
    }

    // --- Stale entries -------------------------------------------------------------------

    public function test_entries_that_no_longer_lead_anywhere_are_dropped_and_deleted(): void
    {
        $kept = $this->mentionBob('kept @bob');

        // Now blocked.
        $carol = $this->joinServer($this->server);
        $this->mentionBob('blocked @bob', $carol);
        Friendship::factory()->create(['user_id' => $this->bob->id, 'friend_id' => $carol->id, 'status' => Friendship::STATUS_BLOCKED]);

        // A channel that became private.
        $hiddenChannel = $this->server->channels()->create(['name' => 'later-private', 'type' => Channel::TYPE_TEXT, 'position' => 9]);
        $this->mentionBob('hidden @bob', channel: $hiddenChannel);
        $hiddenChannel->update(['is_private' => true]);

        // A message gone without DeleteMessage.
        $gone = $this->mentionBob('gone @bob');
        Message::query()->whereKey($gone->id)->forceDelete();

        $this->assertSame(4, NotificationInbox::unreadCount($this->bob));

        $response = $this->actingAs($this->bob)->getJson(route('notifications.index'))->assertOk();

        $response->assertJsonCount(1, 'notifications');
        $response->assertJsonPath('notifications.0.message_id', $kept->id);
        $response->assertJsonPath('unread_count', 1);
        $this->assertSame(1, $this->bob->notifications()->count());
    }

    // --- Opening and marking read ----------------------------------------------------

    public function test_opening_marks_it_read_and_goes_to_the_message(): void
    {
        $message = $this->mentionBob();
        $notification = $this->notificationFor($message);

        $this->actingAs($this->bob)->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('channels.show', [$this->server, $this->general]).'#message-'.$message->id);

        $this->assertNotNull($notification->fresh()?->read_at);
    }

    public function test_marking_one_read(): void
    {
        $notification = $this->notificationFor($this->mentionBob());
        $this->mentionBob('again @bob');

        $this->actingAs($this->bob)->postJson(route('notifications.read', $notification->id))
            ->assertOk()
            ->assertExactJson(['unread_count' => 1]);

        $this->assertNotNull($notification->fresh()?->read_at);
    }

    public function test_marking_all_read(): void
    {
        $this->mentionBob();
        $this->mentionBob('again @bob');

        $this->actingAs($this->bob)->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertExactJson(['unread_count' => 0]);

        $this->actingAs($this->bob)->from(route('notifications.index'))->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_someone_elses_notification_is_a_404(): void
    {
        $notification = $this->notificationFor($this->mentionBob());

        $this->actingAs($this->owner)->get(route('notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('notifications.read', $notification->id))->assertNotFound();
        $this->actingAs($this->owner)->get('/notifications/'.Str::uuid())->assertNotFound();
        $this->actingAs($this->owner)->get('/notifications/not-a-uuid')->assertNotFound();

        $this->assertNull($notification->fresh()?->read_at);
    }

    public function test_mark_all_read_leaves_other_users_alone(): void
    {
        $this->mentionBob();
        app(SendMessage::class)->send($this->bob, $this->general, 'back at you @owner');

        $this->actingAs($this->bob)->postJson(route('notifications.read-all'))->assertOk();

        $this->assertSame(1, NotificationInbox::unreadCount($this->owner));
    }

    // --- The bell ------------------------------------------------------------------------

    public function test_the_bell_shows_the_unread_count_on_app_pages(): void
    {
        $this->mentionBob();
        $this->mentionBob('again @bob');

        // Opening the server page would read #general, so bob looks from
        // the friends page; the owner's server page has the bell too.
        $this->actingAs($this->bob)->get(route('friends.index'))
            ->assertOk()
            ->assertSee('data-inbox-url="'.route('notifications.index').'"', false)
            ->assertSee('data-unread-count="2"', false);

        $this->actingAs($this->owner)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertSee('data-inbox-url', false)
            ->assertSee('data-unread-count="0"', false);

        $this->actingAs($this->bob)->get(route('dashboard'))->assertOk()->assertSee('data-dashboard-inbox', false);
    }

    public function test_the_badge_caps_at_99(): void
    {
        $this->assertSame('', NotificationInbox::badge(0));
        $this->assertSame('7', NotificationInbox::badge(7));
        $this->assertSame('99', NotificationInbox::badge(99));
        $this->assertSame('99+', NotificationInbox::badge(100));
    }

    public function test_the_list_is_capped(): void
    {
        foreach (range(1, NotificationInbox::LIMIT + 2) as $i) {
            $this->mentionBob("ping {$i} @bob");
        }

        $this->assertCount(NotificationInbox::LIMIT, NotificationInbox::items($this->bob));
    }
}
