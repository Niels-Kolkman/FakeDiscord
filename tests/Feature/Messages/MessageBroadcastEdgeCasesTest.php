<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Edge cases around the MessageSent broadcast, its payload and the
 * initial message feed, complementing SendMessageTest.
 */
class MessageBroadcastEdgeCasesTest extends TestCase
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
     * @return BaseBroadcaster&object{sent: list<array{list<string>, string, array<string, mixed>}>}
     */
    private function spyBroadcaster(): BaseBroadcaster
    {
        $spy = new class extends BaseBroadcaster
        {
            /** @var list<array{list<string>, string, array<string, mixed>}> */
            public array $sent = [];

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = [array_map(fn ($c) => (string) $c, $channels), $event, $payload];
            }
        };

        Broadcast::extend('spy', fn () => $spy);
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);

        return $spy;
    }

    // --- The event -------------------------------------------------------

    public function test_the_event_is_queued_on_the_messages_channel(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);
        $event = new MessageSent($message);

        // ISSUE-42: queued, the worker sends it.
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertNotContains(ShouldBroadcastNow::class, class_implements(MessageSent::class));
        $this->assertSame("channel.{$this->channel->id}", MessageSent::channelName($this->channel->id));
        $this->assertSame("private-channel.{$this->channel->id}", $event->broadcastOn()->name);
        $this->assertSame('message.sent', $event->broadcastAs());
        $this->assertSame($message->toPayload(), $event->broadcastWith());
    }

    public function test_the_payload_of_a_deleted_author_has_no_author(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => null, 'content' => 'Orphan']);

        $payload = (new MessageSent($message))->broadcastWith();

        $this->assertNull($payload['author']);
        $this->assertSame('Orphan', $payload['content']);
        $this->assertSame($this->channel->id, $payload['channel_id']);
        $this->assertSame('Deleted User', $message->authorName());
    }

    // --- Broadcasting from the endpoint ---------------------------------

    public function test_without_a_socket_id_the_message_is_still_broadcast_to_everyone(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, ['content' => 'No socket'])->assertCreated();

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->socket === null);
    }

    public function test_a_message_is_broadcast_only_on_the_channel_it_was_posted_in(): void
    {
        $spy = $this->spyBroadcaster();
        $random = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'random']);

        $this->postMessage($this->member, ['content' => 'In random'], $random)->assertCreated();
        $this->postMessage($this->owner, ['content' => 'In general'])->assertCreated();

        $this->assertCount(2, $spy->sent);
        $this->assertSame(["private-channel.{$random->id}"], $spy->sent[0][0]);
        $this->assertSame('In random', $spy->sent[0][2]['content']);
        $this->assertSame(["private-channel.{$this->channel->id}"], $spy->sent[1][0]);
        $this->assertSame('In general', $spy->sent[1][2]['content']);
    }

    public function test_receivers_get_the_same_payload_as_the_sender(): void
    {
        $spy = $this->spyBroadcaster();

        $response = $this->postMessage($this->member, ['content' => 'Same everywhere'])->assertCreated();

        $this->assertCount(1, $spy->sent);
        $payload = $spy->sent[0][2];
        // Laravel adds the socket id to every broadcast payload.
        unset($payload['socket']);
        $this->assertSame($response->json('message'), json_decode((string) json_encode($payload), true));
    }

    public function test_rejected_posts_never_reach_the_broadcaster(): void
    {
        $spy = $this->spyBroadcaster();
        $voice = Channel::factory()->create(['server_id' => $this->server->id, 'type' => Channel::TYPE_VOICE]);

        $this->postMessage($this->member, ['content' => ''])->assertUnprocessable();
        $this->postMessage($this->member, ['content' => 'Hi'], $voice)->assertUnprocessable();
        $this->postMessage(User::factory()->create(), ['content' => 'Hi'])->assertForbidden();

        $this->assertSame([], $spy->sent);
    }

    // --- Request handling -------------------------------------------------

    public function test_the_rate_limit_is_per_user(): void
    {
        Event::fake([MessageSent::class]);

        for ($i = 1; $i <= 30; $i++) {
            $this->postMessage($this->member, ['content' => "Spam {$i}"])->assertCreated();
        }
        $this->postMessage($this->member, ['content' => 'Blocked'])->assertTooManyRequests();

        $this->postMessage($this->owner, ['content' => 'Not affected'])->assertCreated();
    }

    public function test_edit_and_pin_fields_cannot_be_set_through_the_request(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, [
            'content' => 'Plain message',
            'edited_at' => '2020-01-01 00:00:00',
            'pinned_at' => '2020-01-01 00:00:00',
            'pinned_by_id' => $this->member->id,
        ])->assertCreated();

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('Plain message', $message->content);
        $this->assertNull($message->reply_to_id);
        $this->assertNull($message->edited_at);
        $this->assertNull($message->pinned_at);
        $this->assertNull($message->pinned_by_id);
    }

    public function test_numeric_content_is_stored_as_a_string(): void
    {
        Event::fake([MessageSent::class]);

        $this->postMessage($this->member, ['content' => '42'])->assertCreated()
            ->assertJsonPath('message.content', '42');

        $this->assertSame('42', Message::sole()->content);
    }

    // --- MessageFeed ------------------------------------------------------

    public function test_the_feed_is_empty_without_a_channel_or_for_voice_channels(): void
    {
        $voice = Channel::factory()->create(['server_id' => $this->server->id, 'type' => Channel::TYPE_VOICE]);
        Message::factory()->inChannel($voice)->create();

        foreach ([MessageFeed::for(null), MessageFeed::for($voice), MessageFeed::empty()] as $feed) {
            $this->assertCount(0, $feed->messages);
            $this->assertFalse($feed->hasOlder);
        }
    }

    public function test_the_feed_returns_the_newest_messages_oldest_first(): void
    {
        $messages = Message::factory()->count(5)->inChannel($this->channel)->create();

        $feed = MessageFeed::for($this->channel, 3);

        $this->assertSame($messages->slice(2)->pluck('id')->values()->all(), $feed->messages->pluck('id')->all());
        $this->assertTrue($feed->hasOlder);
        $this->assertTrue($feed->messages->first()?->relationLoaded('author'));

        $all = MessageFeed::for($this->channel, 5);
        $this->assertCount(5, $all->messages);
        $this->assertFalse($all->hasOlder);
    }

    public function test_the_feed_only_contains_messages_of_its_own_channel(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id]);
        Message::factory()->inChannel($other)->create();
        $mine = Message::factory()->inChannel($this->channel)->create();

        $this->assertSame([$mine->id], MessageFeed::for($this->channel)->messages->pluck('id')->all());
    }
}
