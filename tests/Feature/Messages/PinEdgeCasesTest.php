<?php

namespace Tests\Feature\Messages;

use App\Actions\Messages\PinMessage;
use App\Actions\Servers\CreateServer;
use App\Events\MessagePinned;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * ISSUE-20 edge cases around pinning that PinTest does not cover: stale
 * models (two moderators at once), the pin limit when nothing changes, a
 * broadcaster that is down, toOthers(), scoped bindings across servers,
 * what the pinned list carries, and how pins survive edits.
 */
class PinEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private Server $server;

    private Channel $channel;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->alice->id]);

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;

        $this->message = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->alice->id,
            'content' => 'Remember this',
        ]);
    }

    private function pinUrl(?Message $message = null, ?Server $server = null, ?Channel $channel = null): string
    {
        $message ??= $this->message;
        $server ??= $this->server;
        $channel ??= $this->channel;

        return "/servers/{$server->id}/channels/{$channel->id}/messages/{$message->id}/pin";
    }

    private function pinsUrl(): string
    {
        return "/servers/{$this->server->id}/channels/{$this->channel->id}/pins";
    }

    private function markPinned(): void
    {
        $this->message->forceFill(['pinned_at' => now(), 'pinned_by_id' => $this->owner->id])->save();
    }

    // --- Concurrency: a model loaded before someone else changed it ------

    public function test_a_stale_copy_cannot_pin_a_message_a_second_time(): void
    {
        Event::fake([MessagePinned::class]);
        $first = Message::query()->findOrFail($this->message->id);
        $stale = Message::query()->findOrFail($this->message->id);
        $other = User::factory()->create();

        $this->assertTrue(app(PinMessage::class)->pin($this->owner, $first));
        $this->assertFalse(app(PinMessage::class)->pin($other, $stale));

        // The first pin wins, and the stale copy now shows it.
        $this->assertSame($this->owner->id, $this->message->fresh()?->pinned_by_id);
        $this->assertTrue($stale->isPinned());
        $this->assertSame($this->owner->id, $stale->pinned_by_id);
        Event::assertDispatchedTimes(MessagePinned::class, 1);
    }

    public function test_a_stale_copy_unpinning_an_unpinned_message_broadcasts_nothing(): void
    {
        Event::fake([MessagePinned::class]);
        $this->markPinned();
        $first = Message::query()->findOrFail($this->message->id);
        $stale = Message::query()->findOrFail($this->message->id);

        $this->assertTrue(app(PinMessage::class)->unpin($first));
        $this->assertFalse(app(PinMessage::class)->unpin($stale));

        $this->assertFalse($stale->isPinned());
        Event::assertDispatchedTimes(MessagePinned::class, 1);
    }

    // --- The limit ------------------------------------------------------

    public function test_at_the_limit_a_pinned_message_reports_unchanged_and_can_still_be_unpinned(): void
    {
        Event::fake([MessagePinned::class]);
        Message::factory()->inChannel($this->channel)->count(Message::MAX_PINS_PER_CHANNEL - 1)->create(['pinned_at' => now()->subHour()]);
        $this->markPinned();

        // Pinning it again is not an error, even though the channel is full.
        $this->actingAs($this->owner)->postJson($this->pinUrl())
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->actingAs($this->owner)->deleteJson($this->pinUrl())->assertOk()->assertJsonPath('changed', true);
        $this->actingAs($this->owner)->postJson($this->pinUrl())->assertOk()->assertJsonPath('changed', true);

        $this->assertSame(
            Message::MAX_PINS_PER_CHANNEL,
            Message::query()->where('channel_id', $this->channel->id)->whereNotNull('pinned_at')->count(),
        );
    }

    public function test_a_rejected_pin_broadcasts_nothing_and_redirects_back_with_the_error_without_javascript(): void
    {
        Event::fake([MessagePinned::class]);
        Message::factory()->inChannel($this->channel)->count(Message::MAX_PINS_PER_CHANNEL)->create(['pinned_at' => now()]);
        $channelUrl = "/servers/{$this->server->id}/channels/{$this->channel->id}";

        $this->actingAs($this->owner)->from($channelUrl)->post($this->pinUrl())
            ->assertRedirect($channelUrl)
            ->assertSessionHasErrors('message');

        $this->assertFalse($this->message->fresh()?->isPinned());
        Event::assertNotDispatched(MessagePinned::class);
    }

    // --- Broadcasting -----------------------------------------------------

    public function test_the_pin_is_saved_when_the_broadcaster_is_down(): void
    {
        Exceptions::fake();
        $this->throwingBroadcaster();

        $this->actingAs($this->owner)->postJson($this->pinUrl())
            ->assertOk()
            ->assertJsonPath('changed', true);
        $this->assertTrue($this->message->fresh()?->isPinned());

        $this->actingAs($this->owner)->deleteJson($this->pinUrl())
            ->assertOk()
            ->assertJsonPath('changed', true);
        $this->assertFalse($this->message->fresh()?->isPinned());

        // Reported, not swallowed silently.
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_pin_broadcast_skips_the_moderators_own_socket(): void
    {
        Event::fake([MessagePinned::class]);

        $this->actingAs($this->owner)->postJson($this->pinUrl(), [], ['X-Socket-ID' => '1234.5678'])->assertOk();

        Event::assertDispatched(MessagePinned::class, fn (MessagePinned $event): bool => $event->socket === '1234.5678');
    }

    public function test_the_pin_broadcast_goes_only_to_the_messages_channel(): void
    {
        Event::fake([MessagePinned::class]);
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        $elsewhere = Message::factory()->inChannel($other)->create();

        $this->actingAs($this->owner)->postJson($this->pinUrl($elsewhere, channel: $other))->assertOk();

        Event::assertDispatched(MessagePinned::class, fn (MessagePinned $event): bool => $event->broadcastOn()->name === 'private-channel.'.$other->id
            && $event->broadcastWith()['channel_id'] === $other->id);
        Event::assertDispatchedTimes(MessagePinned::class, 1);
    }

    public function test_a_pin_whose_pinner_was_deleted_broadcasts_no_pinner(): void
    {
        $gone = User::factory()->create();
        $this->message->forceFill(['pinned_at' => now(), 'pinned_by_id' => $gone->id])->save();
        $gone->delete();

        $payload = (new MessagePinned($this->message->fresh() ?? $this->message))->broadcastWith();

        $this->assertNotNull($payload['pinned_at']);
        $this->assertNull($payload['pinned_by']);
    }

    // --- Scoped bindings --------------------------------------------------

    public function test_urls_mixing_servers_are_404_even_for_a_moderator_of_both(): void
    {
        $otherServer = app(CreateServer::class)->create($this->owner, 'Other Server');
        /** @var Channel $otherChannel */
        $otherChannel = $otherServer->channels()->firstOrFail();

        // This server's channel and message under another server's id.
        $this->actingAs($this->owner)->postJson($this->pinUrl(server: $otherServer))->assertNotFound();
        // Another server's channel under this server's id.
        $this->actingAs($this->owner)->postJson($this->pinUrl(channel: $otherChannel))->assertNotFound();
        $this->actingAs($this->owner)->getJson("/servers/{$this->server->id}/channels/{$otherChannel->id}/pins")->assertNotFound();

        $this->assertFalse($this->message->fresh()?->isPinned());
    }

    public function test_a_deleted_pinned_message_cannot_be_unpinned_and_leaves_the_list(): void
    {
        Event::fake([MessagePinned::class]);
        $this->markPinned();
        $this->message->delete();

        $this->actingAs($this->owner)->deleteJson($this->pinUrl())->assertNotFound();
        $this->actingAs($this->owner)->getJson($this->pinsUrl())->assertExactJson(['pins' => []]);
        Event::assertNotDispatched(MessagePinned::class);
    }

    // --- The pinned list --------------------------------------------------

    public function test_pins_with_the_same_time_list_the_newest_message_first(): void
    {
        $this->freezeSecond();
        $first = Message::factory()->inChannel($this->channel)->create(['pinned_at' => now()]);
        $second = Message::factory()->inChannel($this->channel)->create(['pinned_at' => now()]);

        $ids = array_column($this->actingAs($this->alice)->getJson($this->pinsUrl())->json('pins'), 'id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_the_pinned_list_carries_quotes_reactions_and_attachments(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'reply_to_id' => $this->message->id,
            'content' => 'Pinned reply',
            'pinned_at' => now(),
        ]);
        MessageAttachment::factory()->create(['message_id' => $reply->id]);
        Reaction::factory()->create(['message_id' => $reply->id, 'user_id' => $this->alice->id, 'emoji' => '👍']);
        $this->message->delete();

        $this->actingAs($this->alice)->getJson($this->pinsUrl())
            ->assertOk()
            ->assertJsonPath('pins.0.id', $reply->id)
            ->assertJsonPath('pins.0.reply_to.deleted', true)
            ->assertJsonPath('pins.0.reply_to.id', $this->message->id)
            ->assertJsonCount(1, 'pins.0.attachments')
            ->assertJsonPath('pins.0.reactions.0.emoji', '👍')
            ->assertJsonPath('pins.0.reactions.0.me', true);

        // "me" is per viewer.
        $this->actingAs($this->owner)->getJson($this->pinsUrl())->assertJsonPath('pins.0.reactions.0.me', false);
    }

    public function test_the_pinned_list_loads_without_a_query_per_pin(): void
    {
        $queries = function (int $pins): int {
            Message::query()->whereNotNull('pinned_at')->forceDelete();
            foreach (range(1, $pins) as $i) {
                $message = Message::factory()->inChannel($this->channel)->create([
                    'reply_to_id' => $this->message->id,
                    'pinned_at' => now(),
                    'pinned_by_id' => $this->owner->id,
                ]);
                MessageAttachment::factory()->create(['message_id' => $message->id]);
                Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $this->alice->id]);
            }

            $this->coldServerCache();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->alice)->getJson($this->pinsUrl())->assertOk()->assertJsonCount($pins, 'pins');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($queries(1), $queries(8));
    }

    // --- Pins and other changes ---------------------------------------

    public function test_editing_a_pinned_message_keeps_the_pin(): void
    {
        Event::fake([MessageUpdated::class, MessagePinned::class]);
        $this->freezeSecond();
        $this->markPinned();

        $this->actingAs($this->alice)
            ->patchJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages/{$this->message->id}", ['content' => 'Remember this, edited'])
            ->assertOk()
            ->assertJsonPath('message.pinned_at', now()->toIso8601String());

        $this->assertTrue($this->message->fresh()?->isPinned());
        $this->actingAs($this->alice)->getJson($this->pinsUrl())->assertJsonPath('pins.0.content', 'Remember this, edited');
        Event::assertNotDispatched(MessagePinned::class);
    }

    public function test_pinning_a_reply_returns_its_quote(): void
    {
        Event::fake([MessagePinned::class]);
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->message->id]);

        $this->actingAs($this->owner)->postJson($this->pinUrl($reply))
            ->assertOk()
            ->assertJsonPath('message.reply_to.id', $this->message->id)
            ->assertJsonPath('message.reply_to.content', 'Remember this');
    }

    public function test_a_moderator_can_pin_a_message_whose_author_is_gone(): void
    {
        Event::fake([MessagePinned::class]);
        $this->message->update(['user_id' => null]);

        $this->actingAs($this->owner)->postJson($this->pinUrl())
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message.author', null);
    }

    private function throwingBroadcaster(): void
    {
        $broadcaster = new class extends BaseBroadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Reverb is down');
            }
        };

        Broadcast::extend('down', fn () => $broadcaster);
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    }
}
