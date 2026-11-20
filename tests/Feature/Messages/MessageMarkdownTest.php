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
 * Messages show their markdown (App\Support\Markdown) in channels
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
            ->assertSee('<div class="message-markdown whitespace-pre-wrap break-words text-fg" data-message-body>', false)
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

    public function test_a_sent_message_is_stored_and_returned_as_typed(): void
    {
        $content = "**bold** <b>html</b>\n```js\nlet x = 1;\n```";

        $this->actingAs($this->owner)
            ->postJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages", ['content' => $content])
            ->assertCreated()
            ->assertJsonPath('message.content', $content);

        $this->assertSame($content, Message::query()->latest('id')->firstOrFail()->content);

        $this->channelPage()
            ->assertOk()
            ->assertSee('<strong>bold</strong> &lt;b&gt;html&lt;/b&gt;<pre><code data-language="js">let x = 1;</code></pre>', false);
    }

    public function test_an_edited_message_shows_its_new_markdown_and_source(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => '**old**']);

        $this->actingAs($this->owner)
            ->patchJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages/{$message->id}", ['content' => '~~old~~ _new_'])
            ->assertOk()
            ->assertJsonPath('message.content', '~~old~~ _new_');

        $this->channelPage()
            ->assertOk()
            ->assertSee('data-source="~~old~~ _new_"><s>old</s> <em>new</em></span>', false)
            ->assertDontSee('<strong>old</strong>', false);
    }

    public function test_a_message_without_markdown_is_only_escaped(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => "Tom & Jerry's \"show\"\nnext"]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('>Tom &amp; Jerry&#039;s &quot;show&quot;'."\n".'next</span>', false);
    }

    public function test_masked_and_script_links_are_not_links_on_the_page(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => '[click](javascript:alert(1)) javascript:alert(2) <javascript:alert(3)> data:text/html,x',
        ]);

        $html = (string) $this->channelPage()->assertOk()->getContent();

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="data:', $html);
        $this->assertStringContainsString('[click](javascript:alert(1)) javascript:alert(2) &lt;javascript:alert(3)&gt; data:text/html,x', $html);
    }

    public function test_every_message_on_the_page_is_formatted_on_its_own(): void
    {
        // An unclosed delimiter in one message must not reach into the next.
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => '**open', 'created_at' => now()->subMinute()]);
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => 'close**', 'created_at' => now()]);

        $this->channelPage()
            ->assertOk()
            ->assertSee('data-source="**open">**open</span>', false)
            ->assertSee('data-source="close**">close**</span>', false)
            ->assertDontSee('<strong>', false);
    }

    public function test_html_in_search_results_stays_escaped(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => '**needle** <img src=x onerror=alert(1)>',
        ]);

        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/search?q=needle")
            ->assertOk()
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_html_in_a_reply_preview_stays_escaped(): void
    {
        $original = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => '**quoted** <script>alert(1)</script>',
            'created_at' => now()->subMinute(),
        ]);
        Message::factory()->inChannel($this->channel)->replyTo($original)->create(['user_id' => $this->owner->id, 'content' => 'reply']);

        $html = (string) $this->channelPage()->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertMatchesRegularExpression('/data-reply-content>[^<]*&lt;script&gt;alert\(1\)/', $html);
    }

    public function test_html_on_the_pins_page_stays_escaped(): void
    {
        Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => '**pinned** <iframe src=//evil></iframe>',
            'pinned_at' => now(),
            'pinned_by_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/channels/{$this->channel->id}/pins")
            ->assertOk()
            ->assertSee('&lt;iframe src=//evil&gt;&lt;/iframe&gt;', false)
            ->assertDontSee('<iframe src=//evil', false);
    }

    public function test_the_picker_toggle_is_a_labelled_button_that_does_not_submit(): void
    {
        $html = (string) $this->channelPage()->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*data-emoji-picker-toggle[^>]*>(.*?)<\/button>/s', $html, $match));
        $this->assertStringContainsString('type="button"', $match[0]);
        // Screen readers hear the text label, not the emoji's name.
        $this->assertSame('<span aria-hidden="true">😀</span><span class="sr-only">Choose an emoji</span>', $match[1]);
        $this->assertSame(1, substr_count($html, 'id="emoji-picker"'));
        $this->assertMatchesRegularExpression('/<div[^>]*id="emoji-picker"[^>]*aria-label="Emoji picker"/s', $html);
    }

    public function test_the_stored_and_broadcast_content_stays_the_raw_text(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => '**raw**']);

        $this->assertSame('**raw**', $message->fresh()?->content);
        $this->assertSame('**raw**', $message->toPayload()['content']);
    }
}
