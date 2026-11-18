<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE-25: in a server you share with someone you blocked, their messages
 * are left out of the channel page, the history, the pins and search
 * (Message::visibleTo()). Only for the blocker: the blocked person and
 * everyone else still see them. A reply quoting them says "Blocked
 * message", and the page tells the script whom to skip when messages
 * arrive live (data-blocked-user-ids). DMs keep their whole history.
 */
class BlockedMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private Server $server;

    private Channel $channel;

    private Message $fromBob;

    private Message $fromCarol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->carol = User::factory()->create(['name' => 'Carol']);

        $this->server = app(CreateServer::class)->create($this->alice, 'Shared');
        foreach ([$this->bob, $this->carol] as $member) {
            ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $member->id]);
        }

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;

        $this->fromBob = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->bob->id, 'content' => 'Bob says spam']);
        $this->fromCarol = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->carol->id, 'content' => 'Carol says hi']);
    }

    private function block(User $blocker, User $blocked): Friendship
    {
        return Friendship::factory()->create([
            'user_id' => $blocker->id,
            'friend_id' => $blocked->id,
            'status' => Friendship::STATUS_BLOCKED,
        ]);
    }

    /**
     * @return list<int>
     */
    private function historyIds(User $as): array
    {
        return $this->actingAs($as)->getJson(route('messages.index', [$this->server, $this->channel]))->assertOk()
            ->collect('messages')
            ->pluck('id')
            ->all();
    }

    public function test_the_channel_page_hides_the_messages_of_people_you_blocked(): void
    {
        $this->block($this->alice, $this->bob);

        $this->actingAs($this->alice)->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk()
            ->assertDontSee('Bob says spam')
            ->assertSee('Carol says hi')
            ->assertSee('data-blocked-user-ids="['.$this->bob->id.']"', false);
    }

    public function test_the_blocked_person_and_everyone_else_still_see_them(): void
    {
        $this->block($this->alice, $this->bob);

        foreach ([$this->bob, $this->carol] as $viewer) {
            $this->actingAs($viewer)->get(route('servers.show', $this->server))
                ->assertOk()
                ->assertSee('Bob says spam')
                ->assertSee('data-blocked-user-ids="[]"', false);
        }

        $this->assertSame([$this->fromBob->id, $this->fromCarol->id], $this->historyIds($this->bob));
    }

    public function test_the_history_json_leaves_them_out(): void
    {
        $this->block($this->alice, $this->bob);

        $this->assertSame([$this->fromCarol->id], $this->historyIds($this->alice));
    }

    public function test_history_pages_skip_them_without_losing_other_messages(): void
    {
        $this->block($this->alice, $this->bob);
        // 60 more messages from Bob between Carol's and a last one from Carol.
        Message::factory()->count(60)->inChannel($this->channel)->create(['user_id' => $this->bob->id]);
        $last = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->carol->id]);

        $response = $this->actingAs($this->alice)->getJson(route('messages.index', [$this->server, $this->channel]))->assertOk();

        $this->assertSame([$this->fromCarol->id, $last->id], $response->collect('messages')->pluck('id')->all());
        $this->assertNull($response->json('next_cursor'));
    }

    public function test_messages_of_deleted_accounts_are_never_hidden(): void
    {
        $this->block($this->alice, $this->bob);
        $orphan = Message::factory()->inChannel($this->channel)->create(['user_id' => null, 'content' => 'From a deleted account']);

        $this->assertSame([$this->fromCarol->id, $orphan->id], $this->historyIds($this->alice));
    }

    public function test_being_blocked_hides_nothing_from_you(): void
    {
        $this->block($this->bob, $this->alice);

        $this->assertSame([$this->fromBob->id, $this->fromCarol->id], $this->historyIds($this->alice));
    }

    public function test_unblocking_brings_their_messages_back(): void
    {
        $block = $this->block($this->alice, $this->bob);
        $this->assertSame([$this->fromCarol->id], $this->historyIds($this->alice));

        $this->actingAs($this->alice)->delete(route('friends.unblock', $block));

        $this->assertSame([$this->fromBob->id, $this->fromCarol->id], $this->historyIds($this->alice));
    }

    public function test_a_reply_to_a_blocked_message_says_blocked_message_instead_of_quoting_it(): void
    {
        $this->block($this->alice, $this->bob);
        Message::factory()->inChannel($this->channel)->replyTo($this->fromBob)->create(['user_id' => $this->carol->id, 'content' => 'Replying to Bob']);

        $this->actingAs($this->alice)->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk()
            ->assertSee('Replying to Bob')
            ->assertSee('data-reply-blocked', false)
            ->assertSee('Blocked message')
            ->assertDontSee('Bob says spam');

        // Carol still sees the quote.
        $this->actingAs($this->carol)->get(route('channels.show', [$this->server, $this->channel]))
            ->assertSee('Bob says spam')
            ->assertDontSee('data-reply-blocked', false);
    }

    public function test_pins_leave_them_out(): void
    {
        $this->block($this->alice, $this->bob);
        foreach ([$this->fromBob, $this->fromCarol] as $message) {
            $message->forceFill(['pinned_at' => now(), 'pinned_by_id' => $this->alice->id])->save();
        }

        $pins = $this->actingAs($this->alice)->getJson(route('pins.index', [$this->server, $this->channel]))->assertOk()->collect('pins');
        $this->assertSame([$this->fromCarol->id], $pins->pluck('id')->all());

        $this->actingAs($this->alice)->get(route('pins.index', [$this->server, $this->channel]))
            ->assertOk()
            ->assertDontSee('Bob says spam')
            ->assertSee('Carol says hi');

        $this->assertCount(2, $this->actingAs($this->carol)->getJson(route('pins.index', [$this->server, $this->channel]))->json('pins'));
    }

    public function test_search_leaves_them_out(): void
    {
        $this->block($this->alice, $this->bob);

        $this->actingAs($this->alice)->get(route('servers.search', [$this->server, 'q' => 'says']))
            ->assertOk()
            ->assertSee('Carol')
            ->assertDontSee('spam');

        $this->actingAs($this->carol)->get(route('servers.search', [$this->server, 'q' => 'says']))
            ->assertOk()
            ->assertSee('spam');
    }

    public function test_a_dm_with_someone_you_blocked_keeps_its_history(): void
    {
        $conversation = Conversation::factory()->between($this->alice, $this->bob)->create();
        $old = Message::factory()->inConversation($conversation)->create(['user_id' => $this->bob->id, 'content' => 'Before the block']);
        $this->block($this->alice, $this->bob);

        $messages = $this->actingAs($this->alice)->getJson(route('dms.messages.index', $conversation))->assertOk()->collect('messages');
        $this->assertSame([$old->id], $messages->pluck('id')->all());

        $this->actingAs($this->alice)->get(route('dms.show', $conversation))
            ->assertOk()
            ->assertSee('Before the block')
            ->assertSee('data-blocked-user-ids="[]"', false);
    }
}
