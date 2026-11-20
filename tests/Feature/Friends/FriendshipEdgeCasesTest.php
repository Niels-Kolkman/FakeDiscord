<?php

namespace Tests\Feature\Friends;

use App\Actions\Friends\ManageFriendship;
use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Edge cases around friend requests that FriendRequestTest and
 * FriendsPageTest do not reach: input limits, the duplicate-insert race,
 * the action-level guards, the model scopes, the counts carried by
 * FriendshipChanged, and the lifecycle after a request is gone.
 */
class FriendshipEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'username' => 'bob']);
        $this->carol = User::factory()->create(['name' => 'Carol', 'username' => 'carol']);

        Event::fake([FriendshipChanged::class]);
    }

    private function friendship(User $from, User $to, string $status = Friendship::STATUS_PENDING): Friendship
    {
        return Friendship::factory()->create([
            'user_id' => $from->id,
            'friend_id' => $to->id,
            'status' => $status,
            'accepted_at' => $status === Friendship::STATUS_ACCEPTED ? now() : null,
        ]);
    }

    private function dispatched(string $type): FriendshipChanged
    {
        $events = Event::dispatched(FriendshipChanged::class, fn (FriendshipChanged $event): bool => $event->type === $type);
        $this->assertCount(1, $events, "one {$type} event");

        return $events->first()[0];
    }

    // --- Input -------------------------------------------------------------------

    public function test_a_username_longer_than_64_characters_is_rejected(): void
    {
        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => str_repeat('a', 65)])
            ->assertRedirect('/friends')
            ->assertSessionHasErrors('username');

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_a_username_that_is_not_a_string_is_rejected(): void
    {
        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => ['bob']])
            ->assertSessionHasErrors('username');

        $this->actingAs($this->alice)->postJson('/friends', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username' => 'Enter a username.']);

        $this->assertDatabaseCount('friendships', 0);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_spaces_inside_a_username_are_not_ignored(): void
    {
        // Only the ends are trimmed: "bo b" is not "bob".
        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => 'bo b'])
            ->assertSessionHasErrors(['username' => "That didn't work. Double-check the username and try again."]);

        $this->assertDatabaseCount('friendships', 0);
    }

    // --- Two clicks at once ----------------------------------------------------------

    public function test_a_request_inserted_by_a_parallel_click_gives_the_already_sent_error(): void
    {
        // Simulate the other click winning the race: the same row appears
        // between the lookup and the insert, so the unique index fires.
        Friendship::creating(function (Friendship $friendship): void {
            DB::table('friendships')->insert([
                'user_id' => $friendship->user_id,
                'friend_id' => $friendship->friend_id,
                'status' => Friendship::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => 'bob'])
            ->assertRedirect('/friends')
            ->assertSessionHasErrors(['username' => 'You already sent Bob a friend request.']);

        Event::assertNotDispatched(FriendshipChanged::class);
    }

    // --- Action guards (without the policy) -----------------------------------------

    public function test_removing_twice_changes_and_broadcasts_once(): void
    {
        $friendship = $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);
        $stale = Friendship::query()->findOrFail($friendship->id);
        $action = new ManageFriendship;

        $this->assertTrue($action->remove($this->alice, $friendship));
        $this->assertFalse($action->remove($this->bob, $stale));

        $this->assertModelMissing($friendship);
        Event::assertDispatchedTimes(FriendshipChanged::class, 1);
    }

    public function test_a_stranger_cannot_change_a_friendship_through_the_action(): void
    {
        $request = $this->friendship($this->alice, $this->bob);
        $friendship = $this->friendship($this->alice, $this->carol, Friendship::STATUS_ACCEPTED);
        $stranger = User::factory()->create();
        $action = new ManageFriendship;

        $this->assertFalse($action->accept($stranger, $request));
        $this->assertFalse($action->decline($stranger, $request));
        $this->assertFalse($action->cancel($stranger, $request));
        $this->assertFalse($action->remove($stranger, $friendship));

        $this->assertTrue($request->refresh()->isPending());
        $this->assertTrue($friendship->refresh()->isAccepted());
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_only_the_blocker_can_lift_a_block_through_the_action(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);
        $action = new ManageFriendship;

        $this->assertFalse($action->unblock($this->bob, $block));
        $this->assertFalse($action->remove($this->bob, $block), 'a block is not a friendship');
        $this->assertModelExists($block);

        $this->assertTrue($action->unblock($this->alice, $block));
        $this->assertFalse($action->unblock($this->alice, $block), 'already gone');
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_the_actions_only_touch_the_given_row(): void
    {
        $mine = $this->friendship($this->alice, $this->bob);
        $other = $this->friendship($this->carol, $this->bob);

        (new ManageFriendship)->decline($this->bob, $mine);

        $this->assertModelMissing($mine);
        $this->assertModelExists($other);
    }

    // --- The blocked user never learns about the block --------------------------------

    public function test_the_blocked_user_gets_a_404_for_decline_and_cancel_too(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->bob)->post("/friends/{$block->id}/decline")->assertNotFound();
        $this->actingAs($this->bob)->delete("/friends/{$block->id}/request")->assertNotFound();

        $this->assertModelExists($block);
    }

    public function test_the_blocker_gets_a_403_for_request_actions_on_a_block(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->post("/friends/{$block->id}/accept")->assertForbidden();
        $this->actingAs($this->alice)->delete("/friends/{$block->id}/request")->assertForbidden();
        $this->actingAs($this->alice)->delete("/friends/{$block->id}")->assertForbidden();

        $this->assertModelExists($block);
    }

    // --- Lifecycle ---------------------------------------------------------------------

    public function test_after_a_decline_the_sender_may_ask_again(): void
    {
        $request = $this->friendship($this->alice, $this->bob);
        $this->actingAs($this->bob)->post("/friends/{$request->id}/decline");

        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => 'bob'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/friends?tab=pending');

        $this->assertDatabaseHas('friendships', ['user_id' => $this->alice->id, 'friend_id' => $this->bob->id, 'status' => Friendship::STATUS_PENDING]);
    }

    public function test_after_an_unblock_either_side_may_send_a_request(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);
        $this->actingAs($this->alice)->delete("/friends/{$block->id}/block");

        $this->actingAs($this->bob)->from('/friends')->post('/friends', ['username' => 'alice'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('friendships', ['user_id' => $this->bob->id, 'friend_id' => $this->alice->id, 'status' => Friendship::STATUS_PENDING]);
    }

    public function test_answering_their_request_leaves_other_requests_alone(): void
    {
        $theirs = $this->friendship($this->bob, $this->alice);
        $carols = $this->friendship($this->carol, $this->alice);

        $this->actingAs($this->alice)->from('/friends')->post('/friends', ['username' => 'bob']);

        $this->assertTrue($theirs->refresh()->isAccepted());
        $this->assertTrue($carols->refresh()->isPending());
    }

    // --- JSON replies -------------------------------------------------------------------

    public function test_decline_cancel_remove_and_unblock_answer_json(): void
    {
        $incoming = $this->friendship($this->bob, $this->alice);
        $outgoing = $this->friendship($this->alice, $this->carol);
        $dave = User::factory()->create(['name' => 'Dave', 'username' => 'dave']);
        $friendship = $this->friendship($dave, $this->alice, Friendship::STATUS_ACCEPTED);
        $eve = User::factory()->create(['name' => 'Eve', 'username' => 'eve']);
        $block = $this->friendship($this->alice, $eve, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice);

        $this->postJson("/friends/{$incoming->id}/decline")
            ->assertExactJson(['friendship_id' => $incoming->id, 'changed' => true, 'status' => 'Friend request from Bob declined.']);
        $this->deleteJson("/friends/{$outgoing->id}/request")
            ->assertExactJson(['friendship_id' => $outgoing->id, 'changed' => true, 'status' => 'Friend request to Carol cancelled.']);
        $this->deleteJson("/friends/{$friendship->id}")
            ->assertExactJson(['friendship_id' => $friendship->id, 'changed' => true, 'status' => 'Dave was removed from your friends.']);
        $this->deleteJson("/friends/{$block->id}/block")
            ->assertExactJson(['friendship_id' => $block->id, 'changed' => true, 'status' => 'Eve is unblocked.']);

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_a_request_answered_back_says_so_in_json(): void
    {
        $this->friendship($this->bob, $this->alice);

        $this->actingAs($this->alice)->postJson('/friends', ['username' => '@Bob'])
            ->assertOk()
            ->assertJson(['changed' => true, 'status' => 'You and Bob are now friends.']);
    }

    // --- FriendshipChanged counts ---------------------------------------------------------

    public function test_the_request_event_counts_the_new_request(): void
    {
        $this->friendship($this->carol, $this->bob);

        $this->actingAs($this->alice)->post('/friends', ['username' => 'bob']);

        $event = $this->dispatched(FriendshipChanged::REQUEST);
        $this->assertTrue($event->recipient->is($this->bob));
        $this->assertSame(2, $event->broadcastWith()['incoming_count']);
        $this->assertSame('alice', $event->broadcastWith()['user']['username']);
    }

    public function test_the_accepted_event_carries_the_senders_own_count(): void
    {
        // Alice asked Bob; Carol is still waiting on Alice.
        $request = $this->friendship($this->alice, $this->bob);
        $this->friendship($this->carol, $this->alice);

        $this->actingAs($this->bob)->post("/friends/{$request->id}/accept");

        $event = $this->dispatched(FriendshipChanged::ACCEPTED);
        $this->assertTrue($event->recipient->is($this->alice));
        $this->assertSame($request->id, $event->friendshipId);
        $this->assertSame(1, $event->broadcastWith()['incoming_count']);
    }

    public function test_the_removed_event_after_a_cancel_lowers_the_recipients_count(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->alice)->delete("/friends/{$request->id}/request");

        $event = $this->dispatched(FriendshipChanged::REMOVED);
        $this->assertTrue($event->recipient->is($this->bob));
        $this->assertSame(0, $event->broadcastWith()['incoming_count']);
    }

    // --- Model ------------------------------------------------------------------------------

    public function test_between_finds_the_pair_in_either_direction_only(): void
    {
        $pair = $this->friendship($this->alice, $this->bob);
        $this->friendship($this->alice, $this->carol);
        $this->friendship($this->carol, $this->bob);

        $this->assertSame([$pair->id], Friendship::query()->between($this->alice, $this->bob)->pluck('id')->all());
        $this->assertSame([$pair->id], Friendship::query()->between($this->bob, $this->alice)->pluck('id')->all());
    }

    public function test_involving_finds_rows_on_either_side(): void
    {
        $sent = $this->friendship($this->alice, $this->bob);
        $received = $this->friendship($this->carol, $this->alice);
        $this->friendship($this->bob, $this->carol);

        $this->assertEqualsCanonicalizing(
            [$sent->id, $received->id],
            Friendship::query()->involving($this->alice)->pluck('id')->all(),
        );
    }

    public function test_other_user_and_direction_helpers(): void
    {
        $friendship = $this->friendship($this->alice, $this->bob);

        $this->assertTrue($friendship->otherUser($this->alice)->is($this->bob));
        $this->assertTrue($friendship->otherUser($this->bob)->is($this->alice));
        $this->assertTrue($friendship->sentBy($this->alice));
        $this->assertFalse($friendship->sentBy($this->bob));
        $this->assertTrue($friendship->receivedBy($this->bob));
        $this->assertFalse($friendship->involves($this->carol));
    }

    public function test_incoming_count_ignores_outgoing_accepted_and_blocked_rows(): void
    {
        $this->friendship($this->bob, $this->alice);                                            // counts
        $this->friendship($this->alice, $this->carol);                                          // outgoing
        $this->friendship(User::factory()->create(), $this->alice, Friendship::STATUS_ACCEPTED); // a friend
        $this->friendship(User::factory()->create(), $this->alice, Friendship::STATUS_BLOCKED);  // blocked Alice

        $this->assertSame(1, Friendship::incomingCount($this->alice));
        $this->assertSame(1, Friendship::incomingCount($this->carol));
        $this->assertSame(0, Friendship::incomingCount($this->bob));
    }

    // --- Page ---------------------------------------------------------------------------------

    public function test_friends_with_the_same_name_are_ordered_by_username(): void
    {
        $second = User::factory()->create(['name' => 'Sam', 'username' => 'samz']);
        $first = User::factory()->create(['name' => 'sam', 'username' => 'sama']);
        $this->friendship($this->alice, $second, Friendship::STATUS_ACCEPTED);
        $this->friendship($first, $this->alice, Friendship::STATUS_ACCEPTED);

        $this->actingAs($this->alice)->get('/friends')
            ->assertOk()
            ->assertSeeInOrder(['@sama', '@samz']);
    }

    public function test_requests_sent_at_the_same_moment_are_newest_id_first(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->friendship($this->bob, $this->alice);
        $this->friendship($this->carol, $this->alice);

        $this->actingAs($this->alice)->get('/friends?tab=pending')
            ->assertOk()
            ->assertSeeInOrder(['Incoming — 2', 'Carol', 'Bob']);
    }

    public function test_names_are_escaped_on_the_page(): void
    {
        $mallory = User::factory()->create(['name' => '<script>alert(1)</script>', 'username' => 'mallory']);
        $this->friendship($mallory, $this->alice);

        $this->actingAs($this->alice)->get('/friends?tab=pending')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
