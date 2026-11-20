<?php

/**
 * Friends, one-to-one and group DMs, with the realtime
 * broadcasts asserted through Event::fake (Pest).
 */

use App\Events\ConversationActivity;
use App\Events\ConversationUpdated;
use App\Events\DirectMessageReceived;
use App\Events\FriendshipChanged;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Channel as BroadcastChannel;
use Illuminate\Support\Facades\Event;
use Tests\FeatureTestCase;

beforeEach(function () {
    Event::fake([
        MessageSent::class, MessageUpdated::class, MessageDeleted::class,
        ConversationActivity::class, ConversationUpdated::class,
        DirectMessageReceived::class, FriendshipChanged::class,
    ]);
});

/**
 * @return array{User, User}
 */
function aliceAndBob(): array
{
    return [
        User::factory()->create(['username' => 'alice']),
        User::factory()->create(['username' => 'bob']),
    ];
}

function befriend(User $a, User $b): Friendship
{
    return Friendship::factory()->accepted()->create(['user_id' => $a->id, 'friend_id' => $b->id]);
}

function block(User $blocker, User $blocked): void
{
    Friendship::query()->between($blocker, $blocked)->delete();
    Friendship::factory()->blocked()->create(['user_id' => $blocker->id, 'friend_id' => $blocked->id]);
}

function openDm(FeatureTestCase $test, User $from, User $to): Conversation
{
    $id = $test->actingAs($from)->postJson('/dms', ['user_id' => $to->id])->assertOk()->json('conversation_id');

    return Conversation::findOrFail($id);
}

/**
 * The channel names an event broadcasts on, sorted.
 *
 * @param  array<int, BroadcastChannel>  $channels
 * @return list<string>
 */
function channelNames(array $channels): array
{
    $names = array_map(fn (BroadcastChannel $channel): string => $channel->name, array_values($channels));
    sort($names);

    return $names;
}

it('sends a friend request, accepts it and tells the other side each time', function () {
    [$alice, $bob] = aliceAndBob();

    $this->actingAs($alice)->post('/friends', ['username' => 'bob'])->assertRedirect();

    $friendship = Friendship::sole();
    expect($friendship->status)->toBe(Friendship::STATUS_PENDING);
    Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $e) => $e->type === FriendshipChanged::REQUEST
        && $e->recipient->is($bob)
        && $e->broadcastOn()->name === "private-App.Models.User.{$bob->id}");

    // Only the recipient can accept.
    $this->actingAs($alice)->post("/friends/{$friendship->id}/accept");
    expect($friendship->fresh()?->status)->toBe(Friendship::STATUS_PENDING);

    $this->actingAs($bob)->post("/friends/{$friendship->id}/accept")->assertRedirect();
    expect($friendship->fresh()?->status)->toBe(Friendship::STATUS_ACCEPTED);
    Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $e) => $e->type === FriendshipChanged::ACCEPTED
        && $e->recipient->is($alice));
});

it('opens one conversation per pair of friends', function () {
    [$alice, $bob] = aliceAndBob();
    befriend($alice, $bob);

    $first = openDm($this, $alice, $bob);
    $second = openDm($this, $bob, $alice);

    expect($second->id)->toBe($first->id)
        ->and($first->isDirect())->toBeTrue()
        ->and($first->hasParticipant($alice))->toBeTrue()
        ->and($first->hasParticipant($bob))->toBeTrue();

    $this->actingAs($alice)->get("/dms/{$first->id}")->assertOk()->assertSee($bob->name);
});

it('also opens DMs between people who share a server', function () {
    [$alice, $bob] = aliceAndBob();
    memberOf(serverOwnedBy($alice), $bob);

    expect(openDm($this, $alice, $bob)->isDirect())->toBeTrue();
});

it('refuses DMs with strangers, blocked users and yourself', function () {
    [$alice, $bob] = aliceAndBob();
    $carol = User::factory()->create();

    $this->actingAs($alice)->postJson('/dms', ['user_id' => $bob->id])->assertUnprocessable();
    $this->actingAs($alice)->postJson('/dms', ['user_id' => $alice->id])->assertUnprocessable();

    block($carol, $alice);
    $this->actingAs($alice)->postJson('/dms', ['user_id' => $carol->id])->assertUnprocessable();

    expect(Conversation::count())->toBe(0);
});

