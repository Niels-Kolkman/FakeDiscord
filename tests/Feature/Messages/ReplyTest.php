<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-20: replying to a message. A reply stores reply_to_id, quotes the
 * original (author and a one-line excerpt) in its payload and on the
 * page, and links to #message-{id} so the script can jump to it.
 */
class ReplyTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function reply(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->alice)
            ->postJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages", $data);
    }

    /**
     * @return TestResponse<Response>
     */
    private function channelPage(): TestResponse
    {
        return $this->actingAs($this->alice)->get("/servers/{$this->server->id}/channels/{$this->channel->id}");
    }

    public function test_a_member_can_reply_to_a_message(): void
    {
        Event::fake([MessageSent::class]);

        $response = $this->reply(['content' => 'A Discord clone!', 'reply_to_id' => $this->original->id])
            ->assertCreated()
            ->assertJsonPath('message.reply_to', [
                'id' => $this->original->id,
                'deleted' => false,
                'author' => ['id' => $this->owner->id, 'name' => 'Owner'],
                'content' => 'What should we build next?',
                'has_attachments' => false,
            ]);

        $reply = Message::query()->findOrFail($response->json('message.id'));
        $this->assertSame($this->original->id, $reply->reply_to_id);
    }

    public function test_the_broadcast_carries_the_quote(): void
    {
        Event::fake([MessageSent::class]);

        $this->reply(['content' => 'Agreed', 'reply_to_id' => $this->original->id])->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            $quote = $event->broadcastWith()['reply_to'];

            return $quote['id'] === $this->original->id && $quote['author']['name'] === 'Owner';
        });
    }

    public function test_an_empty_reply_to_id_is_not_a_reply(): void
    {
        Event::fake([MessageSent::class]);

        $this->reply(['content' => 'Hi', 'reply_to_id' => ''])
            ->assertCreated()
            ->assertJsonPath('message.reply_to', null);
    }

    public function test_cannot_reply_to_a_message_of_another_channel(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        $elsewhere = Message::factory()->inChannel($other)->create();

        $this->reply(['content' => 'Nope', 'reply_to_id' => $elsewhere->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to_id');

        $this->assertSame(0, Message::query()->where('content', 'Nope')->count());
    }

    public function test_cannot_reply_to_a_message_of_another_server(): void
    {
        $otherServer = app(CreateServer::class)->create($this->alice, 'Other Server');
        /** @var Channel $otherChannel */
        $otherChannel = $otherServer->channels()->firstOrFail();
        $foreign = Message::factory()->inChannel($otherChannel)->create();

        $this->reply(['content' => 'Nope', 'reply_to_id' => $foreign->id])
            ->assertJsonValidationErrors('reply_to_id');
    }

    public function test_cannot_reply_to_a_deleted_or_missing_message(): void
    {
        $this->original->delete();

        $this->reply(['content' => 'Too late', 'reply_to_id' => $this->original->id])
            ->assertJsonValidationErrors(['reply_to_id' => 'The message you are replying to was deleted.']);

        $this->reply(['content' => 'Nope', 'reply_to_id' => 999999])
            ->assertJsonValidationErrors('reply_to_id');

        $this->reply(['content' => 'Nope', 'reply_to_id' => 'abc'])
            ->assertJsonValidationErrors('reply_to_id');
    }

    public function test_a_reply_can_be_a_file_only_message(): void
    {
        Event::fake([MessageSent::class]);
        Storage::fake(MessageAttachment::disk());

        $this->actingAs($this->alice)
            ->post("/servers/{$this->server->id}/channels/{$this->channel->id}/messages", [
                'reply_to_id' => $this->original->id,
                'attachments' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $this->original->id);
    }

    public function test_the_quote_is_cut_to_one_short_line(): void
    {
        $this->original->update(['content' => "First line\n\nsecond   line ".str_repeat('x', 200)]);
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $quote = $reply->replyPreview()['content'] ?? '';

        $this->assertStringStartsWith('First line second line x', $quote);
        $this->assertSame(Message::REPLY_PREVIEW_LENGTH, mb_strlen($quote));
        $this->assertStringEndsWith('…', $quote);
        $this->assertSame('Short', Message::excerpt('  Short  '));
    }

    public function test_a_reply_to_a_file_only_message_says_so(): void
    {
        $this->original->update(['content' => '']);
        $this->original->attachments()->create([
            'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 1,
        ]);
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);

        $preview = MessageFeed::for($this->channel)->messages->firstWhere('id', $reply->id)?->replyPreview();

        $this->assertNotNull($preview);
        $this->assertTrue($preview['has_attachments']);
        $this->assertSame('Click to see attachment', Message::previewText($preview));
    }

    public function test_a_reply_to_a_deleted_message_says_it_was_deleted(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);
        $this->original->delete();

        $this->assertSame([
            'id' => $this->original->id,
            'deleted' => true,
            'author' => null,
            'content' => '',
            'has_attachments' => false,
        ], $reply->fresh()?->replyPreview());

        $this->channelPage()
            ->assertOk()
            ->assertSee('Original message was deleted')
            ->assertDontSee('What should we build next?');
    }

    public function test_a_reply_whose_original_author_is_gone_shows_deleted_user(): void
    {
        $reply = Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);
        $this->original->update(['user_id' => null]);

        $preview = $reply->fresh()?->replyPreview();
        $this->assertNotNull($preview);
        $this->assertNull($preview['author']);

        $this->channelPage()->assertSee('Deleted User');
    }

    public function test_the_channel_page_quotes_the_original_and_links_to_it(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->alice->id,
            'reply_to_id' => $this->original->id,
            'content' => 'A Discord clone!',
        ]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('id="message-'.$this->original->id.'"', false)
            ->assertSee('data-reply-to-id="'.$this->original->id.'"', false)
            ->assertSee('href="#message-'.$this->original->id.'"', false)
            ->assertSee('data-reply-input', false)
            ->assertSee('data-message-reply', false);
    }

    public function test_the_feed_loads_quotes_without_a_query_per_message(): void
    {
        foreach (range(1, 10) as $i) {
            Message::factory()->inChannel($this->channel)->create(['reply_to_id' => $this->original->id]);
        }

        $feed = MessageFeed::for($this->channel);

        DB::enableQueryLog();
        $feed->toPayload($this->alice);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries);
    }
}
