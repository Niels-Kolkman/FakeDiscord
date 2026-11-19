<?php

/**
 * ISSUE-39: sending, editing, deleting, reacting to and pinning channel
 * messages, with the realtime broadcasts asserted through Event::fake (Pest).
 */

use App\Enums\Permission;
use App\Events\MessageDeleted;
use App\Events\MessagePinned;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\ReactionsUpdated;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([MessageSent::class, MessageUpdated::class, MessageDeleted::class, ReactionsUpdated::class, MessagePinned::class]);

    $this->owner = User::factory()->create();
    $this->server = serverOwnedBy($this->owner);
    $this->channel = generalChannel($this->server);
    $this->member = memberOf($this->server, User::factory()->create(['username' => 'bob']));
    $this->url = messagesUrl($this->channel);
});

function postedMessage(User $author, string $content = 'hello'): Message
{
    return test()->channel->messages()->create(['user_id' => $author->id, 'content' => $content]);
}

it('stores a message and broadcasts it on the channel', function () {
    $response = $this->actingAs($this->member)
        ->postJson($this->url, ['content' => 'Hello, world!'])
        ->assertCreated()
        ->assertJsonPath('message.content', 'Hello, world!')
        ->assertJsonPath('message.author.id', $this->member->id);

    $message = Message::sole();
    expect($message->channel_id)->toBe($this->channel->id)
        ->and($response->json('message.id'))->toBe($message->id);

    Event::assertDispatchedTimes(MessageSent::class, 1);
    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($message) {
        return $event instanceof ShouldBroadcastNow
            && $event->message->is($message)
            && $event->broadcastOn()->name === "private-channel.{$this->channel->id}"
            && $event->broadcastAs() === MessageSent::NAME
            && $event->broadcastWith()['content'] === 'Hello, world!';
    });
});

it('does not store or broadcast invalid messages', function (array $payload, string $field) {
    $this->actingAs($this->member)
        ->postJson($this->url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
})->with([
    'empty' => [['content' => ''], 'content'],
    'too long' => [['content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1)], 'content'],
    'reply to unknown message' => [['content' => 'hi', 'reply_to_id' => 999], 'reply_to_id'],
]);

it('refuses messages from outsiders and members without Send Messages', function () {
    $this->actingAs(User::factory()->create())->postJson($this->url, ['content' => 'hi'])->assertForbidden();

    $this->revokeFromEveryone($this->server, Permission::SendMessages);
    $this->actingAs($this->member)->postJson($this->url, ['content' => 'hi'])->assertForbidden();

    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

it('replies to a message', function () {
    $original = postedMessage($this->owner, 'question?');

    $this->actingAs($this->member)
        ->postJson($this->url, ['content' => 'answer', 'reply_to_id' => $original->id])
        ->assertCreated()
        ->assertJsonPath('message.reply_to.id', $original->id);
});

it('notifies mentioned members', function () {
    $this->actingAs($this->owner)->postJson($this->url, ['content' => 'hey @bob'])->assertCreated();

    $notification = DatabaseNotification::sole();
    expect($notification->type)->toBe(MentionedInMessage::class)
        ->and((int) $notification->notifiable_id)->toBe($this->member->id);
});

it('loads the history of a channel as JSON', function () {
    postedMessage($this->owner, 'first');
    postedMessage($this->member, 'second');

    $this->actingAs($this->member)
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.content', 'first')
        ->assertJsonPath('messages.1.content', 'second');
});

it('lets authors edit their messages and broadcasts the edit', function () {
    $message = postedMessage($this->member, 'teh typo');

    $this->actingAs($this->member)
        ->patchJson("{$this->url}/{$message->id}", ['content' => 'the typo'])
        ->assertOk()
        ->assertJsonPath('message.content', 'the typo');

    expect($message->fresh()->content)->toBe('the typo')
        ->and($message->fresh()->edited_at)->not->toBeNull();
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-channel.{$this->channel->id}"
        && $e->broadcastWith()['content'] === 'the typo');
});

it('does not let anyone else edit a message, not even the owner', function () {
    $message = postedMessage($this->member, 'mine');

    $this->actingAs($this->owner)
        ->patchJson("{$this->url}/{$message->id}", ['content' => 'hijacked'])
        ->assertForbidden();

    expect($message->fresh()->content)->toBe('mine');
    Event::assertNotDispatched(MessageUpdated::class);
});

it('deletes messages for their author and for moderators, and broadcasts it', function () {
    $own = postedMessage($this->member);
    $other = postedMessage($this->owner);

    $this->actingAs($this->member)->deleteJson("{$this->url}/{$other->id}")->assertForbidden();
    $this->actingAs($this->member)->deleteJson("{$this->url}/{$own->id}")->assertNoContent();

    $moderator = $this->memberWith($this->server, Permission::ManageMessages);
    $this->actingAs($moderator)->deleteJson("{$this->url}/{$other->id}")->assertNoContent();

    expect(Message::count())->toBe(0);
    Event::assertDispatchedTimes(MessageDeleted::class, 2);
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e) => $e->messageId === $own->id
        && $e->broadcastOn()->name === "private-channel.{$this->channel->id}"
        && $e->broadcastWith() === ['id' => $own->id, 'channel_id' => $this->channel->id, 'conversation_id' => null]);
});

it('adds and removes reactions and broadcasts both', function () {
    $message = postedMessage($this->owner);
    $reactions = "{$this->url}/{$message->id}/reactions";

    $this->actingAs($this->member)->postJson($reactions, ['emoji' => '👍'])->assertSuccessful();
    expect($message->reactions()->count())->toBe(1);

    $this->actingAs($this->member)->deleteJson($reactions, ['emoji' => '👍'])->assertSuccessful();
    expect($message->reactions()->count())->toBe(0);

    Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $e) => $e->added && $e->emoji === '👍' && $e->userId === $this->member->id);
    Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $e) => ! $e->added
        && $e->broadcastOn()->name === "private-channel.{$this->channel->id}");
});

it('needs Add Reactions to start a reaction', function () {
    $message = postedMessage($this->owner);
    $this->revokeFromEveryone($this->server, Permission::AddReactions);

    $this->actingAs($this->member)
        ->postJson("{$this->url}/{$message->id}/reactions", ['emoji' => '🎉'])
        ->assertForbidden();

    Event::assertNotDispatched(ReactionsUpdated::class);
});

it('pins messages for members with Manage Messages only', function () {
    $message = postedMessage($this->member);
    $pin = "{$this->url}/{$message->id}/pin";

    $this->actingAs($this->member)->postJson($pin)->assertForbidden();
    Event::assertNotDispatched(MessagePinned::class);

    $this->actingAs($this->owner)->postJson($pin)->assertSuccessful();
    expect($message->fresh()->pinned_at)->not->toBeNull();
    Event::assertDispatched(MessagePinned::class, fn (MessagePinned $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-channel.{$this->channel->id}");

    $this->actingAs($this->member)->get("/servers/{$this->server->id}/channels/{$this->channel->id}/pins")->assertOk();

    $this->actingAs($this->owner)->deleteJson($pin)->assertSuccessful();
    expect($message->fresh()->pinned_at)->toBeNull();
});

it('finds messages through search', function () {
    postedMessage($this->owner, 'the quick brown fox');
    postedMessage($this->owner, 'lazy dog');

    $this->actingAs($this->member)
        ->get("/servers/{$this->server->id}/search?q=fox")
        ->assertOk()
        ->assertSee('1 result')
        ->assertSee('the quick brown <mark', false)
        ->assertDontSee('lazy dog');
});
