<?php

namespace Tests\Feature\Friends;

use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Sending a friend request by username, and accepting,
 * declining, cancelling, removing and unblocking. The other person hears
 * about each change through FriendshipChanged on "App.Models.User.{id}".
 */
class FriendRequestTest extends TestCase
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

    /**
     * @return TestResponse<Response>
     */
    private function sendRequest(User $from, string $username): TestResponse
    {
        return $this->actingAs($from)->from('/friends')->post('/friends', ['username' => $username]);
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

    private function assertBroadcast(string $type, User $actor, User $recipient): void
    {
        Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $event): bool => $event->type === $type
            && $event->actor->is($actor)
            && $event->recipient->is($recipient));
    }

    // --- Sending ---------------------------------------------------------

    public function test_a_user_can_send_a_friend_request_by_username(): void
    {
        $this->sendRequest($this->alice, 'bob')
            ->assertRedirect('/friends?tab=pending')
            ->assertSessionHas('status', 'Friend request sent to Bob.');

        $this->assertDatabaseHas('friendships', [
            'user_id' => $this->alice->id,
            'friend_id' => $this->bob->id,
            'status' => Friendship::STATUS_PENDING,
            'accepted_at' => null,
        ]);

        $this->assertBroadcast(FriendshipChanged::REQUEST, $this->alice, $this->bob);
        Event::assertDispatchedTimes(FriendshipChanged::class, 1);
    }

    public function test_the_username_may_have_an_at_sign_capitals_and_spaces(): void
    {
        $this->sendRequest($this->alice, '  @BoB ')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('friendships', ['user_id' => $this->alice->id, 'friend_id' => $this->bob->id]);
    }

    public function test_an_unknown_username_is_rejected(): void
    {
        $this->sendRequest($this->alice, 'nobody')
            ->assertRedirect('/friends')
            ->assertSessionHasErrors(['username' => "That didn't work. Double-check the username and try again."]);

        $this->assertDatabaseCount('friendships', 0);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_the_username_is_required(): void
    {
        $this->sendRequest($this->alice, '')->assertSessionHasErrors(['username' => 'Enter a username.']);
        $this->sendRequest($this->alice, '@')->assertSessionHasErrors('username');

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_you_cannot_send_a_request_to_yourself(): void
    {
        $this->sendRequest($this->alice, 'alice')
            ->assertSessionHasErrors(['username' => 'You cannot send a friend request to yourself.']);

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_a_second_request_to_the_same_user_is_rejected(): void
    {
        $this->friendship($this->alice, $this->bob);

        $this->sendRequest($this->alice, 'bob')
            ->assertSessionHasErrors(['username' => 'You already sent Bob a friend request.']);

        $this->assertDatabaseCount('friendships', 1);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_a_request_to_a_friend_is_rejected_in_either_direction(): void
    {
        $this->friendship($this->bob, $this->alice, Friendship::STATUS_ACCEPTED);

        $this->sendRequest($this->alice, 'bob')
            ->assertSessionHasErrors(['username' => 'You are already friends with Bob.']);
        $this->sendRequest($this->bob, 'alice')
            ->assertSessionHasErrors(['username' => 'You are already friends with Alice.']);

        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_sending_a_request_back_accepts_theirs(): void
    {
        $theirs = $this->friendship($this->bob, $this->alice);

        $this->sendRequest($this->alice, 'bob')
            ->assertRedirect('/friends?tab=all')
            ->assertSessionHas('status', 'You and Bob are now friends.');

        $this->assertDatabaseCount('friendships', 1);
        $theirs->refresh();
        $this->assertTrue($theirs->isAccepted());
        $this->assertNotNull($theirs->accepted_at);
        $this->assertTrue($theirs->sentBy($this->bob), 'the original direction is kept');

        $this->assertBroadcast(FriendshipChanged::ACCEPTED, $this->alice, $this->bob);
    }

    public function test_someone_who_blocked_you_looks_like_an_unknown_username(): void
    {
        $this->friendship($this->bob, $this->alice, Friendship::STATUS_BLOCKED);

        $this->sendRequest($this->alice, 'bob')
            ->assertSessionHasErrors(['username' => "That didn't work. Double-check the username and try again."]);

        $this->assertDatabaseHas('friendships', ['user_id' => $this->bob->id, 'status' => Friendship::STATUS_BLOCKED]);
        $this->assertDatabaseCount('friendships', 1);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_you_must_unblock_someone_before_sending_them_a_request(): void
    {
        $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->sendRequest($this->alice, 'bob')
            ->assertSessionHasErrors(['username' => 'You blocked Bob. Unblock them before sending a friend request.']);

        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_sending_answers_json_when_asked(): void
    {
        $this->actingAs($this->alice)->postJson('/friends', ['username' => 'bob'])
            ->assertOk()
            ->assertJson(['changed' => true, 'status' => 'Friend request sent to Bob.']);

        $this->actingAs($this->alice)->postJson('/friends', ['username' => 'bob'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_guests_and_unverified_users_cannot_send_requests(): void
    {
        $this->post('/friends', ['username' => 'bob'])->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->post('/friends', ['username' => 'bob'])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('friendships', 0);
    }

    // --- Accept and decline ----------------------------------------------

    public function test_the_recipient_can_accept_a_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->bob)->post("/friends/{$request->id}/accept")
            ->assertRedirect('/friends?tab=pending')
            ->assertSessionHas('status', 'You and Alice are now friends.');

        $request->refresh();
        $this->assertTrue($request->isAccepted());
        $this->assertNotNull($request->accepted_at);

        $this->assertBroadcast(FriendshipChanged::ACCEPTED, $this->bob, $this->alice);
    }

    public function test_the_sender_cannot_accept_their_own_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->alice)->post("/friends/{$request->id}/accept")->assertForbidden();

        $this->assertTrue($request->refresh()->isPending());
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_the_recipient_can_decline_a_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->bob)->post("/friends/{$request->id}/decline")
            ->assertRedirect('/friends?tab=pending')
            ->assertSessionHas('status', 'Friend request from Alice declined.');

        $this->assertModelMissing($request);
        $this->assertBroadcast(FriendshipChanged::REMOVED, $this->bob, $this->alice);
    }

    public function test_the_sender_cannot_decline_their_own_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->alice)->post("/friends/{$request->id}/decline")->assertForbidden();

        $this->assertModelExists($request);
    }

    public function test_an_accepted_friendship_cannot_be_accepted_or_declined_again(): void
    {
        $friendship = $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);

        $this->actingAs($this->bob)->post("/friends/{$friendship->id}/accept")->assertForbidden();
        $this->actingAs($this->bob)->post("/friends/{$friendship->id}/decline")->assertForbidden();

        $this->assertTrue($friendship->refresh()->isAccepted());
    }

    // --- Cancel ------------------------------------------------------------

    public function test_the_sender_can_cancel_a_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->alice)->delete("/friends/{$request->id}/request")
            ->assertRedirect('/friends?tab=pending')
            ->assertSessionHas('status', 'Friend request to Bob cancelled.');

        $this->assertModelMissing($request);
        $this->assertBroadcast(FriendshipChanged::REMOVED, $this->alice, $this->bob);
    }

    public function test_the_recipient_cannot_cancel_a_request(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->bob)->delete("/friends/{$request->id}/request")->assertForbidden();

        $this->assertModelExists($request);
    }

    // --- Remove ------------------------------------------------------------

    public function test_either_friend_can_remove_the_friendship(): void
    {
        $first = $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);
        $second = $this->friendship($this->carol, $this->alice, Friendship::STATUS_ACCEPTED);

        $this->actingAs($this->alice)->delete("/friends/{$first->id}")
            ->assertRedirect('/friends?tab=all')
            ->assertSessionHas('status', 'Bob was removed from your friends.');
        $this->actingAs($this->alice)->delete("/friends/{$second->id}")
            ->assertSessionHas('status', 'Carol was removed from your friends.');

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertBroadcast(FriendshipChanged::REMOVED, $this->alice, $this->bob);
        $this->assertBroadcast(FriendshipChanged::REMOVED, $this->alice, $this->carol);
    }

    public function test_a_pending_request_cannot_be_removed_as_a_friendship(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->alice)->delete("/friends/{$request->id}")->assertForbidden();

        $this->assertModelExists($request);
    }

    public function test_after_removing_a_friend_you_can_send_a_new_request(): void
    {
        $friendship = $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);
        $this->actingAs($this->bob)->delete("/friends/{$friendship->id}");

        $this->sendRequest($this->bob, 'alice')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('friendships', ['user_id' => $this->bob->id, 'friend_id' => $this->alice->id, 'status' => Friendship::STATUS_PENDING]);
    }

    // --- Unblock -------------------------------------------------------------

    public function test_the_blocker_can_unblock_without_telling_anyone(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->alice)->delete("/friends/{$block->id}/block")
            ->assertRedirect('/friends?tab=blocked')
            ->assertSessionHas('status', 'Bob is unblocked.');

        $this->assertModelMissing($block);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_the_blocked_user_cannot_see_or_touch_the_block(): void
    {
        $block = $this->friendship($this->alice, $this->bob, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->bob)->delete("/friends/{$block->id}/block")->assertNotFound();
        $this->actingAs($this->bob)->delete("/friends/{$block->id}")->assertNotFound();
        $this->actingAs($this->bob)->post("/friends/{$block->id}/accept")->assertNotFound();

        $this->assertModelExists($block);
    }

    public function test_unblock_only_applies_to_blocks(): void
    {
        $friendship = $this->friendship($this->alice, $this->bob, Friendship::STATUS_ACCEPTED);

        $this->actingAs($this->alice)->delete("/friends/{$friendship->id}/block")->assertForbidden();

        $this->assertModelExists($friendship);
    }

    // --- Strangers and unknown ids -------------------------------------------

    public function test_someone_outside_the_friendship_gets_a_404_for_every_action(): void
    {
        $request = $this->friendship($this->alice, $this->bob);
        $friendship = $this->friendship($this->alice, $this->carol, Friendship::STATUS_ACCEPTED);

        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->post("/friends/{$request->id}/accept")->assertNotFound();
        $this->post("/friends/{$request->id}/decline")->assertNotFound();
        $this->delete("/friends/{$request->id}/request")->assertNotFound();
        $this->delete("/friends/{$friendship->id}")->assertNotFound();
        $this->delete("/friends/{$friendship->id}/block")->assertNotFound();

        $this->assertModelExists($request);
        $this->assertModelExists($friendship);
        Event::assertNotDispatched(FriendshipChanged::class);
    }

    public function test_an_unknown_friendship_is_a_404(): void
    {
        $this->actingAs($this->alice)->post('/friends/999/accept')->assertNotFound();
        $this->actingAs($this->alice)->delete('/friends/abc')->assertNotFound();
    }

    public function test_actions_answer_json_when_asked(): void
    {
        $request = $this->friendship($this->alice, $this->bob);

        $this->actingAs($this->bob)->postJson("/friends/{$request->id}/accept")
            ->assertOk()
            ->assertExactJson(['friendship_id' => $request->id, 'changed' => true, 'status' => 'You and Alice are now friends.']);
    }
}
