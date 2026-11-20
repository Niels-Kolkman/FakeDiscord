<?php

namespace Tests\Feature\Messages;

use App\Actions\Messages\AddReaction;
use App\Actions\Messages\RemoveReaction;
use App\Actions\Servers\CreateServer;
use App\Events\ReactionsUpdated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Edge cases not covered by ReactionTest: the emoji limit, the
 * scope of removing, cross-server URLs, the policy for DM messages, the
 * emoji validator, labels and eager loading.
 */
class ReactionEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /** The 20 emoji that fill a message up to Reactions::MAX_PER_MESSAGE. */
    private const TWENTY = ['😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗', '😙', '🥲', '😚', '🙂'];

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
        $this->message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
    }

    private function url(?Server $server = null, ?Channel $channel = null, ?Message $message = null): string
    {
        $server ??= $this->server;
        $channel ??= $this->channel;
        $message ??= $this->message;

        return "/servers/{$server->id}/channels/{$channel->id}/messages/{$message->id}/reactions";
    }

    private function addReaction(User $user, string $emoji, ?Message $message = null): Reaction
    {
        return Reaction::factory()->create([
            'message_id' => ($message ?? $this->message)->id,
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);
    }

    private function fillUp(): void
    {
        foreach (self::TWENTY as $emoji) {
            $this->addReaction($this->owner, $emoji);
        }
    }

    private function failingBroadcaster(): void
    {
        $failing = new class extends BaseBroadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new BroadcastException('Reverb is not running');
            }
        };

        Broadcast::extend('failing', fn () => $failing);
        config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);
    }

    // --- Unique (message, user, emoji) ----------------------------------------

    public function test_the_model_cannot_store_a_duplicate_reaction(): void
    {
        $this->addReaction($this->alice, '👍');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->addReaction($this->alice, '👍');
    }

    public function test_the_same_emoji_on_another_message_is_a_separate_reaction(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $other = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        $this->addReaction($this->alice, '👍');

        $this->actingAs($this->alice)
            ->postJson($this->url(message: $other), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.count', 1);

        $this->assertSame(2, Reaction::query()->where('user_id', $this->alice->id)->count());
    }

    public function test_emoji_differing_only_by_skin_tone_are_separate_groups(): void
    {
        Event::fake([ReactionsUpdated::class]);

        $this->actingAs($this->alice)->postJson($this->url(), ['emoji' => '👍'])->assertOk();
        $this->actingAs($this->alice)->postJson($this->url(), ['emoji' => '👍🏽'])->assertOk()
            ->assertJsonCount(2, 'reactions')
            ->assertJsonPath('reactions.1.emoji', '👍🏽');
    }

    // --- The 20 emoji limit ----------------------------------------------------

    public function test_the_limit_counts_different_emoji_not_reactions(): void
    {
        Event::fake([ReactionsUpdated::class]);
        // 19 different emoji plus a second reactor on one of them: 20 rows.
        foreach (array_slice(self::TWENTY, 0, 19) as $emoji) {
            $this->addReaction($this->owner, $emoji);
        }
        $this->addReaction($this->bob, '😀');

        $this->actingAs($this->alice)->postJson($this->url(), ['emoji' => '🤗'])
            ->assertOk()
            ->assertJsonCount(Reactions::MAX_PER_MESSAGE, 'reactions');
    }

    public function test_removing_an_emoji_frees_a_slot(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->fillUp();

        $this->actingAs($this->alice)->postJson($this->url(), ['emoji' => '🤗'])->assertUnprocessable();

        $this->actingAs($this->owner)->deleteJson($this->url(), ['emoji' => '🙂'])->assertOk();

        $this->actingAs($this->alice)->postJson($this->url(), ['emoji' => '🤗'])->assertOk()
            ->assertJsonCount(Reactions::MAX_PER_MESSAGE, 'reactions');
    }

    public function test_the_limit_is_per_message(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->fillUp();
        $other = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->alice)->postJson($this->url(message: $other), ['emoji' => '🤗'])->assertOk();
    }

    public function test_the_add_action_enforces_the_limit_itself(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->fillUp();

        try {
            app(AddReaction::class)->add($this->alice, $this->message, '🤗');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('emoji', $e->errors());
        }

        $this->assertSame(Reactions::MAX_PER_MESSAGE, Reaction::query()->count());
        Event::assertNotDispatched(ReactionsUpdated::class);
    }

    public function test_the_form_shows_the_limit_error_after_redirecting(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->fillUp();
        $channelUrl = "/servers/{$this->server->id}/channels/{$this->channel->id}";

        $this->actingAs($this->alice)
            ->from($channelUrl)
            ->post($this->url(), ['emoji' => '🤗'])
            ->assertRedirect($channelUrl)
            ->assertSessionHasErrors('emoji');
    }

    // --- Removing only takes back your own reaction ---------------------------

    public function test_removing_one_emoji_keeps_your_other_reactions(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->alice, '🎉');

        $this->actingAs($this->alice)->deleteJson($this->url(), ['emoji' => '👍'])
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => '🎉', 'count' => 1, 'users' => [['id' => $this->alice->id, 'name' => 'Alice']], 'me' => true],
            ]]);
    }

    public function test_the_server_owner_cannot_remove_someone_elses_reaction(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $this->addReaction($this->alice, '👍');

        $this->actingAs($this->owner)->deleteJson($this->url(), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.count', 1)
            ->assertJsonPath('reactions.0.me', false);

        $this->assertDatabaseHas('reactions', ['user_id' => $this->alice->id, 'emoji' => '👍']);
        Event::assertNotDispatched(ReactionsUpdated::class);
    }

    public function test_removing_only_touches_the_given_message(): void
    {
        Event::fake([ReactionsUpdated::class]);
        $other = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->alice, '👍', $other);

        $removed = app(RemoveReaction::class)->remove($this->alice, $this->message, '👍');

        $this->assertTrue($removed);
        $this->assertDatabaseHas('reactions', ['message_id' => $other->id, 'user_id' => $this->alice->id]);
        $this->assertDatabaseMissing('reactions', ['message_id' => $this->message->id]);
    }

    public function test_a_failed_broadcast_still_removes_the_reaction(): void
    {
        Exceptions::fake();
        $this->failingBroadcaster();
        $this->addReaction($this->alice, '👍');

        $this->actingAs($this->alice)->deleteJson($this->url(), ['emoji' => '👍'])
            ->assertOk()
            ->assertExactJson(['reactions' => []]);

        $this->assertSame(0, Reaction::query()->count());
        Exceptions::assertReported(BroadcastException::class);
    }

    // --- URLs and access ----------------------------------------------------------

    public function test_a_channel_of_another_server_is_not_found(): void
    {
        $otherServer = app(CreateServer::class)->create($this->alice, 'Alice Server');
        /** @var Channel $otherChannel */
        $otherChannel = $otherServer->channels()->firstOrFail();

        // Alice belongs to both servers, but the URL mixes them up.
        $this->actingAs($this->alice)
            ->postJson($this->url(server: $otherServer), ['emoji' => '👍'])
            ->assertNotFound();
        $this->actingAs($this->alice)
            ->postJson($this->url(channel: $otherChannel), ['emoji' => '👍'])
            ->assertNotFound();

        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_a_member_of_another_server_cannot_react_through_its_url(): void
    {
        $outsider = User::factory()->create();
        app(CreateServer::class)->create($outsider, 'Outsider Server');

        $this->actingAs($outsider)->postJson($this->url(), ['emoji' => '👍'])->assertForbidden();
        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_a_former_member_cannot_remove_their_old_reaction(): void
    {
        $this->addReaction($this->alice, '👍');
        ServerMember::query()->where('user_id', $this->alice->id)->delete();

        $this->actingAs($this->alice)->deleteJson($this->url(), ['emoji' => '👍'])->assertForbidden();
        $this->assertSame(1, Reaction::query()->count());
    }

    public function test_the_policy_refuses_reactions_on_direct_messages(): void
    {
        $conversation = Conversation::factory()->create();
        $dm = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);

        $this->assertFalse($this->alice->can('react', $dm));
        $this->assertTrue($this->alice->can('react', $this->message));
    }

    public function test_deleting_a_message_for_good_removes_its_reactions(): void
    {
        $this->addReaction($this->alice, '👍');

        $this->message->forceDelete();

        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_soft_deleting_a_message_keeps_its_reactions(): void
    {
        $this->addReaction($this->alice, '👍');

        $this->message->delete();

        $this->assertSame(1, Reaction::query()->count());
    }

    // --- The emoji validator ------------------------------------------------------

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function emojiCases(): array
    {
        return [
            'hash keycap' => ['#️⃣', true],
            'subdivision flag' => ['🏴󠁧󠁢󠁳󠁣󠁴󠁿', true],
            'zwj with skin tones' => ['🧑🏽‍🤝‍🧑🏻', true],
            'plain digit' => ['1', false],
            'plain hash' => ['#', false],
            'variation selector alone' => ["\u{FE0F}", false],
            'skin tone alone' => ["\u{1F3FD}", false],
            'leading space' => [' 👍', false],
            'trailing newline' => ["👍\n", false],
            'integer' => [1, false],
            'null' => [null, false],
            'invalid utf-8' => ["\xF0\x9F", false],
        ];
    }

    #[DataProvider('emojiCases')]
    public function test_is_valid_emoji(mixed $emoji, bool $expected): void
    {
        $this->assertSame($expected, Reactions::isValidEmoji($emoji));
    }

    public function test_every_quick_picker_emoji_is_valid_and_listed_once(): void
    {
        foreach (Reactions::QUICK as $emoji) {
            $this->assertTrue(Reactions::isValidEmoji($emoji), "Quick emoji {$emoji} is refused");
        }
        $this->assertSame(Reactions::QUICK, array_values(array_unique(Reactions::QUICK)));
    }

    // --- Labels, groups and queries -----------------------------------------------

    public function test_the_label_counts_reactions(): void
    {
        $this->assertSame('👍, 1 reaction', Reactions::label(['emoji' => '👍', 'count' => 1, 'users' => []]));
        $this->assertSame('👍, 2 reactions', Reactions::label(['emoji' => '👍', 'count' => 2, 'users' => []]));
    }

    public function test_me_is_true_even_when_the_viewer_is_not_among_the_names_shown(): void
    {
        foreach (User::factory()->count(3)->create() as $user) {
            $this->addReaction($user, '👍');
        }
        $this->addReaction($this->alice, '👍');

        $groups = Reactions::groups($this->message->load(Reactions::relations()), (int) $this->alice->id);

        $this->assertSame(4, $groups[0]['count']);
        $this->assertNotContains($this->alice->id, array_column($groups[0]['users'], 'id'));
        $this->assertTrue($groups[0]['me'] ?? null);
    }

    public function test_groups_run_no_queries_once_loaded(): void
    {
        $this->addReaction($this->alice, '👍');
        $this->addReaction($this->bob, '🎉');
        $message = Message::query()->with(Reactions::relations())->findOrFail($this->message->id);

        DB::enableQueryLog();
        Reactions::groups($message, (int) $this->alice->id);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_history_api_does_not_query_per_message(): void
    {
        $historyUrl = "/servers/{$this->server->id}/channels/{$this->channel->id}/messages";
        foreach (Message::factory()->count(5)->inChannel($this->channel)->create(['user_id' => $this->owner->id]) as $message) {
            $this->addReaction($this->alice, '👍', $message);
            $this->addReaction($this->bob, '🔥', $message);
        }

        $this->coldServerCache();
        DB::enableQueryLog();
        $this->actingAs($this->bob)->getJson($historyUrl)->assertOk();
        $few = count(DB::getQueryLog());

        foreach (Message::factory()->count(5)->inChannel($this->channel)->create(['user_id' => $this->owner->id]) as $message) {
            $this->addReaction($this->alice, '😂', $message);
        }

        $this->coldServerCache();
        DB::flushQueryLog();
        $this->actingAs($this->bob)->getJson($historyUrl)->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }
}
