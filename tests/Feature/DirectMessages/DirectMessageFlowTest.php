<?php

/**
 * ISSUE-39: friends, one-to-one and group DMs, with the realtime
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
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([
        MessageSent::class, MessageUpdated::class, MessageDeleted::class,
        ConversationActivity::class, ConversationUpdated::class,
        DirectMessageReceived::class, FriendshipChanged::class,
    ]);

    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

function befriend(User $a, User $b): Friendship
{
    return Friendship::factory()->accepted()->create(['user_id' => $a->id, 'friend_id' => $b->id]);
}

function openDm(User $from, User $to): Conversation
{
    $id = test()->actingAs($from)->postJson('/dms', ['user_id' => $to->id])->assertOk()->json('conversation_id');

    return Conversation::findOrFail($id);
}

it('sends a friend request, accepts it and tells the other side each time', function () {
    $this->actingAs($this->alice)->post('/friends', ['username' => 'bob'])->assertRedirect();

    $friendship = Friendship::sole();
    expect($friendship->status)->toBe(Friendship::STATUS_PENDING);
    Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $e) => $e->type === FriendshipChanged::REQUEST
        && $e->recipient->is($this->bob)
        && $e->broadcastOn()->name === "private-App.Models.User.{$this->bob->id}");

    // Only the recipient can accept.
    $this->actingAs($this->alice)->post("/friends/{$friendship->id}/accept");
    expect($friendship->fresh()->status)->toBe(Friendship::STATUS_PENDING);

    $this->actingAs($this->bob)->post("/friends/{$friendship->id}/accept")->assertRedirect();
    expect($friendship->fresh()->status)->toBe(Friendship::STATUS_ACCEPTED);
    Event::assertDispatched(FriendshipChanged::class, fn (FriendshipChanged $e) => $e->type === FriendshipChanged::ACCEPTED
        && $e->recipient->is($this->alice));
});

it('opens one conversation per pair of friends', function () {
    befriend($this->alice, $this->bob);

    $first = openDm($this->alice, $this->bob);
    $second = openDm($this->bob, $this->alice);

    expect($second->id)->toBe($first->id)
        ->and($first->isDirect())->toBeTrue()
        ->and($first->hasParticipant($this->alice))->toBeTrue()
        ->and($first->hasParticipant($this->bob))->toBeTrue();

    $this->actingAs($this->alice)->get("/dms/{$first->id}")->assertOk()->assertSee($this->bob->name);
});

it('also opens DMs between people who share a server', function () {
    $server = serverOwnedBy($this->alice);
    memberOf($server, $this->bob);

    expect(openDm($this->alice, $this->bob)->isDirect())->toBeTrue();
});

it('refuses DMs with strangers, blocked users and yourself', function () {
    $this->actingAs($this->alice)->postJson('/dms', ['user_id' => $this->bob->id])->assertUnprocessable();
    $this->actingAs($this->alice)->postJson('/dms', ['user_id' => $this->alice->id])->assertUnprocessable();

    $carol = User::factory()->create();
    befriend($this->alice, $carol);
    Friendship::query()->delete();
    Friendship::factory()->blocked()->create(['user_id' => $carol->id, 'friend_id' => $this->alice->id]);
    $this->actingAs($this->alice)->postJson('/dms', ['user_id' => $carol->id])->assertUnprocessable();

    expect(Conversation::count())->toBe(0);
});

it('sends a DM and broadcasts it to the conversation and the recipient', function () {
    befriend($this->alice, $this->bob);
    $conversation = openDm($this->alice, $this->bob);

    $this->actingAs($this->alice)
        ->postJson("/dms/{$conversation->id}/messages", ['content' => 'hi bob'])
        ->assertCreated()
        ->assertJsonPath('message.conversation_id', $conversation->id);

    $message = Message::sole();
    expect($message->channel_id)->toBeNull()
        ->and($conversation->fresh()->last_message_at)->not->toBeNull();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-conversation.{$conversation->id}");

    Event::assertDispatched(ConversationActivity::class, function (ConversationActivity $e) use ($conversation) {
        $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());
        sort($channels);

        return $e->conversation->is($conversation)
            && $channels === ["private-App.Models.User.{$this->alice->id}", "private-App.Models.User.{$this->bob->id}"]
            && $e->broadcastWith()['conversation_id'] === $conversation->id;
    });

    // Only the recipient is notified, never the author.
    Event::assertDispatchedTimes(DirectMessageReceived::class, 1);
    Event::assertDispatched(DirectMessageReceived::class, fn (DirectMessageReceived $e) => $e->broadcastOn()[0]->name === "private-App.Models.User.{$this->bob->id}");
});

it('does not notify a recipient who blocked the author', function () {
    befriend($this->alice, $this->bob);
    $conversation = openDm($this->alice, $this->bob);
    Friendship::query()->delete();
    Friendship::factory()->blocked()->create(['user_id' => $this->bob->id, 'friend_id' => $this->alice->id]);

    $this->actingAs($this->alice)
        ->postJson("/dms/{$conversation->id}/messages", ['content' => 'hello?'])
        ->assertForbidden();

    Event::assertNotDispatched(MessageSent::class);
    Event::assertNotDispatched(DirectMessageReceived::class);
});

it('hides a conversation from outsiders as a 404', function () {
    befriend($this->alice, $this->bob);
    $conversation = openDm($this->alice, $this->bob);
    $eve = User::factory()->create();

    // A 404, not a 403, so conversation ids do not tell who talks to whom.
    $this->actingAs($eve)->get("/dms/{$conversation->id}")->assertNotFound();
    $this->actingAs($eve)->getJson("/dms/{$conversation->id}/messages")->assertNotFound();
    $this->actingAs($eve)->postJson("/dms/{$conversation->id}/messages", ['content' => 'hi'])->assertNotFound();

    Event::assertNotDispatched(MessageSent::class);
});

it('edits and deletes DMs and broadcasts both on the conversation', function () {
    befriend($this->alice, $this->bob);
    $conversation = openDm($this->alice, $this->bob);
    $message = $conversation->messages()->create(['user_id' => $this->alice->id, 'content' => 'helo']);
    $url = "/dms/{$conversation->id}/messages/{$message->id}";

    $this->actingAs($this->bob)->patchJson($url, ['content' => 'nope'])->assertForbidden();
    $this->actingAs($this->bob)->deleteJson($url)->assertForbidden();

    $this->actingAs($this->alice)->patchJson($url, ['content' => 'hello'])->assertOk();
    $this->actingAs($this->alice)->deleteJson($url)->assertNoContent();

    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $e) => $e->broadcastOn()->name === "private-conversation.{$conversation->id}");
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e) => $e->broadcastWith() === [
        'id' => $message->id, 'channel_id' => null, 'conversation_id' => $conversation->id,
    ]);
});

it('creates a group DM with friends and tells every member', function () {
    $carol = User::factory()->create();
    befriend($this->alice, $this->bob);
    befriend($this->alice, $carol);

    $this->actingAs($this->alice)
        ->postJson('/dms/groups', ['user_ids' => [$this->bob->id, $carol->id], 'name' => 'Trio'])
        ->assertCreated();

    $group = Conversation::sole();
    expect($group->isGroup())->toBeTrue()
        ->and($group->users()->count())->toBe(3)
        ->and($group->isOwnedBy($this->alice))->toBeTrue();

    $this->actingAs($carol)->postJson("/dms/{$group->id}/messages", ['content' => 'hey all'])->assertCreated();
    Event::assertDispatchedTimes(DirectMessageReceived::class, 2);

    // Only the owner removes people; everyone hears about it.
    $this->actingAs($this->bob)->deleteJson("/dms/{$group->id}/participants/{$carol->id}")->assertForbidden();
    $this->actingAs($this->alice)->deleteJson("/dms/{$group->id}/participants/{$carol->id}")->assertSuccessful();

    expect($group->fresh()->hasParticipant($carol))->toBeFalse();
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => in_array(
        "private-App.Models.User.{$carol->id}",
        array_map(fn ($c) => $c->name, $e->broadcastOn()),
        true,
    ));
});
