<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-14: posting a message stores it and broadcasts MessageSent on the
 * private "channel.{id}" channel.
 */
class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postMessage(User $as, array $data, ?Channel $channel = null, array $headers = []): TestResponse
    {
        $channel ??= $this->channel;

        return $this->actingAs($as)->postJson(
            "/servers/{$channel->server_id}/channels/{$channel->id}/messages",
            $data,
            $headers,
        );
    }

    /**
     * Records what reaches the broadcaster, like the real Reverb driver would get it.
     */
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

    // --- Sending ---------------------------------------------------------

    public function test_a_member_can_post_a_message(): void
    {
        Event::fake([MessageSent::class]);
        $this->travelTo('2026-03-04 05:06:07');

        $response = $this->postMessage($this->member, ['content' => 'Hello, world!'])->assertCreated();

        $message = Message::sole();
        $this->assertSame($this->channel->id, $message->channel_id);
        $this->assertNull($message->conversation_id);
        $this->assertSame($this->member->id, $message->user_id);
        $this->assertSame('Hello, world!', $message->content);

        $response->assertExactJson(['message' => [
            'id' => $message->id,
            'channel_id' => $this->channel->id,
            'conversation_id' => null,
            'content' => 'Hello, world!',
            'mentions' => null,
            'created_at' => '2026-03-04T05:06:07+00:00',
            'edited_at' => null,
            'pinned_at' => null,
            'author' => [
                'id' => $this->member->id,
                'name' => 'Member',
                'username' => $this->member->username,
                'avatar_url' => $this->member->avatarUrl(),
                'profile_url' => route('users.profile', $this->member),
            ],
            'reply_to' => null,
            'reactions' => [],
            'attachments' => [],
        ]]);
    }

    public function test_the_owner_can_post_a_message(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->owner, ['content' => 'Welcome!'])->assertCreated();

        $this->assertSame($this->owner->id, Message::sole()->user_id);
    }

    public function test_posting_broadcasts_message_sent_on_the_private_channel_to_others(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, ['content' => 'Hi'], headers: ['X-Socket-ID' => '1234.5678'])->assertCreated();

        $message = Message::sole();
        Event::assertDispatchedTimes(MessageSent::class, 1);
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($message): bool {
            return $event->message->is($message)
                && $event->broadcastOn()->name === "private-channel.{$this->channel->id}"
                && $event->broadcastAs() === 'message.sent'
                && $event->broadcastWith() === $message->fresh()?->toPayload()
                // toOthers(): the sender's own socket is skipped.
                && $event->socket === '1234.5678';
        });
    }

    public function test_the_broadcast_reaches_the_broadcaster_right_away(): void
    {
        // In dev QUEUE_CONNECTION=database; messages must not wait for a worker.
        config(['queue.default' => 'database']);
        /** @var BaseBroadcaster&object{sent: list<array{list<string>, string, array<string, mixed>}>} $spy */
        $spy = $this->spyBroadcaster();

        $this->postMessage($this->member, ['content' => 'Instant'])->assertCreated();

        $this->assertCount(1, $spy->sent);
        [$channels, $name, $payload] = $spy->sent[0];
        $this->assertSame(["private-channel.{$this->channel->id}"], $channels);
        $this->assertSame('message.sent', $name);
        $this->assertSame('Instant', $payload['content']);
        $this->assertSame($this->member->id, $payload['author']['id']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_failed_broadcast_still_saves_the_message_and_is_reported(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);

        $this->postMessage($this->member, ['content' => 'Saved anyway'])->assertCreated()
            ->assertJsonPath('message.content', 'Saved anyway');

        $this->assertSame('Saved anyway', Message::sole()->content);
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_a_form_post_without_javascript_redirects_back_to_the_channel(): void
    {
        Event::fake([MessageSent::class]);

        $this->actingAs($this->member)
            ->post("/servers/{$this->server->id}/channels/{$this->channel->id}/messages", ['content' => 'No JS'])
            ->assertRedirect("/servers/{$this->server->id}/channels/{$this->channel->id}");

        $this->assertSame('No JS', Message::sole()->content);
    }

    public function test_content_is_trimmed_and_keeps_inner_newlines(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, ['content' => "  line one\nline two  \n"])->assertCreated();

        $this->assertSame("line one\nline two", Message::sole()->content);
    }

    public function test_html_is_stored_as_typed_and_escaped_on_the_page(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, ['content' => '<script>alert(1)</script>'])->assertCreated()
            ->assertJsonPath('message.content', '<script>alert(1)</script>');

        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}/channels/{$this->channel->id}")
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_message_of_exactly_the_maximum_length_is_accepted(): void
    {
        Event::fake([MessageSent::class]);

        // Multibyte characters count as one each, like Discord.
        $content = str_repeat('é', Message::MAX_CONTENT_LENGTH);

        $this->postMessage($this->member, ['content' => $content])->assertCreated();

        $this->assertSame($content, Message::sole()->content);
    }

    // --- Validation ------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidContent(): array
    {
        return [
            'missing' => [[], 'Type a message first.'],
            'empty' => [['content' => ''], 'Type a message first.'],
            'whitespace only' => [['content' => "  \n\t "], 'Type a message first.'],
            'too long' => [['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)], 'Messages can be at most 2000 characters long.'],
            'array' => [['content' => ['hello']], 'The content field must be a string.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidContent')]
    public function test_invalid_content_is_rejected(array $data, string $error): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => $error]);

        $this->assertSame(0, Message::count());
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_messages_cannot_be_sent_in_a_voice_channel(): void
    {
        Event::fake([MessageSent::class]);
        $voice = Channel::factory()->create(['server_id' => $this->server->id, 'type' => Channel::TYPE_VOICE, 'name' => 'Lounge']);

        $this->postMessage($this->member, ['content' => 'Hello?'], $voice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'You cannot send messages in a voice channel.']);

        $this->assertSame(0, Message::count());
    }

    // --- Access ----------------------------------------------------------

    public function test_guests_and_unverified_users_cannot_post(): void
    {
        $url = "/servers/{$this->server->id}/channels/{$this->channel->id}/messages";

        $this->post($url, ['content' => 'Hi'])->assertRedirect('/login');
        $this->postJson($url, ['content' => 'Hi'])->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $unverified->id]);
        $this->actingAs($unverified)->post($url, ['content' => 'Hi'])->assertRedirect('/email/verify');

        $this->assertSame(0, Message::count());
    }

    public function test_non_members_cannot_post(): void
    {
        Event::fake([MessageSent::class]);
        $outsider = User::factory()->create();

        $this->postMessage($outsider, ['content' => 'Let me in'])->assertForbidden();

        $this->assertSame(0, Message::count());
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_a_member_who_left_can_no_longer_post(): void
    {
        ServerMember::where('server_id', $this->server->id)->where('user_id', $this->member->id)->delete();

        $this->postMessage($this->member, ['content' => 'Still here?'])->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_a_channel_of_another_server_is_not_found(): void
    {
        $other = app(CreateServer::class)->create($this->member, 'Other Server');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();

        // The member belongs to both servers, but the channel is not in this one.
        $this->actingAs($this->member)
            ->postJson("/servers/{$this->server->id}/channels/{$otherChannel->id}/messages", ['content' => 'Hi'])
            ->assertNotFound();

        $this->actingAs($this->member)
            ->postJson("/servers/{$this->server->id}/channels/999999/messages", ['content' => 'Hi'])
            ->assertNotFound();

        $this->assertSame(0, Message::count());
    }

    public function test_authorisation_is_checked_before_validation(): void
    {
        $outsider = User::factory()->create();

        $this->postMessage($outsider, ['content' => ''])->assertForbidden();
    }

    public function test_sending_is_rate_limited(): void
    {
        Event::fake([MessageSent::class]);

        for ($i = 1; $i <= 30; $i++) {
            $this->postMessage($this->member, ['content' => "Message {$i}"])->assertCreated();
        }

        $this->postMessage($this->member, ['content' => 'One too many'])->assertTooManyRequests();
        $this->assertSame(30, Message::count());
    }

    public function test_user_id_and_channel_id_cannot_be_overridden(): void
    {
        Event::fake([MessageSent::class]);
        $otherChannel = Channel::factory()->create(['server_id' => $this->server->id]);

        $this->postMessage($this->member, [
            'content' => 'Mine',
            'user_id' => $this->owner->id,
            'channel_id' => $otherChannel->id,
            'conversation_id' => 1,
        ])->assertCreated();

        $message = Message::sole();
        $this->assertSame($this->member->id, $message->user_id);
        $this->assertSame($this->channel->id, $message->channel_id);
        $this->assertNull($message->conversation_id);
    }
}
