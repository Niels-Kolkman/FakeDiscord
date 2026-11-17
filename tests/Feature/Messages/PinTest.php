<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessagePinned;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-20: moderators (ServerPolicy::manageMessages, the owner for now)
 * pin and unpin messages; every member sees a channel's pinned list,
 * newest pin first. MessagePinned broadcasts the change on "channel.{id}".
 */
class PinTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $stranger;

    private Server $server;

    private Channel $channel;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->stranger = User::factory()->create();
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

    private function pinUrl(?Message $message = null, ?Channel $channel = null): string
    {
        $message ??= $this->message;
        $channel ??= $this->channel;

        return "/servers/{$this->server->id}/channels/{$channel->id}/messages/{$message->id}/pin";
    }

    private function pinsUrl(?Channel $channel = null): string
    {
        $channel ??= $this->channel;

        return "/servers/{$this->server->id}/channels/{$channel->id}/pins";
    }

    /**
     * @return TestResponse<Response>
     */
    private function pin(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson($this->pinUrl());
    }

    private function markPinned(?User $by = null): void
    {
        $this->message->forceFill(['pinned_at' => now(), 'pinned_by_id' => $by?->id])->save();
    }

    public function test_a_moderator_can_pin_a_message(): void
    {
        Event::fake([MessagePinned::class]);
        $this->freezeSecond();

        $this->pin()
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message.id', $this->message->id)
            ->assertJsonPath('message.pinned_at', now()->toIso8601String())
            ->assertJsonPath('message.pinned_by', ['id' => $this->owner->id, 'name' => 'Owner']);

        $this->message->refresh();
        $this->assertTrue($this->message->isPinned());
        $this->assertSame($this->owner->id, $this->message->pinned_by_id);

        Event::assertDispatched(MessagePinned::class, function (MessagePinned $event): bool {
            return $event->broadcastOn()->name === 'private-channel.'.$this->channel->id
                && $event->broadcastAs() === 'message.pinned'
                && $event->broadcastWith() === [
                    'id' => $this->message->id,
                    'channel_id' => $this->channel->id,
                    'pinned_at' => now()->toIso8601String(),
                    'pinned_by' => ['id' => $this->owner->id, 'name' => 'Owner'],
                ];
        });
    }

    public function test_pinning_twice_changes_nothing(): void
    {
        Event::fake([MessagePinned::class]);
        $this->pin()->assertOk();
        $pinnedAt = $this->message->fresh()?->pinned_at;

        $this->travel(5)->minutes();
        $this->pin()->assertOk()->assertJsonPath('changed', false);

        $this->assertEquals($pinnedAt, $this->message->fresh()?->pinned_at);
        Event::assertDispatchedTimes(MessagePinned::class, 1);
    }

    public function test_a_moderator_can_unpin_a_message(): void
    {
        Event::fake([MessagePinned::class]);
        $this->markPinned($this->owner);

        $this->actingAs($this->owner)->deleteJson($this->pinUrl())
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message.pinned_at', null)
            ->assertJsonPath('message.pinned_by', null);

        $this->message->refresh();
        $this->assertFalse($this->message->isPinned());
        $this->assertNull($this->message->pinned_by_id);
        Event::assertDispatched(MessagePinned::class, fn (MessagePinned $event): bool => $event->broadcastWith()['pinned_at'] === null);

        $this->actingAs($this->owner)->deleteJson($this->pinUrl())->assertOk()->assertJsonPath('changed', false);
        Event::assertDispatchedTimes(MessagePinned::class, 1);
    }

    public function test_members_without_manage_messages_cannot_pin_or_unpin(): void
    {
        Event::fake([MessagePinned::class]);

        // Not even their own message.
        $this->pin($this->alice)->assertForbidden();
        $this->markPinned();
        $this->actingAs($this->alice)->deleteJson($this->pinUrl())->assertForbidden();

        $this->assertTrue($this->message->fresh()?->isPinned());
        Event::assertNotDispatched(MessagePinned::class);
    }

    public function test_non_members_and_guests_cannot_pin(): void
    {
        $this->postJson($this->pinUrl())->assertUnauthorized();
        $this->pin($this->stranger)->assertForbidden();

        $this->assertFalse($this->message->fresh()?->isPinned());
    }

    public function test_messages_of_other_channels_and_deleted_messages_are_404(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        $this->actingAs($this->owner)->postJson($this->pinUrl(channel: $other))->assertNotFound();

        $this->message->delete();
        $this->pin()->assertNotFound();
    }

    public function test_a_channel_holds_at_most_fifty_pins(): void
    {
        Event::fake([MessagePinned::class]);
        Message::factory()->inChannel($this->channel)->count(Message::MAX_PINS_PER_CHANNEL)->create(['pinned_at' => now()]);
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        $otherPin = Message::factory()->inChannel($other)->create();

        $this->pin()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'A channel can have at most 50 pinned messages. Unpin one first.']);
        $this->assertFalse($this->message->fresh()?->isPinned());

        // The limit is per channel.
        $this->actingAs($this->owner)->postJson($this->pinUrl($otherPin, $other))->assertOk()->assertJsonPath('changed', true);

        // Deleted messages do not count.
        Message::query()->where('channel_id', $this->channel->id)->whereNotNull('pinned_at')->firstOrFail()->delete();
        $this->pin()->assertOk()->assertJsonPath('changed', true);
    }

    public function test_pinning_without_javascript_redirects_back(): void
    {
        Event::fake([MessagePinned::class]);
        $channelUrl = "/servers/{$this->server->id}/channels/{$this->channel->id}";

        $this->actingAs($this->owner)->from($channelUrl)->post($this->pinUrl())->assertRedirect($channelUrl);
        $this->assertTrue($this->message->fresh()?->isPinned());

        $this->actingAs($this->owner)->from($this->pinsUrl())->delete($this->pinUrl())->assertRedirect($this->pinsUrl());
        $this->assertFalse($this->message->fresh()?->isPinned());
    }

    public function test_members_see_the_pinned_list_newest_pin_first(): void
    {
        $older = Message::factory()->inChannel($this->channel)->create(['content' => 'Older pin', 'pinned_at' => now()->subDay()]);
        $newer = Message::factory()->inChannel($this->channel)->create(['content' => 'Newer pin', 'pinned_at' => now(), 'pinned_by_id' => $this->owner->id]);
        Message::factory()->inChannel($this->channel)->create(['pinned_at' => now()])->delete();
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        Message::factory()->inChannel($other)->create(['pinned_at' => now()]);

        $response = $this->actingAs($this->alice)->getJson($this->pinsUrl())->assertOk();

        $this->assertSame([$newer->id, $older->id], array_column($response->json('pins'), 'id'));
        $response->assertJsonPath('pins.0.pinned_by', ['id' => $this->owner->id, 'name' => 'Owner'])
            ->assertJsonPath('pins.0.content', 'Newer pin')
            ->assertJsonPath('pins.1.pinned_by', null);
    }

    public function test_the_pinned_list_is_empty_without_pins(): void
    {
        $this->actingAs($this->alice)->getJson($this->pinsUrl())->assertExactJson(['pins' => []]);
    }

    public function test_the_pinned_list_page_works_without_javascript(): void
    {
        $this->markPinned($this->owner);

        $this->actingAs($this->alice)->get($this->pinsUrl())
            ->assertOk()
            ->assertSee('Pinned messages in #'.$this->channel->name)
            ->assertSee('Remember this')
            ->assertSee('by Owner')
            ->assertSee('#message-'.$this->message->id, false)
            ->assertDontSee('Unpin');

        $this->actingAs($this->owner)->get($this->pinsUrl())->assertSee('Unpin');

        $this->message->forceFill(['pinned_at' => null])->save();
        $this->actingAs($this->alice)->get($this->pinsUrl())->assertSee('This channel has no pinned messages yet.');
    }

    public function test_only_members_see_the_pinned_list(): void
    {
        $this->getJson($this->pinsUrl())->assertUnauthorized();
        $this->actingAs($this->stranger)->getJson($this->pinsUrl())->assertForbidden();

        $voice = Channel::factory()->voice()->create(['server_id' => $this->server->id]);
        $this->actingAs($this->alice)->getJson($this->pinsUrl($voice))->assertNotFound();
    }

    public function test_the_channel_page_shows_the_pin_button_to_moderators_only(): void
    {
        $this->markPinned();
        $channelUrl = "/servers/{$this->server->id}/channels/{$this->channel->id}";

        $this->actingAs($this->owner)->get($channelUrl)
            ->assertOk()
            ->assertSee('data-pins-toggle', false)
            ->assertSee('data-pins-panel', false)
            ->assertSee('data-message-pin-form', false)
            ->assertSee('Unpin');

        $this->actingAs($this->alice)->get($channelUrl)
            ->assertOk()
            ->assertSee('data-pins-toggle', false)
            ->assertDontSee('data-message-pin-form', false);
    }

    public function test_the_message_payload_says_whether_it_is_pinned(): void
    {
        $this->freezeSecond();
        $this->assertNull($this->message->toPayload()['pinned_at']);

        $this->markPinned();

        $this->assertSame(now()->toIso8601String(), $this->message->fresh()?->toPayload()['pinned_at']);
    }

    public function test_deleting_the_pinner_keeps_the_pin(): void
    {
        $moderator = User::factory()->create();
        $this->markPinned($moderator);

        $moderator->delete();

        $this->message->refresh();
        $this->assertTrue($this->message->isPinned());
        $this->assertNull($this->message->pinned_by_id);
    }
}
