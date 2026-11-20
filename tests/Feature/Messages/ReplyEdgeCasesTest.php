<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Edge cases around replies that ReplyTest does not cover: odd
 * reply_to_id values, reply chains, edits on either side, a hard-deleted
 * original, escaping, originals outside the loaded page, and the exact
 * boundaries of the quoted excerpt.
 */
class ReplyEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private Server $server;

    private Channel $channel;

    private Message $original;

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

        $this->original = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => 'What should we build next?',
        ]);
    }

    private function messagesUrl(): string
    {
        return "/servers/{$this->server->id}/channels/{$this->channel->id}/messages";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function reply(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->alice)->postJson($this->messagesUrl(), $data);
    }

    // --- reply_to_id values ---------------------------------------------

    public function test_odd_reply_to_ids_are_rejected_without_a_server_error(): void
    {
        foreach ([0, -1, [1], ['id' => $this->original->id], '1e3', 1.5] as $value) {
            $this->reply(['content' => 'Odd', 'reply_to_id' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reply_to_id');
        }

        $this->assertSame(0, Message::query()->where('content', 'Odd')->count());
    }

    public function test_a_numeric_string_reply_to_id_works_like_a_form_post(): void
    {
        Event::fake([MessageSent::class]);

        $this->reply(['content' => 'From a form', 'reply_to_id' => (string) $this->original->id])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $this->original->id);
    }

    public function test_a_null_reply_to_id_is_not_a_reply(): void
    {
        Event::fake([MessageSent::class]);

        $this->reply(['content' => 'Hi', 'reply_to_id' => null])
            ->assertCreated()
            ->assertJsonPath('message.reply_to', null);
    }

    public function test_the_wrong_type_has_its_own_error(): void
    {
        $this->reply(['content' => 'Odd', 'reply_to_id' => 'abc'])
            ->assertJsonValidationErrors(['reply_to_id' => 'The message you are replying to does not exist.']);
    }

    public function test_a_member_can_reply_to_their_own_message(): void
    {
        Event::fake([MessageSent::class]);
        $own = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->alice->id, 'content' => 'Me first']);

        $this->reply(['content' => 'And me again', 'reply_to_id' => $own->id])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.author.name', 'Alice');
    }

    public function test_a_non_member_cannot_reply(): void
    {
        $this->reply(['content' => 'Sneaky', 'reply_to_id' => $this->original->id], User::factory()->create())
            ->assertForbidden();

        $this->assertSame(0, Message::query()->where('reply_to_id', $this->original->id)->count());
    }

    // --- Chains and edits ---------------------------------------------

    public function test_a_reply_to_a_reply_quotes_only_its_direct_parent(): void
    {
        Event::fake([MessageSent::class]);
        $first = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->alice->id,
            'reply_to_id' => $this->original->id,
            'content' => 'First answer',
        ]);

        $this->reply(['content' => 'Second answer', 'reply_to_id' => $first->id], $this->owner)
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $first->id)
            ->assertJsonPath('message.reply_to.content', 'First answer')
            ->assertJsonPath('message.reply_to.author.name', 'Alice')
            ->assertJsonMissingPath('message.reply_to.reply_to');
    }

    public function test_editing_the_original_updates_the_quote(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $this->original->update(['content' => 'A Discord clone, edited']);

        $payload = MessageFeed::for($this->channel)->messages->firstWhere('id', $reply->id)?->toPayload();
        $this->assertSame('A Discord clone, edited', $payload['reply_to']['content'] ?? null);
    }

    public function test_editing_a_reply_keeps_its_quote(): void
    {
        Event::fake([MessageUpdated::class]);
        $reply = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->alice->id,
            'reply_to_id' => $this->original->id,
            'content' => 'Typo',
        ]);

        $this->actingAs($this->alice)->patchJson($this->messagesUrl().'/'.$reply->id, ['content' => 'Fixed'])
            ->assertOk()
            ->assertJsonPath('message.content', 'Fixed')
            ->assertJsonPath('message.reply_to.id', $this->original->id);

        $this->assertSame($this->original->id, $reply->fresh()?->reply_to_id);
        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => ($event->broadcastWith()['reply_to']['id'] ?? null) === $this->original->id);
    }

    public function test_an_edit_cannot_change_what_a_message_replies_to(): void
    {
        Event::fake([MessageUpdated::class]);
        $other = Message::factory()->inChannel($this->channel)->create();
        $reply = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->alice->id,
            'reply_to_id' => $this->original->id,
        ]);

        $this->actingAs($this->alice)
            ->patchJson($this->messagesUrl().'/'.$reply->id, ['content' => 'Edited', 'reply_to_id' => $other->id])
            ->assertOk();

        $this->assertSame($this->original->id, $reply->fresh()?->reply_to_id);
    }

    public function test_a_hard_deleted_original_turns_the_reply_into_a_plain_message(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $this->original->forceDelete();

        $reply->refresh();
        $this->assertNull($reply->reply_to_id);
        $this->assertNull($reply->replyPreview());
    }

    public function test_a_preview_without_eager_loading_still_works(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $fresh = Message::query()->findOrFail($reply->id);
        $this->assertFalse($fresh->relationLoaded('replyTo'));

        $this->assertSame('What should we build next?', $fresh->replyPreview()['content'] ?? null);
        $this->assertNull($this->original->replyPreview());
    }

    // --- The channel page ---------------------------------------------

    public function test_the_quote_on_the_page_is_escaped(): void
    {
        $this->original->update(['content' => '<script>alert("x")</script>']);
        Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $this->actingAs($this->alice)->get("/servers/{$this->server->id}/channels/{$this->channel->id}")
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_an_original_older_than_the_loaded_page_is_still_quoted(): void
    {
        Message::factory()->inChannel($this->channel)->count(MessageFeed::LIMIT)->create();
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $feed = MessageFeed::for($this->channel);

        $this->assertFalse($feed->messages->contains('id', $this->original->id));
        $quote = $feed->messages->firstWhere('id', $reply->id)?->replyPreview();
        $this->assertSame($this->original->id, $quote['id'] ?? null);
        $this->assertFalse($quote['deleted'] ?? true);
    }

    // --- The excerpt --------------------------------------------------

    public function test_the_excerpt_keeps_exactly_the_limit_and_cuts_one_more(): void
    {
        $limit = Message::REPLY_PREVIEW_LENGTH;

        $this->assertSame(str_repeat('x', $limit), Message::excerpt(str_repeat('x', $limit)));
        $this->assertSame(str_repeat('x', $limit - 1).'…', Message::excerpt(str_repeat('x', $limit + 1)));
    }

    public function test_the_excerpt_counts_characters_not_bytes(): void
    {
        $limit = Message::REPLY_PREVIEW_LENGTH;

        $this->assertSame(str_repeat('😀', $limit), Message::excerpt(str_repeat('😀', $limit)));

        $cut = Message::excerpt(str_repeat('😀', $limit + 1));
        $this->assertSame($limit, mb_strlen($cut));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    public function test_the_excerpt_does_not_end_in_a_space_before_the_ellipsis(): void
    {
        $content = str_repeat('x', Message::REPLY_PREVIEW_LENGTH - 2).' '.str_repeat('y', 10);

        $this->assertSame(str_repeat('x', Message::REPLY_PREVIEW_LENGTH - 2).'…', Message::excerpt($content));
    }

    public function test_the_excerpt_of_whitespace_is_empty_and_unicode_spaces_collapse(): void
    {
        $this->assertSame('', Message::excerpt(" \n\t "));
        $this->assertSame('a b c', Message::excerpt("a\u{00A0}b\u{2003}\u{3000}c"));
    }

    public function test_the_preview_text_of_an_empty_original_without_files_is_empty(): void
    {
        $this->assertSame('', Message::previewText(['content' => '', 'has_attachments' => false]));
        $this->assertSame('Hi', Message::previewText(['content' => 'Hi', 'has_attachments' => true]));
    }
}
