<?php

namespace Tests\Feature\DirectMessages;

use App\Actions\Conversations\OpenDirectConversation;
use App\Actions\Servers\CreateServer;
use App\Events\ConversationActivity;
use App\Events\DirectMessageReceived;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\ReactionsUpdated;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 1:1 direct messages. One conversation per pair of users,
 * opened from the friends page, with the channel message features (send,
 * history, edit, delete, reactions, replies, attachments), events on the
 * private "conversation.{id}" channel and a DM list in the sidebar ordered
 * by latest activity.
 */
class DirectMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'username' => 'bob']);
        $this->stranger = User::factory()->create(['name' => 'Stranger', 'username' => 'stranger']);

        Friendship::factory()->accepted()->create(['user_id' => $this->alice->id, 'friend_id' => $this->bob->id]);
    }

    private function dm(): Conversation
    {
        return Conversation::factory()->between($this->alice, $this->bob)->create();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(User $as, Conversation $conversation, array $data, array $headers = []): TestResponse
    {
        return $this->actingAs($as)->postJson(route('dms.messages.store', $conversation), $data, $headers);
    }

    private function spyBroadcaster(bool $fail = false): BaseBroadcaster
    {
        $spy = new class($fail) extends BaseBroadcaster
        {
            /** @var list<array{list<string>, string, array<string, mixed>}> */
            public array $sent = [];

            public function __construct(private bool $fail) {}

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

                $this->sent[] = [array_map(fn ($c) => (string) $c, $channels), $event, $payload];
            }
        };

        Broadcast::extend('spy', fn () => $spy);
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);

        return $spy;
    }

    // --- Opening a DM --------------------------------------------------------

    public function test_a_friend_can_open_a_dm_and_lands_on_it(): void
    {
        $response = $this->actingAs($this->alice)->post(route('dms.store'), ['user_id' => $this->bob->id]);

        $conversation = Conversation::sole();
        $response->assertRedirect(route('dms.show', $conversation));
        $this->assertTrue($conversation->isDirect());
        $this->assertSame(Conversation::directKey($this->alice, $this->bob), $conversation->direct_key);
        $this->assertEqualsCanonicalizing([$this->alice->id, $this->bob->id], $conversation->users()->pluck('users.id')->all());
    }

    public function test_a_pair_has_one_conversation_whoever_opens_it(): void
    {
        $this->actingAs($this->alice)->post(route('dms.store'), ['user_id' => $this->bob->id]);
        $this->actingAs($this->bob)->post(route('dms.store'), ['user_id' => $this->alice->id]);
        $this->actingAs($this->alice)->post(route('dms.store'), ['user_id' => $this->bob->id]);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Conversation::sole()->participants()->count());
    }

    public function test_the_direct_key_is_the_same_in_both_directions(): void
    {
        $this->assertSame('3:12', Conversation::directKey(12, 3));
        $this->assertSame(Conversation::directKey($this->alice, $this->bob), Conversation::directKey($this->bob, $this->alice));
    }

    public function test_opening_answers_json_with_the_url(): void
    {
        $response = $this->actingAs($this->alice)->postJson(route('dms.store'), ['user_id' => $this->bob->id]);

        $conversation = Conversation::sole();
        $response->assertOk()->assertExactJson([
            'conversation_id' => $conversation->id,
            'url' => route('dms.show', $conversation),
        ]);
    }

    public function test_people_sharing_a_server_can_open_a_dm(): void
    {
        $server = app(CreateServer::class)->create($this->alice, 'Shared');
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $this->stranger->id]);

        $this->actingAs($this->stranger)->post(route('dms.store'), ['user_id' => $this->alice->id])
            ->assertRedirect(route('dms.show', Conversation::sole()));
    }

    public function test_strangers_cannot_open_a_dm(): void
    {
        $this->actingAs($this->stranger)
            ->from(route('friends.index'))
            ->post(route('dms.store'), ['user_id' => $this->alice->id])
            ->assertRedirect(route('friends.index'))
            ->assertSessionHasErrors(['user' => OpenDirectConversation::unreachable()]);

        $this->assertSame(0, Conversation::count());
    }

    public function test_a_pending_request_is_not_enough_to_open_a_dm(): void
    {
        Friendship::factory()->create(['user_id' => $this->stranger->id, 'friend_id' => $this->alice->id]);

        $this->actingAs($this->stranger)->postJson(route('dms.store'), ['user_id' => $this->alice->id])
            ->assertJsonValidationErrors(['user' => OpenDirectConversation::unreachable()]);
    }

    public function test_nobody_can_dm_themselves(): void
    {
        $this->actingAs($this->alice)->postJson(route('dms.store'), ['user_id' => $this->alice->id])
            ->assertJsonValidationErrors(['user' => 'You cannot send direct messages to yourself.']);

        $this->assertSame(0, Conversation::count());
    }

    public function test_an_unknown_user_gives_the_same_error_as_a_stranger(): void
    {
        $this->actingAs($this->alice)->postJson(route('dms.store'), ['user_id' => 999999])
            ->assertJsonValidationErrors(['user' => OpenDirectConversation::unreachable()]);

        $this->actingAs($this->alice)->postJson(route('dms.store'), [])
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_a_block_in_either_direction_stops_a_new_dm_without_revealing_it(): void
    {
        $server = app(CreateServer::class)->create($this->alice, 'Shared');
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $this->stranger->id]);
        Friendship::factory()->blocked()->create(['user_id' => $this->alice->id, 'friend_id' => $this->stranger->id]);

        // The blocked one gets the same answer as a stranger.
        $this->actingAs($this->stranger)->postJson(route('dms.store'), ['user_id' => $this->alice->id])
            ->assertJsonValidationErrors(['user' => OpenDirectConversation::unreachable()]);
        $this->actingAs($this->alice)->postJson(route('dms.store'), ['user_id' => $this->stranger->id])
            ->assertJsonValidationErrors(['user']);

        $this->assertSame(0, Conversation::count());
    }

    public function test_guests_and_unverified_users_cannot_open_dms(): void
    {
        $this->post(route('dms.store'), ['user_id' => $this->bob->id])->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->post(route('dms.store'), ['user_id' => $this->bob->id])
            ->assertRedirect(route('verification.notice'));
    }

    public function test_opening_an_existing_dm_returns_it(): void
    {
        $existing = $this->dm();

        $opened = app(OpenDirectConversation::class)->open($this->bob, $this->alice);

        $this->assertTrue($opened->is($existing));
        $this->assertSame(1, Conversation::count());
    }

    public function test_the_unique_direct_key_stops_a_second_conversation_for_a_pair(): void
    {
        $this->dm();

        $this->expectException(UniqueConstraintViolationException::class);
        Conversation::query()->create(['type' => Conversation::TYPE_DIRECT, 'direct_key' => Conversation::directKey($this->alice, $this->bob)]);
    }

    // --- The conversation page ----------------------------------------------

    public function test_participants_see_the_conversation_with_its_messages(): void
    {
        $conversation = $this->dm();
        Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'Hey Alice!']);

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertOk()
            ->assertSee('Hey Alice!')
            ->assertSee('This is the beginning of your direct message history with', false)
            ->assertSee('data-conversation-id="'.$conversation->id.'"', false)
            ->assertSee('data-broadcast-channel="conversation.'.$conversation->id.'"', false)
            ->assertSee('data-typing-channel="conversation-typing.'.$conversation->id.'"', false)
            ->assertSee('data-history-url="'.route('dms.messages.index', $conversation).'"', false)
            ->assertSee('action="'.route('dms.messages.store', $conversation).'"', false)
            ->assertSee('placeholder="Message @bob"', false)
            ->assertDontSee('data-pins-panel', false)
            ->assertDontSee('data-channel-id', false);
    }

    public function test_message_actions_point_at_the_dm_routes(): void
    {
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertSee('data-message-url="'.route('dms.messages.update', [$conversation, $message]).'"', false)
            ->assertSee('data-reactions-url="'.route('dms.reactions.store', [$conversation, $message]).'"', false)
            // No moderators and no pins in a DM.
            ->assertDontSee('data-message-pin-form', false);
    }

    public function test_others_get_a_404_everywhere(): void
    {
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $as = $this->actingAs($this->stranger);
        $as->get(route('dms.show', $conversation))->assertNotFound();
        $as->getJson(route('dms.messages.index', $conversation))->assertNotFound();
        $as->postJson(route('dms.messages.store', $conversation), ['content' => 'x'])->assertNotFound();
        $as->patchJson(route('dms.messages.update', [$conversation, $message]), ['content' => 'x'])->assertNotFound();
        $as->deleteJson(route('dms.messages.destroy', [$conversation, $message]))->assertNotFound();
        $as->deleteJson(route('dms.reactions.destroy', [$conversation, $message]), ['emoji' => '👍'])->assertNotFound();

        $this->actingAs($this->alice)->get('/dms/999999')->assertNotFound();
        $this->assertNull($message->fresh()?->edited_at);
    }

    public function test_group_dms_open_on_the_same_route_with_the_group_page(): void
    {
        $group = Conversation::factory()->group()->create(['owner_id' => $this->alice->id, 'name' => 'Squad']);
        $group->users()->attach([$this->alice->id, $this->bob->id]);

        $this->actingAs($this->alice)->get(route('dms.show', $group))->assertOk()->assertSee('data-group-details', false);
        $this->send($this->alice, $group, ['content' => 'Hi all'])->assertCreated();
    }

    public function test_a_blocked_dm_keeps_its_history_but_has_no_composer(): void
    {
        $conversation = $this->dm();
        Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'Before the block']);
        Friendship::query()->delete();
        Friendship::factory()->blocked()->create(['user_id' => $this->bob->id, 'friend_id' => $this->alice->id]);

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertOk()
            ->assertSee('Before the block')
            ->assertSee('You cannot send messages in this conversation.')
            ->assertDontSee('data-message-form', false);

        // Opening it again still leads to the history.
        $this->actingAs($this->alice)->post(route('dms.store'), ['user_id' => $this->bob->id])
            ->assertRedirect(route('dms.show', $conversation));
    }

    public function test_a_dm_with_a_deleted_account_shows_deleted_user(): void
    {
        $conversation = $this->dm();
        Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'Bye']);
        $this->bob->delete();

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertOk()
            ->assertSee('Deleted User')
            ->assertSee('Bye')
            ->assertDontSee('data-message-form', false);

        $this->send($this->alice, $conversation, ['content' => 'Hello?'])->assertForbidden();
    }

    // --- The DM list in the sidebar -----------------------------------------

    public function test_the_sidebar_lists_dms_by_latest_activity(): void
    {
        $carol = User::factory()->create(['name' => 'Carol']);
        $dave = User::factory()->create(['name' => 'Dave']);

        $withBob = $this->dm();
        $withCarol = Conversation::factory()->between($this->alice, $carol)->create();
        $withDave = Conversation::factory()->between($this->alice, $dave)->create();

        $withBob->forceFill(['last_message_at' => Carbon::parse('2026-01-02')])->save();
        $withCarol->forceFill(['last_message_at' => Carbon::parse('2026-01-03')])->save();
        $withDave->forceFill(['last_message_at' => Carbon::parse('2026-01-01')])->save();

        $this->assertSame(
            [$withCarol->id, $withBob->id, $withDave->id],
            Conversation::sidebarFor($this->alice)->pluck('id')->all(),
        );

        $this->actingAs($this->alice)->get(route('friends.index'))
            ->assertOk()
            ->assertSeeInOrder(['Direct messages', 'Carol', 'Bob', 'Dave']);
    }

    public function test_sending_moves_a_dm_to_the_top(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        $carol = User::factory()->create(['name' => 'Carol']);
        Friendship::factory()->accepted()->create(['user_id' => $this->alice->id, 'friend_id' => $carol->id]);
        $withBob = $this->dm();
        $withCarol = Conversation::factory()->between($this->alice, $carol)->create();

        $this->travelTo(Carbon::parse('2026-05-01 10:00'));
        $this->send($this->alice, $withCarol, ['content' => 'First'])->assertCreated();
        $this->travelTo(Carbon::parse('2026-05-01 11:00'));
        $this->send($this->bob, $withBob, ['content' => 'Later'])->assertCreated();

        $this->assertSame([$withBob->id, $withCarol->id], Conversation::sidebarFor($this->alice)->pluck('id')->all());
        $this->assertSame('2026-05-01 11:00:00', $withBob->fresh()?->last_message_at?->format('Y-m-d H:i:s'));
    }

    public function test_an_empty_dm_is_only_listed_while_it_is_open(): void
    {
        $conversation = $this->dm();

        $this->assertTrue(Conversation::sidebarFor($this->bob)->isEmpty());
        $this->assertSame([$conversation->id], Conversation::sidebarFor($this->alice, $conversation)->pluck('id')->all());

        $this->actingAs($this->bob)->get(route('friends.index'))
            ->assertSee('No direct messages yet.');

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertSee('data-dm-id="'.$conversation->id.'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_the_sidebar_leaves_out_other_peoples_dms_and_groups(): void
    {
        $theirs = Conversation::factory()->between($this->bob, $this->stranger)->create(['last_message_at' => now()]);
        $group = Conversation::factory()->group()->create(['last_message_at' => now()]);
        $group->users()->attach([$this->bob->id, $this->stranger->id]);

        $this->assertTrue(Conversation::sidebarFor($this->alice)->isEmpty());
        $this->assertEqualsCanonicalizing([$theirs->id, $group->id], Conversation::sidebarFor($this->stranger)->pluck('id')->all());
    }

    public function test_the_friends_list_has_a_message_button(): void
    {
        $this->actingAs($this->alice)->get(route('friends.index'))
            ->assertOk()
            ->assertSee('action="'.route('dms.store').'"', false)
            ->assertSee('name="user_id" value="'.$this->bob->id.'"', false)
            ->assertSee('Message Bob');
    }

    public function test_a_failed_message_button_shows_the_error_on_the_friends_page(): void
    {
        $this->actingAs($this->alice)
            ->from(route('friends.index'))
            ->followingRedirects()
            ->post(route('dms.store'), ['user_id' => $this->stranger->id])
            ->assertSee(OpenDirectConversation::unreachable());
    }

    // --- Messages ------------------------------------------------------------

    public function test_a_participant_can_send_a_message(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        $conversation = $this->dm();

        $response = $this->send($this->alice, $conversation, ['content' => 'Hi Bob'], ['X-Socket-ID' => '1234.5678']);

        $message = Message::sole();
        $response->assertCreated()
            ->assertJsonPath('message.id', $message->id)
            ->assertJsonPath('message.channel_id', null)
            ->assertJsonPath('message.conversation_id', $conversation->id)
            ->assertJsonPath('message.content', 'Hi Bob')
            ->assertJsonPath('message.author.id', $this->alice->id);
        $this->assertNull($message->channel_id);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertNotNull($conversation->fresh()?->last_message_at);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->broadcastOn()->name === "private-conversation.{$conversation->id}"
            && $event->broadcastWith()['conversation_id'] === $conversation->id
            && $event->socket === '1234.5678');

        Event::assertDispatched(ConversationActivity::class, function (ConversationActivity $event) use ($conversation): bool {
            $channels = collect($event->broadcastOn())->pluck('name')->sort()->values()->all();
            $expected = collect([$this->alice->id, $this->bob->id])->map(fn (int $id): string => "private-App.Models.User.{$id}")->sort()->values()->all();

            return $channels === $expected
                && $event->broadcastAs() === 'conversation.activity'
                && $event->broadcastWith()['conversation_id'] === $conversation->id
                // Nothing about the content.
                && array_keys($event->broadcastWith()) === ['conversation_id', 'last_message_at']
                // Not toOthers(): the sender's other tabs re-order too.
                && $event->socket === null;
        });
    }

    public function test_the_broadcasts_go_out_on_the_conversation_and_user_channels(): void
    {
        /** @var BaseBroadcaster&object{sent: list<array{list<string>, string, array<string, mixed>}>} $spy */
        $spy = $this->spyBroadcaster();
        $conversation = $this->dm();

        $this->send($this->alice, $conversation, ['content' => 'Over the wire'])->assertCreated();

        $this->assertSame([
            [["private-conversation.{$conversation->id}"], MessageSent::NAME],
            [["private-App.Models.User.{$this->alice->id}", "private-App.Models.User.{$this->bob->id}"], ConversationActivity::NAME],
            // Browser notifications: only the other person.
            [["private-App.Models.User.{$this->bob->id}"], DirectMessageReceived::NAME],
        ], array_map(fn (array $sent): array => [$sent[0], $sent[1]], $spy->sent));
    }

    public function test_a_failed_broadcast_still_saves_the_message(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);
        $conversation = $this->dm();

        $this->send($this->alice, $conversation, ['content' => 'Reverb is down'])->assertCreated();

        $this->assertSame('Reverb is down', Message::sole()->content);
        $this->assertNotNull($conversation->fresh()?->last_message_at);
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_without_javascript_sending_redirects_back_to_the_dm(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        $conversation = $this->dm();

        $this->actingAs($this->bob)->post(route('dms.messages.store', $conversation), ['content' => 'Plain form'])
            ->assertRedirect(route('dms.show', $conversation));

        $this->assertSame('Plain form', Message::sole()->content);
    }

    public function test_outsiders_and_blocked_users_cannot_send(): void
    {
        $conversation = $this->dm();

        $this->send($this->stranger, $conversation, ['content' => 'Let me in'])->assertNotFound();

        Friendship::query()->delete();
        Friendship::factory()->blocked()->create(['user_id' => $this->alice->id, 'friend_id' => $this->bob->id]);
        $this->send($this->bob, $conversation, ['content' => 'Hello?'])->assertForbidden();
        $this->send($this->alice, $conversation, ['content' => 'Hello?'])->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_after_unfriending_the_conversation_still_works(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        $conversation = $this->dm();
        Friendship::query()->delete();

        $this->send($this->alice, $conversation, ['content' => 'Still here'])->assertCreated();
    }

    public function test_the_content_rules_are_the_same_as_in_channels(): void
    {
        $conversation = $this->dm();

        $this->send($this->alice, $conversation, ['content' => '   '])->assertJsonValidationErrors(['content' => 'Type a message first.']);
        $this->send($this->alice, $conversation, ['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)])
            ->assertJsonValidationErrors(['content']);
    }

    public function test_replies_must_point_at_a_message_of_the_same_conversation(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        $conversation = $this->dm();
        $original = Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'Question?']);
        $elsewhere = Message::factory()->inConversation(Conversation::factory()->between($this->bob, $this->stranger)->create())->create();
        $channelMessage = Message::factory()->create();

        $this->send($this->alice, $conversation, ['content' => 'Answer', 'reply_to_id' => $original->id])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $original->id)
            ->assertJsonPath('message.reply_to.content', 'Question?');

        foreach ([$elsewhere, $channelMessage] as $other) {
            $this->send($this->alice, $conversation, ['content' => 'Nope', 'reply_to_id' => $other->id])
                ->assertJsonValidationErrors(['reply_to_id']);
        }
    }

    public function test_attachments_are_stored_per_conversation(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);
        Storage::fake(MessageAttachment::disk());
        $conversation = $this->dm();

        $this->send($this->alice, $conversation, ['attachments' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]])
            ->assertCreated();

        $path = MessageAttachment::sole()->path;
        $this->assertStringStartsWith("attachments/conversations/{$conversation->id}/", $path);
        Storage::disk(MessageAttachment::disk())->assertExists($path);
    }

    public function test_history_pages_like_a_channel(): void
    {
        $conversation = $this->dm();
        Message::factory()->count(55)->inConversation($conversation)->create(['user_id' => $this->bob->id]);
        Message::factory()->count(3)->create(); // channel messages stay out

        $first = $this->actingAs($this->alice)->getJson(route('dms.messages.index', $conversation))->assertOk();
        $this->assertCount(50, $first->json('messages'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->actingAs($this->alice)
            ->getJson(route('dms.messages.index', [$conversation, 'before' => $first->json('next_cursor')]))
            ->assertOk();
        $this->assertCount(5, $second->json('messages'));
        $this->assertNull($second->json('next_cursor'));

        $this->actingAs($this->alice)->getJson(route('dms.messages.index', [$conversation, 'before' => 'nonsense']))
            ->assertJsonValidationErrors(['before']);
    }

    public function test_authors_edit_their_own_messages_and_the_edit_is_broadcast(): void
    {
        Event::fake([MessageUpdated::class]);
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id, 'content' => 'Typo']);

        $this->actingAs($this->bob)->patchJson(route('dms.messages.update', [$conversation, $message]), ['content' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($this->alice)->patchJson(route('dms.messages.update', [$conversation, $message]), ['content' => 'Fixed'])
            ->assertOk()
            ->assertJsonPath('message.content', 'Fixed');

        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->broadcastOn()->name === "private-conversation.{$conversation->id}");
    }

    public function test_only_the_author_deletes_and_the_delete_is_broadcast(): void
    {
        Event::fake([MessageDeleted::class]);
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $this->actingAs($this->bob)->deleteJson(route('dms.messages.destroy', [$conversation, $message]))->assertForbidden();
        $this->actingAs($this->alice)->deleteJson(route('dms.messages.destroy', [$conversation, $message]))->assertNoContent();

        $this->assertSoftDeleted($message);
        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event): bool => $event->broadcastOn()->name === "private-conversation.{$conversation->id}"
            && $event->broadcastWith() === ['id' => $message->id, 'channel_id' => null, 'conversation_id' => $conversation->id]);
    }

    public function test_messages_of_another_conversation_are_a_404(): void
    {
        $conversation = $this->dm();
        $theirs = Conversation::factory()->between($this->alice, $this->stranger)->create();
        $message = Message::factory()->inConversation($theirs)->create(['user_id' => $this->alice->id]);
        $channelMessage = Message::factory()->create(['user_id' => $this->alice->id]);

        foreach ([$message, $channelMessage] as $other) {
            $this->actingAs($this->alice)->patchJson("/dms/{$conversation->id}/messages/{$other->id}", ['content' => 'x'])->assertNotFound();
            $this->actingAs($this->alice)->deleteJson("/dms/{$conversation->id}/messages/{$other->id}")->assertNotFound();
        }
    }

    public function test_both_participants_can_react_and_reactions_are_broadcast(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $this->actingAs($this->bob)->postJson(route('dms.reactions.store', [$conversation, $message]), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '👍')
            ->assertJsonPath('reactions.0.me', true);
        $this->actingAs($this->alice)->postJson(route('dms.reactions.store', [$conversation, $message]), ['emoji' => '👍'])
            ->assertJsonPath('reactions.0.count', 2);
        $this->actingAs($this->bob)->deleteJson(route('dms.reactions.destroy', [$conversation, $message]), ['emoji' => '👍'])
            ->assertJsonPath('reactions.0.count', 1);

        $this->actingAs($this->stranger)->postJson(route('dms.reactions.store', [$conversation, $message]), ['emoji' => '👍'])
            ->assertNotFound();

        Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $event): bool => $event->broadcastOn()->name === "private-conversation.{$conversation->id}"
            && $event->broadcastWith()['conversation_id'] === $conversation->id
            && $event->broadcastWith()['channel_id'] === null);
    }

    public function test_dm_messages_cannot_be_pinned(): void
    {
        $conversation = $this->dm();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $this->assertFalse($this->alice->can('pin', $message));
        $this->assertTrue($this->alice->can('update', $message));
        $this->assertTrue($this->bob->can('react', $message));
        $this->assertFalse($this->stranger->can('react', $message));
    }
}