it('sends a DM and broadcasts it to the conversation and the recipient', function () {
    [$alice, $bob] = aliceAndBob();
    befriend($alice, $bob);
    $conversation = openDm($this, $alice, $bob);

    $this->actingAs($alice)
        ->postJson("/dms/{$conversation->id}/messages", ['content' => 'hi bob'])
        ->assertCreated()
        ->assertJsonPath('message.conversation_id', $conversation->id);

    $message = Message::sole();
    expect($message->channel_id)->toBeNull()
        ->and($conversation->fresh()?->last_message_at)->not->toBeNull();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-conversation.{$conversation->id}");

    Event::assertDispatched(ConversationActivity::class, fn (ConversationActivity $e) => $e->conversation->is($conversation)
        && channelNames($e->broadcastOn()) === ["private-App.Models.User.{$alice->id}", "private-App.Models.User.{$bob->id}"]
        && $e->broadcastWith()['conversation_id'] === $conversation->id);

    // Only the recipient is notified, never the author.
    Event::assertDispatchedTimes(DirectMessageReceived::class, 1);
    Event::assertDispatched(DirectMessageReceived::class, fn (DirectMessageReceived $e) => channelNames($e->broadcastOn()) === ["private-App.Models.User.{$bob->id}"]);
});

it('stops DMs once the recipient blocks the author', function () {
    [$alice, $bob] = aliceAndBob();
    befriend($alice, $bob);
    $conversation = openDm($this, $alice, $bob);
    block($bob, $alice);

    $this->actingAs($alice)
        ->postJson("/dms/{$conversation->id}/messages", ['content' => 'hello?'])
        ->assertForbidden();

    Event::assertNotDispatched(MessageSent::class);
    Event::assertNotDispatched(DirectMessageReceived::class);
});

it('hides a conversation from outsiders as a 404', function () {
    [$alice, $bob] = aliceAndBob();
    befriend($alice, $bob);
    $conversation = openDm($this, $alice, $bob);
    $eve = User::factory()->create();

    // A 404, not a 403, so conversation ids do not tell who talks to whom.
    $this->actingAs($eve)->get("/dms/{$conversation->id}")->assertNotFound();
    $this->actingAs($eve)->getJson("/dms/{$conversation->id}/messages")->assertNotFound();
    $this->actingAs($eve)->postJson("/dms/{$conversation->id}/messages", ['content' => 'hi'])->assertNotFound();

    Event::assertNotDispatched(MessageSent::class);
});

it('edits and deletes DMs and broadcasts both on the conversation', function () {
    [$alice, $bob] = aliceAndBob();
    befriend($alice, $bob);
    $conversation = openDm($this, $alice, $bob);
    $message = $conversation->messages()->create(['user_id' => $alice->id, 'content' => 'helo']);
    $url = "/dms/{$conversation->id}/messages/{$message->id}";

    $this->actingAs($bob)->patchJson($url, ['content' => 'nope'])->assertForbidden();
    $this->actingAs($bob)->deleteJson($url)->assertForbidden();

    $this->actingAs($alice)->patchJson($url, ['content' => 'hello'])->assertOk();
    $this->actingAs($alice)->deleteJson($url)->assertNoContent();

    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $e) => $e->broadcastOn()->name === "private-conversation.{$conversation->id}");
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e) => $e->broadcastWith() === [
        'id' => $message->id, 'channel_id' => null, 'conversation_id' => $conversation->id,
    ]);
});

it('creates a group DM with friends and tells every member', function () {
    [$alice, $bob] = aliceAndBob();
    $carol = User::factory()->create();
    befriend($alice, $bob);
    befriend($alice, $carol);

    $this->actingAs($alice)
        ->postJson('/dms/groups', ['user_ids' => [$bob->id, $carol->id], 'name' => 'Trio'])
        ->assertCreated();

    $group = Conversation::sole();
    expect($group->isGroup())->toBeTrue()
        ->and($group->users()->count())->toBe(3)
        ->and($group->isOwnedBy($alice))->toBeTrue();

    $this->actingAs($carol)->postJson("/dms/{$group->id}/messages", ['content' => 'hey all'])->assertCreated();
    Event::assertDispatchedTimes(DirectMessageReceived::class, 2);

    // Only the owner removes people; the removed person hears about it too.
    $this->actingAs($bob)->deleteJson("/dms/{$group->id}/participants/{$carol->id}")->assertForbidden();
    $this->actingAs($alice)->deleteJson("/dms/{$group->id}/participants/{$carol->id}")->assertSuccessful();

    expect($group->fresh()?->hasParticipant($carol))->toBeFalse();
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => in_array(
        "private-App.Models.User.{$carol->id}",
        channelNames($e->broadcastOn()),
        true,
    ));
});
