<?php

namespace Tests\Feature\Messages;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\EditMessage;
use App\Actions\Servers\CreateServer;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Edge cases on top of EditDeleteMessageTest: the policy rules for
 * messages without a channel or author, content boundaries, no-op edits and
 * what an edit or delete must not disturb.
 */
class EditDeleteMessageRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $author;

    private User $member;

    private Server $server;

    private Channel $channel;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->author = User::factory()->create();
        $this->member = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Rules Server');
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

    // --- Policy --------------------------------------------------------------

    public function test_the_policy_denies_editing_and_deleting_direct_messages_for_now(): void
    {
        // DMs have no edit/delete routes yet; the policy must not let one through.
        $dm = Message::factory()->inConversation(Conversation::factory()->create())->create([
            'user_id' => $this->author->id,
        ]);

        $this->assertFalse($this->author->can('update', $dm));
        $this->assertFalse($this->author->can('delete', $dm));
        $this->assertFalse($this->owner->can('delete', $dm));
    }

    public function test_nobody_can_edit_a_message_of_a_deleted_account(): void
    {
        $orphan = Message::factory()->inChannel($this->channel)->create(['user_id' => null]);

        foreach ([$this->owner, $this->author, $this->member] as $user) {
            $this->assertFalse($user->can('update', $orphan));
        }
        $this->assertTrue($this->owner->can('delete', $orphan));
        $this->assertFalse($this->member->can('delete', $orphan));
    }

    public function test_the_policy_matches_the_issue_rules(): void
    {
        $this->assertTrue($this->author->can('update', $this->message));
        $this->assertTrue($this->author->can('delete', $this->message));
        $this->assertFalse($this->owner->can('update', $this->message));
        $this->assertTrue($this->owner->can('delete', $this->message));
        $this->assertFalse($this->member->can('update', $this->message));
        $this->assertFalse($this->member->can('delete', $this->message));
    }

    public function test_a_moderator_can_edit_their_own_message(): void
    {
        Event::fake([MessageUpdated::class]);
        $own = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => 'Mine']);

        $this->actingAs($this->owner)->patchJson($this->url($own), ['content' => 'Still mine'])
            ->assertOk()
            ->assertJsonPath('message.content', 'Still mine');

        Event::assertDispatched(MessageUpdated::class);
    }

    public function test_the_owner_of_another_server_is_not_a_moderator_here(): void
    {
        app(CreateServer::class)->create($this->member, 'Member Server');

        $this->actingAs($this->member)->deleteJson($this->url())->assertForbidden();
        $this->assertNotSoftDeleted($this->message);
    }

    // --- Content -------------------------------------------------------------

    public function test_content_of_exactly_the_maximum_length_is_accepted(): void
    {
        Event::fake([MessageUpdated::class]);
        $content = str_repeat('a', Message::MAX_CONTENT_LENGTH);

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => $content])->assertOk();

        $this->assertSame($content, $this->message->fresh()?->content);
    }

    public function test_too_long_content_explains_the_limit(): void
    {
        $this->actingAs($this->author)
            ->patchJson($this->url(), ['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)])
            ->assertJsonValidationErrors([
                'content' => 'Messages can be at most '.Message::MAX_CONTENT_LENGTH.' characters long.',
            ]);
    }

    public function test_resaving_the_same_content_with_extra_whitespace_is_not_an_edit(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => "  Original \n"])
            ->assertOk()
            ->assertJsonPath('message.edited_at', null);

        Event::assertNotDispatched(MessageUpdated::class);
    }

    public function test_resaving_after_an_edit_keeps_the_original_edited_at(): void
    {
        Event::fake([MessageUpdated::class]);
        $this->travelTo('2026-03-04 05:00:00');
        $this->actingAs($this->author)->patchJson($this->url(), ['content' => 'Changed'])->assertOk();
        $this->travelTo('2026-03-04 07:00:00');

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => 'Changed'])
            ->assertOk()
            ->assertJsonPath('message.edited_at', '2026-03-04T05:00:00+00:00');

        Event::assertDispatchedTimes(MessageUpdated::class, 1);
    }

    public function test_content_is_stored_as_typed_and_escaped_on_the_page(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => '<script>alert(1)</script>'])->assertOk();

        $this->assertSame('<script>alert(1)</script>', $this->message->fresh()?->content);
        $this->actingAs($this->member)->get("/servers/{$this->server->id}/channels/{$this->channel->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // --- What an edit / delete must not disturb ------------------------------

    public function test_an_edit_keeps_the_message_in_its_place_in_history(): void
    {
        Event::fake([MessageUpdated::class]);
        $this->travelTo('2026-03-04 05:00:00');
        $this->message->forceFill(['created_at' => now()])->save();
        $later = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->member->id,
            'created_at' => now()->addMinute(),
        ]);
        $this->travelTo('2026-03-04 09:00:00');

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => 'Edited later'])->assertOk();

        $this->assertSame(
            [$this->message->id, $later->id],
            MessageFeed::for($this->channel)->messages->pluck('id')->all(),
        );
        $this->assertSame('2026-03-04T05:00:00+00:00', $this->message->fresh()?->created_at?->toIso8601String());
    }

    public function test_editing_or_deleting_one_message_leaves_the_others_alone(): void
    {
        Event::fake([MessageUpdated::class, MessageDeleted::class]);
        $other = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->author->id, 'content' => 'Other']);

        $this->actingAs($this->author)->patchJson($this->url(), ['content' => 'Changed'])->assertOk();
        $this->actingAs($this->owner)->deleteJson($this->url())->assertNoContent();

        $other->refresh();
        $this->assertSame('Other', $other->content);
        $this->assertNull($other->edited_at);
        $this->assertNotSoftDeleted($other);
    }

    public function test_a_soft_deleted_message_keeps_its_edit_history(): void
    {
        Event::fake([MessageUpdated::class, MessageDeleted::class]);
        $this->actingAs($this->author)->patchJson($this->url(), ['content' => 'Edited then deleted'])->assertOk();

        $this->actingAs($this->author)->deleteJson($this->url())->assertNoContent();

        $trashed = Message::withTrashed()->findOrFail($this->message->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('Edited then deleted', $trashed->content);
        $this->assertNotNull($trashed->edited_at);
    }

    // --- Actions -------------------------------------------------------------

    public function test_deleting_a_direct_message_broadcasts_on_the_conversation(): void
    {
        Event::fake([MessageDeleted::class]);
        $conversation = Conversation::factory()->create();
        $dm = Message::factory()->inConversation($conversation)->create();

        app(DeleteMessage::class)->delete($dm);

        $this->assertSoftDeleted($dm);
        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event): bool => $event->broadcastOn()->name === "private-conversation.{$conversation->id}"
            && $event->broadcastWith() === ['id' => $dm->id, 'channel_id' => null, 'conversation_id' => $conversation->id]);
    }

    public function test_the_edit_action_returns_the_saved_message(): void
    {
        Event::fake([MessageUpdated::class]);

        $edited = app(EditMessage::class)->edit($this->message, 'Via action');

        $this->assertTrue($edited->is($this->message));
        $this->assertFalse($edited->isDirty());
        $this->assertTrue($edited->isEdited());
        $this->assertSame('Via action', $this->message->fresh()?->content);
    }
}
