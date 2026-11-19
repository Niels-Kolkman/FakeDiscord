<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-36: messages show their markdown (App\Support\Markdown) in channels
 * and DMs, raw HTML stays text, the raw source is kept for editing, and
 * the composer has the (script-only) emoji picker.
 */
class MessageMarkdownTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;
    }

    /**
     * @return TestResponse<Response>
     */
    private function channelPage(): TestResponse
    {
        return $this->actingAs($this->owner)->get("/servers/{$this->server->id}/channels/{$this->channel->id}");
    }

    public function test_messages_are_rendered_as_markdown(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => "**bold** and `code`\n> a quote\nsee https://example.com",
        ]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('<strong>bold</strong> and <code>code</code><blockquote>a quote</blockquote>see <a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow ugc">https://example.com</a>', false);
    }

    public function test_html_in_a_message_is_escaped_not_rendered(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => '**<script>alert(1)</script>** <img src=x onerror=alert(2)>',
        ]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong> &lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_the_raw_text_is_kept_for_editing(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => "**hi** \"there\" <b>\nline",
        ]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('data-source="**hi** &quot;there&quot; &lt;b&gt;'."\n".'line"', false);
    }

    public function test_the_message_body_is_a_div_so_blocks_can_sit_in_it(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => "```\ncode\n```"]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('<div class="message-markdown whitespace-pre-wrap break-words text-[#dbdee1]" data-message-body>', false)
            ->assertSee('<pre><code>code</code></pre>', false);
    }

    public function test_the_composer_has_a_hidden_emoji_picker(): void
    {
        $html = (string) $this->channelPage()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="emoji-picker"[^>]*data-emoji-picker-toggle[^>]*hidden[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*id="emoji-picker"[^>]*role="dialog"[^>]*data-emoji-picker[^>]*hidden[^>]*>/s', $html);
    }

    public function test_direct_messages_render_markdown_and_have_the_picker(): void
    {
        $friend = User::factory()->create(['name' => 'Friend']);
        $conversation = Conversation::factory()->between($this->owner, $friend)->create();
        Message::factory()->inConversation($conversation)->create(['user_id' => $friend->id, 'content' => '~~old~~ __new__']);

        $this->actingAs($this->owner)->get(route('dms.show', $conversation))
            ->assertOk()
            ->assertSee('<s>old</s> <u>new</u>', false)
            ->assertSee('data-emoji-picker-toggle', false);
    }

    public function test_the_stored_and_broadcast_content_stays_the_raw_text(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => '**raw**']);

        $this->assertSame('**raw**', $message->fresh()?->content);
        $this->assertSame('**raw**', $message->toPayload()['content']);
    }
}
