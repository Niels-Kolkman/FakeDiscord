<?php

namespace Tests\Feature\Messages;

use App\Actions\Messages\AddReaction;
use App\Actions\Servers\CreateServer;
use App\Events\ReactionsUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\Reactions;
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
 * ISSUE-18: members add and remove emoji reactions on channel messages.
 * Reactions are grouped per emoji with a count and the first reactors
 * (tooltip), one per (message, user, emoji), and ReactionsUpdated
 * broadcasts the new counts on the private "channel.{id}" channel.
 */
class ReactionTest extends TestCase
{
    use RefreshDatabase, RunsTheQueueWorker;

    private User $owner;

    private User $alice;

    private User $bob;

    private Server $server;

    private Channel $channel;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        foreach ([$this->alice, $this->bob] as $user) {
            ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id]);
        }

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;

        $this->message = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->owner->id,
            'content' => 'React to me',
        ]);
    }

    private function url(?Message $message = null, ?Channel $channel = null): string
    {
        $message ??= $this->message;
        $channel ??= $this->channel;

        return "/servers/{$this->server->id}/channels/{$channel->id}/messages/{$message->id}/reactions";
    }

    private function channelUrl(): string
    {
        return "/servers/{$this->server->id}/channels/{$this->channel->id}";
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function react(User $as, mixed $emoji, ?Message $message = null, array $headers = []): TestResponse
    {
        return $this->actingAs($as)->postJson($this->url($message), ['emoji' => $emoji], $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function unreact(User $as, mixed $emoji, ?Message $message = null, array $headers = []): TestResponse
    {
        return $this->actingAs($as)->deleteJson($this->url($message), ['emoji' => $emoji], $headers);
    }

    private function addReaction(User $user, string $emoji, ?Message $message = null): Reaction
    {
        return Reaction::factory()->create([
            'message_id' => ($message ?? $this->message)->id,
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);
    }

    /**
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

    // --- Adding ------------------------------------------------------------

    public function test_a_member_can_react_to_a_message(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->alice, '👍')
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => '👍', 'count' => 1, 'users' => [['id' => $this->alice->id, 'name' => 'Alice']], 'me' => true],
            ]]);

        $this->assertDatabaseHas('reactions', [
            'message_id' => $this->message->id,
            'user_id' => $this->alice->id,
            'emoji' => '👍',
        ]);
    }

    public function test_reactions_are_grouped_per_emoji_in_order_of_first_use(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->owner, '🔥');
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->owner, '👍');

        $this->react($this->bob, '👍')
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => '🔥', 'count' => 1, 'users' => [['id' => $this->owner->id, 'name' => 'Owner']], 'me' => false],
                ['emoji' => '👍', 'count' => 3, 'users' => [
                    ['id' => $this->alice->id, 'name' => 'Alice'],
                    ['id' => $this->owner->id, 'name' => 'Owner'],
                    ['id' => $this->bob->id, 'name' => 'Bob'],
                ], 'me' => true],
            ]]);
    }

    public function test_reacting_twice_with_the_same_emoji_keeps_one_reaction(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->alice, '👍')->assertOk();
        $this->react($this->alice, '👍')->assertOk()->assertJsonPath('reactions.0.count', 1);

        $this->assertSame(1, Reaction::query()->count());
        // Only the first one changed anything.
        Event::assertDispatchedTimes(ReactionsUpdated::class, 1);
    }

    public function test_the_add_action_survives_a_race_on_the_unique_index(): void
    {
        Event::fake([ReactionsUpdated::class]);
        // Another request inserted the same reaction a moment earlier.
        $this->addReaction($this->alice, '👍');

        $added = app(AddReaction::class)->add($this->alice, $this->message, '👍');

        $this->assertFalse($added);
        $this->assertSame(1, Reaction::query()->count());
        Event::assertNotDispatched(ReactionsUpdated::class);
    }

    public function test_a_user_can_react_with_several_emoji(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->alice, '👍')->assertOk();
        $this->react($this->alice, '🎉')->assertOk()
            ->assertJsonPath('reactions.0.emoji', '👍')
            ->assertJsonPath('reactions.1.emoji', '🎉')
            ->assertJsonPath('reactions.1.me', true);
    }

    public function test_anyone_can_react_to_their_own_message(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->owner, '❤️')->assertOk()->assertJsonPath('reactions.0.me', true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validEmoji(): array
    {
        return [
            'simple' => ['👍'],
            'with variation selector' => ['❤️'],
            'skin tone' => ['👍🏽'],
            'flag' => ['🇳🇱'],
            'keycap' => ['1️⃣'],
            'zwj family' => ['👨‍👩‍👧'],
            'zwj flag' => ['🏳️‍🌈'],
        ];
    }

    #[DataProvider('validEmoji')]
    public function test_unicode_emoji_are_accepted(string $emoji): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->alice, $emoji)->assertOk()->assertJsonPath('reactions.0.emoji', $emoji);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidEmoji(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'text' => ['lol'],
            'markup' => ['<b>👍</b>'],
            'two emoji' => ['👍👍'],
            'two flags' => ['🇳🇱🇧🇪'],
            'emoji and text' => ['👍a'],
            'custom emoji' => [':partyparrot:'],
            'only a joiner' => ["\u{200D}"],
            'array' => [['👍']],
            'too long' => [str_repeat('👍', 20)],
        ];
    }

    #[DataProvider('invalidEmoji')]
    public function test_anything_but_a_single_emoji_is_refused(mixed $emoji): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->react($this->alice, $emoji)->assertUnprocessable()->assertJsonValidationErrors('emoji');

        $this->assertSame(0, Reaction::query()->count());
        Event::assertNotDispatched(ReactionsUpdated::class);
    }

    public function test_a_message_holds_at_most_twenty_different_emoji(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $emoji = ['😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗', '😙', '🥲', '😚', '🙂'];
        $this->assertCount(Reactions::MAX_PER_MESSAGE, $emoji);
        foreach ($emoji as $e) {
            $this->addReaction($this->owner, $e);
        }

        $this->react($this->alice, '🤗')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['emoji' => 'at most 20 different reactions']);

        // Joining an emoji that is already there is still fine.
        $this->react($this->alice, '😀')->assertOk()->assertJsonPath('reactions.0.count', 2);
    }

    // --- Removing ----------------------------------------------------------

    public function test_a_member_can_remove_their_reaction(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->bob, '👍');

        $this->unreact($this->alice, '👍')
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => '👍', 'count' => 1, 'users' => [['id' => $this->bob->id, 'name' => 'Bob']], 'me' => false],
            ]]);

        $this->assertDatabaseMissing('reactions', ['user_id' => $this->alice->id]);
        $this->assertDatabaseHas('reactions', ['user_id' => $this->bob->id, 'emoji' => '👍']);
    }

    public function test_removing_the_last_reaction_leaves_no_group(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->alice, '👍');

        $this->unreact($this->alice, '👍')->assertOk()->assertExactJson(['reactions' => []]);
    }

    public function test_removing_a_reaction_you_do_not_have_changes_nothing(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->bob, '👍');

        $this->unreact($this->alice, '👍')->assertOk()->assertJsonPath('reactions.0.count', 1);

        $this->assertSame(1, Reaction::query()->count());
        Event::assertNotDispatched(ReactionsUpdated::class);
    }

    public function test_a_stored_custom_emoji_can_still_be_removed(): void
    {
        Event::fake([ReactionsUpdated::class]);
        // The demo seeder uses custom names that can no longer be added.
        $this->addReaction($this->alice, ':partyparrot:');

        $this->unreact($this->alice, ':partyparrot:')->assertOk()->assertExactJson(['reactions' => []]);
    }

    public function test_removing_needs_an_emoji(): void
    {
        $this->unreact($this->alice, null)->assertUnprocessable()->assertJsonValidationErrors('emoji');
    }

    // --- Who may react -----------------------------------------------------

    public function test_a_non_member_cannot_react_or_remove(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $stranger = User::factory()->create();
        $this->addReaction($this->alice, '👍');

        $this->react($stranger, '👍')->assertForbidden();
        $this->unreact($stranger, '👍')->assertForbidden();

        $this->assertSame(1, Reaction::query()->count());
    }

    public function test_a_former_member_cannot_react(): void
    {
        ServerMember::query()->where('user_id', $this->alice->id)->delete();

        $this->react($this->alice, '👍')->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->post($this->url(), ['emoji' => '👍'])->assertRedirect('/login');
        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_a_message_of_another_channel_is_not_found(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);

        $this->actingAs($this->alice)
            ->postJson($this->url(channel: $other), ['emoji' => '👍'])
            ->assertNotFound();
    }

    public function test_a_deleted_message_is_not_found(): void
    {
        $this->message->delete();

        $this->react($this->alice, '👍')->assertNotFound();
        $this->unreact($this->alice, '👍')->assertNotFound();
    }

    public function test_reacting_is_rate_limited(): void
    {
        Event::fake([ReactionsUpdated::class]);

        for ($i = 0; $i < 60; $i++) {
            $this->unreact($this->alice, '👍')->assertOk();
        }

        $this->react($this->alice, '👍')->assertTooManyRequests();
    }

    // --- Without JavaScript ------------------------------------------------

    public function test_the_forms_redirect_back_to_the_channel(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->actingAs($this->alice)->post($this->url(), ['emoji' => '🎉'])->assertRedirect($this->channelUrl());
        $this->assertSame(1, Reaction::query()->count());

        $this->actingAs($this->alice)->delete($this->url(), ['emoji' => '🎉'])->assertRedirect($this->channelUrl());
        $this->assertSame(0, Reaction::query()->count());
    }

    // --- Broadcasting --------------------------------------------------------

    public function test_adding_broadcasts_the_new_counts_to_others(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->bob, '👍');

        $this->react($this->alice, '👍', headers: ['X-Socket-ID' => '1234.5678'])->assertOk();

        Event::assertDispatchedTimes(ReactionsUpdated::class, 1);
        Event::assertDispatched(ReactionsUpdated::class, function (ReactionsUpdated $event): bool {
            return $event->message->is($this->message)
                && $event->broadcastOn()->name === "private-channel.{$this->channel->id}"
                && $event->broadcastAs() === 'reactions.updated'
                && $event->broadcastWith() === [
                    'message_id' => $this->message->id,
                    'channel_id' => $this->channel->id,
                    'conversation_id' => null,
                    'user_id' => $this->alice->id,
                    'emoji' => '👍',
                    'added' => true,
                    // No `me`: the broadcast goes to everyone.
                    'reactions' => [['emoji' => '👍', 'count' => 2, 'users' => [
                        ['id' => $this->bob->id, 'name' => 'Bob'],
                        ['id' => $this->alice->id, 'name' => 'Alice'],
                    ]]],
                ]
                // toOthers(): the reactor's own socket is skipped.
                && $event->socket === '1234.5678';
        });
    }

    public function test_removing_broadcasts_the_new_counts_to_others(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->alice, '👍');

        $this->unreact($this->alice, '👍', headers: ['X-Socket-ID' => '1234.5678'])->assertOk();

        Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $event): bool => $event->broadcastWith()['added'] === false
            && $event->broadcastWith()['reactions'] === []
            && $event->socket === '1234.5678');
    }

    public function test_the_request_queues_the_broadcast_and_the_worker_sends_it(): void
    {
        // ISSUE-42: queued on the database queue, sent by the worker.
        config(['queue.default' => 'database']);
        $this->assertContains(ShouldBroadcast::class, class_implements(ReactionsUpdated::class));
        $spy = $this->spyBroadcaster();

        $this->react($this->alice, '🔥')->assertOk();

        $this->assertCount(0, $spy->sent);
        $this->runQueueWorker();

        $this->assertCount(1, $spy->sent);
        [$channels, $name, $payload] = $spy->sent[0];
        $this->assertSame(["private-channel.{$this->channel->id}"], $channels);
        $this->assertSame('reactions.updated', $name);
        $this->assertSame('🔥', $payload['emoji']);
        $this->assertSame(1, $payload['reactions'][0]['count']);
    }

    public function test_a_failed_broadcast_still_saves_the_reaction(): void
    {
        Exceptions::fake();
        $this->spyBroadcaster(fail: true);

        $this->react($this->alice, '🔥')->assertOk();

        $this->assertSame(1, Reaction::query()->count());
        Exceptions::assertReported(BroadcastException::class);
    }

    // --- Showing reactions ---------------------------------------------------

    public function test_the_history_api_includes_reactions_for_the_viewer(): void
    {
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->bob, '👍');

        $this->actingAs($this->bob)
            ->getJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.reactions', [
                ['emoji' => '👍', 'count' => 2, 'users' => [
                    ['id' => $this->alice->id, 'name' => 'Alice'],
                    ['id' => $this->bob->id, 'name' => 'Bob'],
                ], 'me' => true],
            ]);

        $this->actingAs($this->owner)
            ->getJson("/servers/{$this->server->id}/channels/{$this->channel->id}/messages")
            ->assertJsonPath('messages.0.reactions.0.me', false);
    }

    public function test_the_channel_page_shows_reactions_with_tooltips(): void
    {
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->bob, '👍');
        $this->addReaction($this->bob, '🎉');

        $page = $this->actingAs($this->alice)->get($this->channelUrl())->assertOk();

        $page->assertSee('data-reactions-url="'.url($this->url()).'"', false)
            ->assertSee('title="Alice and Bob reacted with 👍"', false)
            ->assertSee('aria-label="👍, 2 reactions"', false)
            ->assertSee('title="Bob reacted with 🎉"', false)
            ->assertSee('aria-label="🎉, 1 reaction"', false)
            // The picker offers the quick emoji, as forms.
            ->assertSee('aria-label="React with 😂"', false)
            ->assertSee('data-reaction-emoji=', false);

        $html = (string) $page->getContent();
        // Alice reacted with 👍: that button takes it back (DELETE); 🎉 adds hers.
        $this->assertMatchesRegularExpression('/name="_method" value="DELETE">\s*<input type="hidden" name="emoji" value="👍">\s*<button[^>]*aria-pressed="true"/u', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="emoji" value="🎉">\s*<button[^>]*aria-pressed="false"/u', $html);
    }

    public function test_a_message_without_reactions_hides_the_row(): void
    {
        $html = (string) $this->actingAs($this->alice)->get($this->channelUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-message-reactions\s+hidden/', $html);
    }

    public function test_reaction_markup_is_escaped(): void
    {
        $this->alice->forceFill(['name' => '<script>alert(1)</script>'])->save();
        $this->addReaction($this->alice, '👍');

        $this->actingAs($this->bob)->get($this->channelUrl())->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; reacted with 👍', false);
    }

    public function test_a_deleted_account_takes_its_reactions_with_it(): void
    {
        $this->addReaction($this->alice, '👍');

        $this->alice->delete();

        $this->assertSame(0, Reaction::query()->count());
    }

    // --- Tooltip text --------------------------------------------------------

    /**
     * @return array<string, array{int, list<string>, string}>
     */
    public static function tooltips(): array
    {
        return [
            'one' => [1, ['Alice'], 'Alice reacted with 👍'],
            'two' => [2, ['Alice', 'Bob'], 'Alice and Bob reacted with 👍'],
            'three' => [3, ['Alice', 'Bob', 'Carol'], 'Alice, Bob and Carol reacted with 👍'],
            'one more' => [4, ['Alice', 'Bob', 'Carol'], 'Alice, Bob, Carol and 1 other reacted with 👍'],
            'many more' => [10, ['Alice', 'Bob', 'Carol'], 'Alice, Bob, Carol and 7 others reacted with 👍'],
            'no names' => [2, [], '2 others reacted with 👍'],
        ];
    }

    /**
     * @param  list<string>  $names
     */
    #[DataProvider('tooltips')]
    public function test_the_tooltip_names_the_first_reactors(int $count, array $names, string $expected): void
    {
        $users = array_map(fn (string $name, int $i): array => ['id' => $i + 1, 'name' => $name], $names, array_keys($names));

        $this->assertSame($expected, Reactions::tooltip(['emoji' => '👍', 'count' => $count, 'users' => $users]));
    }

    public function test_groups_name_at_most_three_reactors(): void
    {
        foreach (User::factory()->count(5)->create() as $user) {
            $this->addReaction($user, '👍');
        }

        $groups = Reactions::groups($this->message->load(Reactions::relations()));

        $this->assertSame(5, $groups[0]['count']);
        $this->assertCount(Reactions::NAMES_SHOWN, $groups[0]['users']);
        $this->assertArrayNotHasKey('me', $groups[0]);
    }
}
