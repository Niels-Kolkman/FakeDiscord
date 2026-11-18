<?php

namespace Tests\Feature\Friends;

use App\Events\FriendshipChanged;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ISSUE-25: blocking someone (POST /friends/block, App\Actions\Friends\BlockUser)
 * ends any request or friendship between the two, stops friend requests
 * and DMs both ways, and never tells the blocked person about it.
 */
class BlockUserTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'username' => 'bob']);

        Event::fake([FriendshipChanged::class]);
    }

    private function friendship(User $from, User $to, string $status): Friendship
    {
        return Friendship::factory()->create([
            'user_id' => $from->id,
            'friend_id' => $to->id,
            'status' => $status,
            'accepted_at' => $status === Friendship::STATUS_ACCEPTED ? now() : null,
        ]);
    }

    private function assertBlocked(User $blocker, User $blocked): void
    {
        $this->assertDatabaseHas('friendships', [
            'user_id' => $blocker->id,
            'friend_id' => $blocked->id,
            'status' => Friendship::STATUS_BLOCKED,
            'accepted_at' => null,
        ]);
    }

    // --- Blocking ------------------------------------------------------------------

    public function test_blocking_someone_creates_a_block_and_lists_them_on_the_blocked_tab(): void
    {
        $this->actingAs($this->alice)
            ->post('/friends/block', ['user_id' => $this->bob->id])
            ->assertRedirect(route('friends.index', ['tab' => 'blocked']))
            ->assertSessionHas('status', 'Bob is blocked.');

        $this->assertBlocked($this->alice, $this->bob);
        $this->assertDatabaseCount('friendships', 1);
        Event::assertNotDispatched(FriendshipChanged::class);

        $this->actingAs($this->alice)->get('/friends?tab=blocked')
            ->assertOk()
            ->assertSee('aria-label="Unblock Bob"', false);
    }

    public function test_blocking_answers_json(): void
    {
        $response = $this->actingAs($this->alice)->postJson('/friends/block', ['user_id' => $this->bob->id]);

        $block = Friendship::query()->firstOrFail();
        $response->assertOk()->assertExactJson([
            'friendship_id' => $block->id,
            'changed' => true,
            'status' => 'Bob is blocked.',
        ]);
    }

    public function test_blocking_someone_twice_changes_nothing(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->postJson('/friends/block', ['user_id' => $this->bob->id])
            ->assertOk()
            ->assertExactJson(['friendship_id' => $block->id, 'changed' => false, 'status' => null]);

        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_blocking_a_friend_ends_the_friendship_and_looks_like_a_removal_to_them(): void
    {
        // Bob sent the request, so the row flips direction: Alice is the blocker.
        $friendship = $this->friendship($this->bob, $this->alice, Friendship::STATUS_ACCEPTED);

        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id])->assertRedirect();

        $this->assertDatabaseCount('friendships', 1);
        $this->assertDatabaseHas('friendships', [
            'id' => $friendship->id,
            'user_id' => $this->alice->id,
            'friend_id' => $this->bob->id,
            'status' => Friendship::STATUS_BLOCKED,
            'accepted_at' => null,
        ]);

        Event::assertDispatchedTimes(FriendshipChanged::class, 1);
        Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $event): bool => $event->type === FriendshipChanged::REMOVED
            && $event->friendshipId === $friendship->id
            && $event->actor->is($this->alice)
            && $event->recipient->is($this->bob));

        $this->actingAs($this->bob)->get('/friends')->assertOk()->assertDontSee('aria-label="Remove Alice from your friends"', false);
    }

    public function test_blocking_ends_a_pending_request_either_way(): void
    {
        $incoming = $this->friendship($this->bob, $this->alice, Friendship::STATUS_PENDING);
        $carol = User::factory()->create(['name' => 'Carol']);
        $outgoing = $this->friendship($this->alice, $carol, Friendship::STATUS_PENDING);

        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $carol->id]);

        $this->assertBlocked($this->alice, $this->bob);
        $this->assertBlocked($this->alice, $carol);
        $this->assertSame(0, Friendship::incomingCount($this->alice));
        $this->assertSame([$incoming->id, $outgoing->id], Friendship::query()->orderBy('id')->pluck('id')->all());
        Event::assertDispatchedTimes(FriendshipChanged::class, 2);
    }

    public function test_you_can_block_someone_who_blocked_you_and_each_block_is_lifted_on_its_own(): void
    {
        $theirs = $this->friendship($this->bob, $this->alice, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->postJson('/friends/block', ['user_id' => $this->bob->id])
            ->assertOk()
            ->assertJsonPath('changed', true);

        $this->assertDatabaseCount('friendships', 2);
        $this->assertBlocked($this->alice, $this->bob);
        Event::assertNotDispatched(FriendshipChanged::class);

        // Bob lifts his block; Alice's stays.
        $this->actingAs($this->bob)->deleteJson(route('friends.unblock', $theirs))->assertOk();

        $this->assertDatabaseCount('friendships', 1);
        $this->assertBlocked($this->alice, $this->bob);
        $this->actingAs($this->bob)->post('/friends', ['username' => 'alice'])
            ->assertSessionHasErrors(['username' => "That didn't work. Double-check the username and try again."]);
    }

    public function test_with_a_mutual_block_each_side_is_told_about_their_own_block(): void
    {
        $this->friendship($this->bob, $this->alice, Friendship::STATUS_BLOCKED);
        $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->post('/friends', ['username' => 'bob'])
            ->assertSessionHasErrors(['username' => 'You blocked Bob. Unblock them before sending a friend request.']);
        $this->actingAs($this->bob)->post('/friends', ['username' => 'alice'])
            ->assertSessionHasErrors(['username' => 'You blocked Alice. Unblock them before sending a friend request.']);
    }

    public function test_you_cannot_block_yourself_or_someone_who_does_not_exist(): void
    {
        $this->actingAs($this->alice)->from('/friends')->post('/friends/block', ['user_id' => $this->alice->id])
            ->assertRedirect('/friends')
            ->assertSessionHasErrors(['user' => 'You cannot block yourself.']);

        $this->actingAs($this->alice)->postJson('/friends/block', ['user_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'That user does not exist.']);

        $this->actingAs($this->alice)->postJson('/friends/block', ['user_id' => 'bob'])
            ->assertJsonValidationErrors('user_id');
        $this->actingAs($this->alice)->postJson('/friends/block', [])
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_guests_and_unverified_users_cannot_block(): void
    {
        $this->post('/friends/block', ['user_id' => $this->bob->id])->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->post('/friends/block', ['user_id' => $this->bob->id])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('friendships', 0);
    }

    // --- What a block stops ----------------------------------------------------------

    public function test_the_blocked_user_cannot_send_a_friend_request_and_gets_the_unknown_user_error(): void
    {
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);

        $this->actingAs($this->bob)->post('/friends', ['username' => 'alice'])
            ->assertSessionHasErrors(['username' => "That didn't work. Double-check the username and try again."]);
        $this->actingAs($this->alice)->post('/friends', ['username' => 'bob'])
            ->assertSessionHasErrors(['username' => 'You blocked Bob. Unblock them before sending a friend request.']);

        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_nobody_can_open_a_new_dm_across_a_block(): void
    {
        $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);

        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$from, $to]) {
            $this->actingAs($from)->postJson('/dms', ['user_id' => $to->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user' => 'You can only send direct messages to friends and people you share a server with.']);
        }

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_an_existing_dm_stays_readable_but_nobody_can_send_in_it(): void
    {
        $conversation = Conversation::factory()->between($this->alice, $this->bob)->create();
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);

        foreach ([$this->alice, $this->bob] as $user) {
            $this->actingAs($user)->getJson(route('dms.messages.index', $conversation))->assertOk();
            $this->actingAs($user)->postJson(route('dms.messages.store', $conversation), ['content' => 'Hello?'])->assertForbidden();
        }

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_unblocking_lets_them_send_friend_requests_again(): void
    {
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);
        $block = Friendship::query()->firstOrFail();

        $this->actingAs($this->alice)->delete(route('friends.unblock', $block))->assertRedirect();
        $this->actingAs($this->bob)->post('/friends', ['username' => 'alice'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('friendships', ['user_id' => $this->bob->id, 'friend_id' => $this->alice->id, 'status' => Friendship::STATUS_PENDING]);
    }

    // --- Where you block from -------------------------------------------------------

    public function test_the_friends_lists_offer_block_but_the_blocked_list_does_not(): void
    {
        $carol = User::factory()->create(['name' => 'Carol']);
        $dave = User::factory()->create(['name' => 'Dave']);
        $eve = User::factory()->create(['name' => 'Eve']);
        $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);
        $this->friendship($carol, $this->alice, Friendship::STATUS_PENDING);
        $this->friendship($this->alice, $dave, Friendship::STATUS_PENDING);
        $this->friendship($this->alice, $eve, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->get('/friends')
            ->assertSee('action="'.route('friends.block').'"', false)
            ->assertSee('name="user_id" value="'.$this->bob->id.'"', false)
            ->assertSee('aria-label="Block Bob"', false);

        $this->actingAs($this->alice)->get('/friends?tab=pending')
            ->assertSee('aria-label="Block Carol"', false)
            ->assertSee('aria-label="Block Dave"', false);

        $this->actingAs($this->alice)->get('/friends?tab=blocked')
            ->assertSee('aria-label="Unblock Eve"', false)
            ->assertDontSee('aria-label="Block Eve"', false);
    }

    public function test_the_profile_card_says_whether_you_blocked_them_but_never_whether_they_blocked_you(): void
    {
        $this->actingAs($this->alice)->post('/friends/block', ['user_id' => $this->bob->id]);
        $block = Friendship::query()->firstOrFail();

        $this->actingAs($this->alice)->getJson('/users/bob/profile')
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('is_self', false)
            ->assertJsonPath('block_url', route('friends.block'))
            ->assertJsonPath('unblock_url', route('friends.unblock', $block));

        $this->actingAs($this->bob)->getJson('/users/alice/profile')
            ->assertOk()
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('unblock_url', null);
    }

    public function test_your_own_profile_card_has_no_block_button(): void
    {
        $this->actingAs($this->alice)->getJson('/users/alice/profile')
            ->assertOk()
            ->assertJsonPath('is_self', true)
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('block_url', null)
            ->assertJsonPath('unblock_url', null);
    }
}
