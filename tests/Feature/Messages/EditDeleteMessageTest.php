<?php

namespace Tests\Feature\Messages;

use App\Actions\Account\DeleteUser;
use App\Actions\Servers\CreateServer;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RunsTheQueueWorker;
use Tests\TestCase;

/**
 * Authors edit ("(edited)") and delete their own messages,
 * moderators (ServerPolicy::manageMessages: Manage Messages) delete any.
 * Deleting is a soft delete. MessageUpdated / MessageDeleted broadcast on
 * the private "channel.{id}" channel.
 */
class EditDeleteMessageTest extends TestCase
{
    use RefreshDatabase, RunsTheQueueWorker;

    private User $owner;

    private User $author;

    private User $member;

    private Server $server;

    private Channel $channel;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->author = User::factory()->create(['name' => 'Author']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        foreach ([$this->author, $this->member] as $user) {
            ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id]);
        }

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;

        $this->message = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->author->id,
            'content' => 'Original',
        ]);
    }

    private function url(?Message $message = null): string
    {
        $message ??= $this->message;

        return "/servers/{$this->server->id}/channels/{$this->channel->id}/messages/{$message->id}";
    }

    private function channelUrl(): string
    {
        return "/servers/{$this->server->id}/channels/{$this->channel->id}";
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function edit(User $as, array $data, ?Message $message = null, array $headers = []): TestResponse
    {
        return $this->actingAs($as)->patchJson($this->url($message), $data, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function remove(User $as, ?Message $message = null, array $headers = []): TestResponse
    {
        return $this->actingAs($as)->deleteJson($this->url($message), [], $headers);
    }

    /**
     * Records what reaches the broadcaster, like the real Reverb driver would get it.
     *
     * @return BaseBroadcaster&object{sent: list<array{list<string>, string, array<string, mixed>}>}
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

    private function channelPage(User $as): string
    {
        return (string) $this->actingAs($as)->get($this->channelUrl())->assertOk()->getContent();
    }

    // --- Editing ---------------------------------------------------------

    public function test_the_author_can_edit_their_message(): void
    {
        Event::fake([MessageUpdated::class]);
        $this->travelTo('2026-03-04 05:06:07');

        $response = $this->edit($this->author, ['content' => '  Fixed typo  '])->assertOk();

        $message = $this->message->fresh();
        $this->assertNotNull($message);
        $this->assertSame('Fixed typo', $message->content);
        $this->assertTrue($message->isEdited());

        $response->assertExactJson(['message' => $message->toPayload()])
            ->assertJsonPath('message.edited_at', '2026-03-04T05:06:07+00:00')
            ->assertJsonPath('message.content', 'Fixed typo');
    }

    public function test_editing_broadcasts_message_updated_to_others(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->edit($this->author, ['content' => 'New'], headers: ['X-Socket-ID' => '1234.5678'])->assertOk();

        Event::assertDispatchedTimes(MessageUpdated::class, 1);
        Event::assertDispatched(MessageUpdated::class, function (MessageUpdated $event): bool {
            return $event->message->is($this->message)
                && $event->broadcastOn()->name === "private-channel.{$this->channel->id}"
                && $event->broadcastAs() === 'message.updated'
                && $event->broadcastWith() === $this->message->fresh()?->toPayload()
                // toOthers(): the author's own socket is skipped.
                && $event->socket === '1234.5678';
        });
    }

    public function test_saving_the_same_content_does_not_mark_it_edited(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->edit($this->author, ['content' => 'Original'])->assertOk()
            ->assertJsonPath('message.edited_at', null);

        $this->assertFalse($this->message->fresh()?->isEdited());
        Event::assertNotDispatched(MessageUpdated::class);
    }

    public function test_a_second_edit_moves_edited_at_forward(): void
    {
        Event::fake([MessageUpdated::class]);
        $this->travelTo('2026-03-04 05:00:00');
        $this->edit($this->author, ['content' => 'One'])->assertOk();
        $this->travelTo('2026-03-04 06:00:00');

        $this->edit($this->author, ['content' => 'Two'])->assertOk()
            ->assertJsonPath('message.edited_at', '2026-03-04T06:00:00+00:00');
    }

    public function test_the_update_broadcast_is_queued_and_sent_by_the_worker(): void
    {
        // ISSUE-42: queued on the database queue, sent by the worker.
        config(['queue.default' => 'database']);
        $this->assertContains(ShouldBroadcast::class, class_implements(MessageUpdated::class));
        $spy = $this->spyBroadcaster();

        $this->edit($this->author, ['content' => 'Live edit'])->assertOk();

        $this->assertCount(0, $spy->sent);
        $this->runQueueWorker();

        $this->assertCount(1, $spy->sent);
        [$channels, $name, $payload] = $spy->sent[0];
        $this->assertSame(["private-channel.{$this->channel->id}"], $channels);
        $this->assertSame('message.updated', $name);
        $this->assertSame('Live edit', $payload['content']);
        $this->assertIsString($payload['edited_at']);
    }

    public function test_a_failed_update_broadcast_still_saves_the_edit(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);

        $this->edit($this->author, ['content' => 'Saved anyway'])->assertOk();

        $this->assertSame('Saved anyway', $this->message->fresh()?->content);
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_a_form_edit_without_javascript_redirects_back_to_the_channel(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->actingAs($this->author)->patch($this->url(), ['content' => 'Form edit'])
            ->assertRedirect($this->channelUrl());

        $this->assertSame('Form edit', $this->message->fresh()?->content);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidContent(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['content' => '']],
            'whitespace only' => [['content' => "  \n "]],
            'too long' => [['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)]],
            'not a string' => [['content' => ['a']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidContent')]
    public function test_invalid_content_is_rejected(array $data): void
    {
        Event::fake([MessageUpdated::class]);

        $this->edit($this->author, $data)->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->assertSame('Original', $this->message->fresh()?->content);
        Event::assertNotDispatched(MessageUpdated::class);
    }

    public function test_an_empty_edit_suggests_deleting(): void
    {
        $this->edit($this->author, ['content' => ''])
            ->assertJsonValidationErrors(['content' => 'A message cannot be empty. Delete it instead.']);
    }

    public function test_only_the_author_can_edit(): void
    {
        Event::fake([MessageUpdated::class]);

        // Moderators can delete, but not put words in someone's mouth.
        $this->edit($this->owner, ['content' => 'Hijacked'])->assertForbidden();
        $this->edit($this->member, ['content' => 'Hijacked'])->assertForbidden();
        $this->edit(User::factory()->create(), ['content' => 'Hijacked'])->assertForbidden();

        $this->assertSame('Original', $this->message->fresh()?->content);
        Event::assertNotDispatched(MessageUpdated::class);
    }

    public function test_an_author_who_left_the_server_can_no_longer_edit_or_delete(): void
    {
        ServerMember::where('server_id', $this->server->id)->where('user_id', $this->author->id)->delete();

        $this->edit($this->author, ['content' => 'Too late'])->assertForbidden();
        $this->remove($this->author)->assertForbidden();
        $this->assertNotSoftDeleted($this->message);
    }

    public function test_authorisation_is_checked_before_validation(): void
    {
        $this->edit($this->member, ['content' => ''])->assertForbidden();
    }

    public function test_the_author_cannot_move_or_reassign_the_message(): void
    {
        Event::fake([MessageUpdated::class]);
        $other = Channel::factory()->create(['server_id' => $this->server->id]);

        $this->edit($this->author, [
            'content' => 'Moved?',
            'channel_id' => $other->id,
            'user_id' => $this->member->id,
            'edited_at' => null,
        ])->assertOk();

        $message = $this->message->refresh();
        $this->assertSame($this->channel->id, $message->channel_id);
        $this->assertSame($this->author->id, $message->user_id);
        $this->assertNotNull($message->edited_at);
    }

    // --- Deleting ----------------------------------------------------------

    public function test_the_author_can_delete_their_message(): void
    {
        Event::fake([MessageDeleted::class]);

        $this->remove($this->author, headers: ['X-Socket-ID' => '1234.5678'])->assertNoContent();

        // Soft delete: hidden from queries, the row stays.
        $this->assertSoftDeleted($this->message);
        $this->assertNull(Message::find($this->message->id));
        $this->assertSame('Original', Message::withTrashed()->find($this->message->id)?->content);

        Event::assertDispatchedTimes(MessageDeleted::class, 1);
        Event::assertDispatched(MessageDeleted::class, function (MessageDeleted $event): bool {
            return $event->broadcastOn()->name === "private-channel.{$this->channel->id}"
                && $event->broadcastAs() === 'message.deleted'
                && $event->broadcastWith() === ['id' => $this->message->id, 'channel_id' => $this->channel->id, 'conversation_id' => null]
                && $event->socket === '1234.5678';
        });
    }

    public function test_a_moderator_can_delete_any_message(): void
    {
        Event::fake([MessageDeleted::class]);

        $this->remove($this->owner)->assertNoContent();

        $this->assertSoftDeleted($this->message);
        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_a_moderator_can_delete_messages_of_deleted_accounts(): void
    {
        Event::fake([MessageDeleted::class]);
        $orphan = Message::factory()->inChannel($this->channel)->create(['user_id' => null]);

        $this->remove($this->owner, $orphan)->assertNoContent();

        $this->assertSoftDeleted($orphan);
    }

    public function test_other_members_and_outsiders_cannot_delete(): void
    {
        Event::fake([MessageDeleted::class]);

        $this->remove($this->member)->assertForbidden();
        $this->remove(User::factory()->create())->assertForbidden();

        $this->assertNotSoftDeleted($this->message);
        Event::assertNotDispatched(MessageDeleted::class);
    }

    public function test_the_delete_broadcast_carries_no_content(): void
    {
        config(['queue.default' => 'database']);
        $this->assertContains(ShouldBroadcast::class, class_implements(MessageDeleted::class));
        $spy = $this->spyBroadcaster();

        $this->remove($this->author)->assertNoContent();
        $this->runQueueWorker();

        $this->assertCount(1, $spy->sent);
        [$channels, $name, $payload] = $spy->sent[0];
        $this->assertSame(["private-channel.{$this->channel->id}"], $channels);
        $this->assertSame('message.deleted', $name);
        $this->assertSame($this->message->id, $payload['id']);
        $this->assertSame($this->channel->id, $payload['channel_id']);
        $this->assertArrayNotHasKey('content', $payload);
    }

    public function test_a_failed_delete_broadcast_still_deletes_the_message(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);

        $this->remove($this->author)->assertNoContent();

        $this->assertSoftDeleted($this->message);
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_a_form_delete_without_javascript_redirects_back_to_the_channel(): void
    {
        Event::fake([MessageDeleted::class]);

        $this->actingAs($this->author)->delete($this->url())->assertRedirect($this->channelUrl());

        $this->assertSoftDeleted($this->message);
    }

    public function test_a_deleted_message_cannot_be_edited_or_deleted_again(): void
    {
        Event::fake([MessageDeleted::class, MessageUpdated::class]);
        $this->message->delete();

        $this->edit($this->author, ['content' => 'Back from the dead'])->assertNotFound();
        $this->remove($this->author)->assertNotFound();
        $this->remove($this->owner)->assertNotFound();

        Event::assertNotDispatched(MessageDeleted::class);
        Event::assertNotDispatched(MessageUpdated::class);
    }

    public function test_a_message_of_another_channel_is_not_found(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id]);
        $url = "/servers/{$this->server->id}/channels/{$other->id}/messages/{$this->message->id}";

        $this->actingAs($this->author)->patchJson($url, ['content' => 'Wrong channel'])->assertNotFound();
        $this->actingAs($this->author)->deleteJson($url)->assertNotFound();
        $this->assertNotSoftDeleted($this->message);
    }

    public function test_the_owner_of_another_server_cannot_delete_through_their_own_server(): void
    {
        $otherServer = app(CreateServer::class)->create($this->member, 'Other Server');
        /** @var Channel $otherChannel */
        $otherChannel = $otherServer->channels()->firstOrFail();
        $url = "/servers/{$otherServer->id}/channels/{$otherChannel->id}/messages/{$this->message->id}";

        $this->actingAs($this->member)->deleteJson($url)->assertNotFound();
        $this->assertNotSoftDeleted($this->message);
    }

    public function test_guests_and_unverified_users_cannot_edit_or_delete(): void
    {
        $this->patchJson($this->url(), ['content' => 'x'])->assertUnauthorized();
        $this->deleteJson($this->url())->assertUnauthorized();

        $this->author->forceFill(['email_verified_at' => null])->save();
        $this->edit($this->author, ['content' => 'x'])->assertForbidden();
        $this->remove($this->author)->assertForbidden();
        $this->assertNotSoftDeleted($this->message);
    }

    // --- Soft-deleted messages stay hidden -----------------------------------

    public function test_deleted_messages_are_left_out_of_the_history_and_the_page(): void
    {
        $kept = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id, 'content' => 'Still here']);
        $this->message->delete();

        $this->assertSame([$kept->id], MessageFeed::for($this->channel)->messages->pluck('id')->all());

        $this->actingAs($this->member)
            ->getJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $kept->id);

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertOk()
            ->assertSee('Still here')
            ->assertDontSee('Original');
    }

    public function test_replies_keep_pointing_at_a_deleted_message(): void
    {
        $reply = Message::factory()->replyTo($this->message)->create(['user_id' => $this->member->id]);

        $this->message->delete();

        $reply->refresh();
        $this->assertSame($this->message->id, $reply->reply_to_id);
        $this->assertNull($reply->replyTo);
    }

    public function test_deleting_an_account_also_anonymises_its_deleted_messages(): void
    {
        $this->message->delete();
        $this->actingAs($this->author);

        app(DeleteUser::class)->delete($this->author, ['password' => 'password']);

        $this->assertNull(Message::withTrashed()->find($this->message->id)?->user_id);
    }

    // --- Channel page markup ---------------------------------------------------

    public function test_the_page_marks_edited_messages(): void
    {
        $this->message->forceFill(['edited_at' => '2026-03-04 05:06:07'])->save();
        $plain = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);

        $html = $this->channelPage($this->member);

        $this->assertMatchesRegularExpression(
            '/data-message-id="'.$this->message->id.'".*?data-message-edited\s+title="Edited 2026-03-04T05:06:07\+00:00"\s*>\(edited\)</s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-message-id="'.$plain->id.'".*?data-message-edited\s+hidden\s*>\(edited\)</s',
            $html,
        );
    }

    public function test_members_only_see_actions_on_their_own_messages(): void
    {
        $own = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);

        $html = $this->channelPage($this->member);

        $this->assertStringNotContainsString('data-can-manage-messages', $html);
        $this->assertStringContainsString('data-viewer-id="'.$this->member->id.'"', $html);
        $this->assertSame(1, preg_match_all('/data-message-edit\s/', $html));
        $this->assertSame(1, substr_count($html, 'data-message-delete-form'));
        $this->assertStringContainsString('action="'.url($this->url($own)).'"', $html);
    }

    public function test_moderators_see_delete_on_every_message_but_edit_only_on_their_own(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);

        $html = $this->channelPage($this->owner);

        $this->assertStringContainsString('data-can-manage-messages', $html);
        $this->assertSame(2, substr_count($html, 'data-message-delete-form'));
        $this->assertSame(1, preg_match_all('/data-message-edit\s/', $html));
    }
}
