<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ISSUE-14: an open text channel shows its newest messages and a composer
 * wired up for resources/js/channel-messages.js.
 */
class ChannelMessagesPageTest extends TestCase
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

    private function channelUrl(?Channel $channel = null): string
    {
        $channel ??= $this->channel;

        return "/servers/{$channel->server_id}/channels/{$channel->id}";
    }

    public function test_the_channel_shows_its_messages_oldest_first_with_authors(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => 'First message']);
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id, 'content' => 'Second message']);

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertOk()
            ->assertSeeInOrder(['Welcome to #general!', 'Owner', 'First message', 'Member', 'Second message'])
            ->assertSee('data-message-list', false);
    }

    public function test_the_default_server_page_shows_the_messages_of_its_first_channel(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id, 'content' => 'On the server page']);

        $this->actingAs($this->member)->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSee('On the server page')
            ->assertSee('data-channel-id="'.$this->channel->id.'"', false);
    }

    public function test_messages_of_other_channels_are_not_shown(): void
    {
        $random = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'random']);
        Message::factory()->inChannel($random)->create(['content' => 'In random']);
        Message::factory()->inChannel($this->channel)->create(['content' => 'In general']);

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertSee('In general')
            ->assertDontSee('In random');
    }

    public function test_only_the_newest_messages_are_shown_and_the_welcome_header_is_hidden(): void
    {
        for ($i = 1; $i <= MessageFeed::LIMIT + 1; $i++) {
            Message::factory()->inChannel($this->channel)->create(['content' => sprintf('Message number %03d', $i)]);
        }

        $response = $this->actingAs($this->member)->get($this->channelUrl())->assertOk();

        // The header is rendered hidden; channel-messages.js shows it once
        // the last older page has been loaded.
        $response->assertDontSee('Message number 001')
            ->assertSeeInOrder(['Message number 002', sprintf('Message number %03d', MessageFeed::LIMIT + 1)]);
        $this->assertMatchesRegularExpression('/data-channel-start\s+hidden/', (string) $response->getContent());
        $this->assertSame(MessageFeed::LIMIT, substr_count((string) $response->getContent(), 'data-message-id='));
    }

    public function test_exactly_the_limit_still_shows_the_start_of_the_channel(): void
    {
        Message::factory()->count(MessageFeed::LIMIT)->inChannel($this->channel)->create();

        $response = $this->actingAs($this->member)->get($this->channelUrl())
            ->assertSee('This is the start of the #general channel.')
            ->assertSee('data-older-cursor=""', false);
        $this->assertDoesNotMatchRegularExpression('/data-channel-start\s+hidden/', (string) $response->getContent());
    }

    public function test_messages_of_deleted_users_show_deleted_user(): void
    {
        Message::factory()->inChannel($this->channel)->create(['user_id' => null, 'content' => 'Orphaned']);

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertOk()
            ->assertSeeInOrder(['Deleted User', 'Orphaned']);
    }

    public function test_text_channels_have_a_composer_that_posts_to_the_channel(): void
    {
        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertOk()
            ->assertSee('data-channel-messages', false)
            ->assertSee('action="'.route('messages.store', [$this->server, $this->channel]).'"', false)
            ->assertSee('name="content"', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('placeholder="Message #general"', false);
    }

    public function test_voice_channels_have_no_messages_or_composer(): void
    {
        $voice = Channel::factory()->create(['server_id' => $this->server->id, 'type' => Channel::TYPE_VOICE, 'name' => 'Lounge']);

        $this->actingAs($this->member)->get($this->channelUrl($voice))
            ->assertOk()
            ->assertDontSee('data-channel-messages', false)
            ->assertDontSee('data-message-form', false)
            ->assertDontSee('data-typing-indicator', false);
    }

    public function test_text_channels_have_a_polite_live_typing_indicator(): void
    {
        // ISSUE-17: typing-indicator.js fills it; empty on page load.
        $content = (string) $this->actingAs($this->member)->get($this->channelUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p[^>]*aria-live="polite"[^>]*data-typing-indicator><\/p>/', $content);
        $this->assertLessThan(
            strpos($content, 'data-typing-indicator'),
            strpos($content, 'data-message-form'),
            'the indicator sits below the composer',
        );
    }

    public function test_viewers_who_cannot_send_still_get_the_typing_indicator(): void
    {
        // ISSUE-17: the indicator is outside the composer, so it stays once
        // roles (ISSUE-26) can take "Send Messages" away.
        Gate::before(fn (User $user, string $ability) => $ability === 'sendMessages' ? false : null);

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertOk()
            ->assertDontSee('data-message-form', false)
            ->assertSee('data-typing-indicator', false);
    }

    public function test_a_validation_error_from_a_form_post_is_shown_on_the_channel(): void
    {
        $this->actingAs($this->member)
            ->from($this->channelUrl())
            ->post($this->channelUrl().'/messages', ['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)])
            ->assertRedirect($this->channelUrl());

        $this->actingAs($this->member)->get($this->channelUrl())
            ->assertSee('Messages can be at most 2000 characters long.');
    }

    public function test_authors_and_reactions_are_eager_loaded(): void
    {
        $authors = User::factory()->count(5)->create();
        foreach ($authors as $author) {
            $messages = Message::factory()->count(5)->inChannel($this->channel)->create(['user_id' => $author->id]);
            foreach ($messages as $message) {
                Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $author->id, 'emoji' => '👍']);
                MessageAttachment::factory()->create(['message_id' => $message->id]);
            }
        }

        $this->actingAs($this->member);
        $this->coldServerCache();
        DB::enableQueryLog();
        $this->get($this->channelUrl())->assertOk();
        $withMessages = count(DB::getQueryLog());

        Message::query()->delete();
        $this->coldServerCache();
        DB::flushQueryLog();
        $this->get($this->channelUrl())->assertOk();
        $without = count(DB::getQueryLog());

        // Extra queries for the authors, the attachments (ISSUE-19), the
        // reactions and the reactors, not one per message.
        $this->assertLessThanOrEqual($without + 4, $withMessages);
    }
}
