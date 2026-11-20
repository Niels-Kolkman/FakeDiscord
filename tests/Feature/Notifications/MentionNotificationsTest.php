<?php

namespace Tests\Feature\Notifications;

use App\Actions\Messages\SendMessage;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Support\MentionNotifier;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GivesPermissions;
use Tests\Concerns\RunsTheQueueWorker;
use Tests\TestCase;

/**
 * ISSUE-33: mentioning someone stores a database notification for them and
 * broadcasts it on their private user channel; edits, deletes and leaving
 * keep the notifications in step.
 */
class MentionNotificationsTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;
    use RunsTheQueueWorker;

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
        $this->carol = User::factory()->create(['username' => 'carol']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Pings');
        $this->joinServer($this->server, $this->bob);
        $this->joinServer($this->server, $this->carol);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
    }

    /**
     * @return TestResponse<Response>
     */
    private function say(User $as, string $content, ?Channel $channel = null): TestResponse
    {
        $channel ??= $this->general;

        return $this->actingAs($as)->postJson(route('messages.store', [$channel->server_id, $channel]), ['content' => $content]);
    }

    /**
     * @return list<int>
     */
    private function notified(): array
    {
        return DatabaseNotification::query()->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function notificationOf(User $user): DatabaseNotification
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->firstOrFail();
    }

    /**
     * What reached the broadcaster: [channels, event name, payload].
     *
     * @var list<array{list<string>, string, array<string, mixed>}>
     */
    private array $sent = [];

    /**
     * Records what reaches the broadcaster in $sent, like the real Reverb
     * driver would get it, or fails like Reverb being down.
     */
    private function spyBroadcaster(bool $fail = false): void
    {
        $record = function (array $channels, string $event, array $payload): void {
            $this->sent[] = [array_values(array_map(fn ($c): string => (string) $c, $channels)), $event, $payload];
        };

        $spy = new class($fail, $record) extends BaseBroadcaster
        {
            public function __construct(private bool $fail, private \Closure $record) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                if ($this->fail) {
                    throw new BroadcastException('Reverb is not running');
                }
                ($this->record)($channels, (string) $event, $payload);
            }
        };

        Broadcast::extend('spy', fn () => $spy);
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);
    }

    // --- Who is notified ---------------------------------------------------------

    public function test_a_mentioned_member_gets_a_database_notification(): void
    {
        $this->say($this->owner, 'hey @bob, look at this')->assertCreated();
        $message = Message::sole();

        $notification = $this->notificationOf($this->bob);
        $this->assertSame(MentionedInMessage::class, $notification->type);
        $this->assertNull($notification->read_at);
        $this->assertSame([
            'message_id' => $message->id,
            'channel_id' => $this->general->id,
            'server_id' => $this->server->id,
            'reason' => 'user',
            'role_name' => null,
            'author' => ['id' => $this->owner->id, 'name' => 'Owner'],
            'server_name' => 'Pings',
            'channel_name' => $this->general->name,
            'excerpt' => 'hey @bob, look at this',
        ], $notification->data);
        $this->assertSame([$this->bob->id], $this->notified());
    }

    public function test_mentioning_yourself_does_nothing(): void
    {
        $this->say($this->bob, 'note to self @bob')->assertCreated();

        $this->assertSame([], $this->notified());
    }

    public function test_nobody_is_notified_by_a_plain_message(): void
    {
        $this->say($this->owner, 'hello bob')->assertCreated();

        $this->assertSame([], $this->notified());
    }

    public function test_people_who_blocked_the_author_are_not_notified(): void
    {
        Friendship::factory()->create([
            'user_id' => $this->bob->id, 'friend_id' => $this->carol->id, 'status' => Friendship::STATUS_BLOCKED,
        ]);

        $this->say($this->carol, '@bob @owner')->assertCreated();

        $this->assertSame([$this->owner->id], $this->notified());
    }

    public function test_members_who_cannot_see_the_channel_are_not_notified(): void
    {
        $secret = $this->server->channels()->create(['name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 9, 'is_private' => true]);

        $this->say($this->owner, '@bob @carol', $secret)->assertCreated();

        $this->assertSame([], $this->notified());
    }

    public function test_role_holders_are_notified_with_the_role_name(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $this->giveRole($this->carol, $mods);

        $this->say($this->carol, 'paging @Mods')->assertCreated();

        $this->assertSame([$this->bob->id], $this->notified());
        $this->assertSame('role', $this->notificationOf($this->bob)->data['reason']);
        $this->assertSame('Mods', $this->notificationOf($this->bob)->data['role_name']);
    }

    public function test_everyone_notifies_every_member_who_can_see_the_channel(): void
    {
        $this->say($this->owner, '@everyone meeting at 8')->assertCreated();

        $this->assertSame([$this->bob->id, $this->carol->id], $this->notified());
        $this->assertSame('everyone', $this->notificationOf($this->bob)->data['reason']);
    }

    public function test_everyone_without_the_permission_notifies_nobody(): void
    {
        $this->say($this->bob, '@everyone free pizza')->assertCreated();
        $this->assertSame([], $this->notified());

        $herald = $this->memberWith($this->server, Permission::MentionEveryone);
        $this->say($herald, '@everyone free pizza')->assertCreated();
        $this->assertSame([$this->owner->id, $this->bob->id, $this->carol->id], $this->notified());
    }

    public function test_one_notification_per_person_with_the_most_direct_reason(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $this->giveRole($this->carol, $mods);

        $this->say($this->owner, '@everyone @mods and especially @bob @bob')->assertCreated();

        $this->assertSame([$this->bob->id, $this->carol->id], $this->notified());
        $this->assertSame('user', $this->notificationOf($this->bob)->data['reason']);
        $this->assertSame('role', $this->notificationOf($this->carol)->data['reason']);
    }

    public function test_dms_send_no_mention_notifications(): void
    {
        $conversation = Conversation::factory()->create();

        app(SendMessage::class)->send($this->owner, $conversation, 'hi @bob');

        $this->assertSame([], $this->notified());
    }

    // --- Broadcasting --------------------------------------------------------------

    public function test_the_notification_is_stored_at_once_and_broadcast_by_the_worker(): void
    {
        // ISSUE-42: the inbox row is written by the request, the broadcast
        // goes through the database queue.
        config(['queue.default' => 'database']);
        $this->spyBroadcaster();

        $this->say($this->owner, 'hey @bob')->assertCreated();

        $this->assertSame(1, $this->bob->notifications()->count());
        $this->assertSame([], $this->sent);
        $this->runQueueWorker();

        $notifications = array_values(array_filter(
            $this->sent,
            fn (array $sent): bool => $sent[1] === 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated',
        ));
        $this->assertCount(1, $notifications);
        $sent = $notifications[0];
        $this->assertSame(['private-App.Models.User.'.$this->bob->id], $sent[0]);

        $payload = $sent[2];
        $notification = $this->notificationOf($this->bob);
        $this->assertSame($notification->id, $payload['id']);
        $this->assertSame('mention.created', $payload['type']);
        $this->assertSame('mentioned you', $payload['label']);
        $this->assertSame('Owner', $payload['author_name']);
        $this->assertFalse($payload['read']);
        $this->assertSame($this->general->id, $payload['channel_id']);
        $this->assertSame(route('notifications.open', $notification->id), $payload['open_url']);
        $this->assertStringEndsWith('#message-'.Message::sole()->id, $payload['url']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_failed_broadcast_keeps_the_message_and_the_notifications(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);

        $this->say($this->owner, '@bob @carol')->assertCreated();

        $this->assertSame([$this->bob->id, $this->carol->id], $this->notified());
        Exceptions::assertReported(BroadcastException::class);
    }

    // --- Edits, deletes and leaving ----------------------------------------------------

    public function test_an_edit_notifies_new_mentions_and_takes_back_removed_ones(): void
    {
        $this->say($this->owner, 'hi @bob')->assertCreated();
        $message = Message::sole();
        $bobs = $this->notificationOf($this->bob)->id;

        $this->actingAs($this->owner)
            ->patchJson(route('messages.update', [$this->server, $this->general, $message]), ['content' => 'hi @bob and @carol'])
            ->assertOk();

        $this->assertSame([$this->bob->id, $this->carol->id], $this->notified());
        // bob keeps the one he had: no second ping.
        $this->assertSame($bobs, $this->notificationOf($this->bob)->id);

        $this->actingAs($this->owner)
            ->patchJson(route('messages.update', [$this->server, $this->general, $message]), ['content' => 'hi @carol'])
            ->assertOk();

        $this->assertSame([$this->carol->id], $this->notified());
    }

    public function test_deleting_the_message_deletes_its_notifications(): void
    {
        $this->say($this->owner, '@bob @carol')->assertCreated();
        $this->say($this->owner, 'and again @bob')->assertCreated();
        $first = Message::query()->orderBy('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson(route('messages.destroy', [$this->server, $this->general, $first]))
            ->assertSuccessful();

        $this->assertSame([$this->bob->id], $this->notified());
    }

    public function test_leaving_the_server_deletes_its_notifications_only(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $this->joinServer($other, $this->bob);
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();

        $this->say($this->owner, '@bob here')->assertCreated();
        $this->say($this->owner, '@bob there', $otherChannel)->assertCreated();
        $this->assertCount(2, $this->bob->notifications()->get());

        $this->actingAs($this->bob)->delete(route('servers.leave', $this->server))->assertRedirect();

        $left = $this->bob->notifications()->get();
        $this->assertCount(1, $left);
        $this->assertSame($other->id, $left->sole()->data['server_id']);
    }

    public function test_opening_a_channel_marks_its_mentions_read(): void
    {
        $random = $this->server->channels()->create(['name' => 'random', 'type' => Channel::TYPE_TEXT, 'position' => 50]);
        $this->say($this->owner, '@bob in general')->assertCreated();
        $this->say($this->owner, '@bob in random', $random)->assertCreated();

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $random]))->assertOk();

        $read = $this->bob->notifications()->get()
            ->mapWithKeys(fn (DatabaseNotification $n): array => [$n->data['channel_name'] => $n->read_at !== null]);
        $this->assertTrue($read['random']);
        $this->assertFalse($read[$this->general->name]);
    }

    public function test_marking_read_up_to_a_message_leaves_later_mentions_unread(): void
    {
        $this->say($this->owner, '@bob one')->assertCreated();
        $first = Message::query()->orderBy('id')->firstOrFail();
        $this->say($this->owner, '@bob two')->assertCreated();

        $this->actingAs($this->bob)
            ->postJson(route('channels.read', [$this->server, $this->general]), ['message_id' => $first->id])
            ->assertOk();

        $this->assertSame(1, $this->bob->unreadNotifications()->count());
    }

    public function test_recipients_skip_members_who_have_left(): void
    {
        $message = Message::factory()->inChannel($this->general)->create(['user_id' => $this->owner->id, 'content' => '@bob']);
        $this->server->memberships()->where('user_id', $this->bob->id)->delete();

        $this->assertSame([], app(MentionNotifier::class)->recipients($message));
    }
}
