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
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([MessageSent::class, MessageUpdated::class, MessageDeleted::class, ReactionsUpdated::class, MessagePinned::class]);
});

function postedMessage(Channel $channel, User $author, string $content = 'hello'): Message
{
    return $channel->messages()->create(['user_id' => $author->id, 'content' => $content]);
}

it('stores a message and broadcasts it on the channel', function () {
    $s = serverScene();

    $response = $this->actingAs($s->member)
        ->postJson($s->messagesUrl(), ['content' => 'Hello, world!'])
        ->assertCreated()
        ->assertJsonPath('message.content', 'Hello, world!')
        ->assertJsonPath('message.author.id', $s->member->id);

    $message = Message::sole();
    expect($message->channel_id)->toBe($s->channel->id)
        ->and($response->json('message.id'))->toBe($message->id);

    Event::assertDispatchedTimes(MessageSent::class, 1);
    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message)
        && $event->broadcastOn()->name === "private-channel.{$s->channel->id}"
        && $event->broadcastAs() === MessageSent::NAME
        && $event->broadcastWith()['content'] === 'Hello, world!');
});

it('does not store or broadcast invalid messages', function (array $payload, string $field) {
    $s = serverScene();

    $this->actingAs($s->member)
        ->postJson($s->messagesUrl(), $payload)
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
    $s = serverScene();

    $this->actingAs(User::factory()->create())->postJson($s->messagesUrl(), ['content' => 'hi'])->assertForbidden();

    $this->revokeFromEveryone($s->server, Permission::SendMessages);
    $this->actingAs($s->member)->postJson($s->messagesUrl(), ['content' => 'hi'])->assertForbidden();

    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

it('replies to a message', function () {
    $s = serverScene();
    $original = postedMessage($s->channel, $s->owner, 'question?');

    $this->actingAs($s->member)
        ->postJson($s->messagesUrl(), ['content' => 'answer', 'reply_to_id' => $original->id])
        ->assertCreated()
        ->assertJsonPath('message.reply_to.id', $original->id);
});

it('notifies mentioned members', function () {
    $s = serverScene(User::factory()->create(['username' => 'bob']));

    $this->actingAs($s->owner)->postJson($s->messagesUrl(), ['content' => 'hey @bob'])->assertCreated();

    $notification = DatabaseNotification::sole();
    expect($notification->type)->toBe(MentionedInMessage::class)
        ->and((int) $notification->notifiable_id)->toBe($s->member->id);
});

it('loads the history of a channel as JSON', function () {
    $s = serverScene();
    postedMessage($s->channel, $s->owner, 'first');
    postedMessage($s->channel, $s->member, 'second');

    $this->actingAs($s->member)
        ->getJson($s->messagesUrl())
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.content', 'first')
        ->assertJsonPath('messages.1.content', 'second');
});

it('lets authors edit their messages and broadcasts the edit', function () {
    $s = serverScene();
    $message = postedMessage($s->channel, $s->member, 'teh typo');

    $this->actingAs($s->member)
        ->patchJson("{$s->messagesUrl()}/{$message->id}", ['content' => 'the typo'])
        ->assertOk()
        ->assertJsonPath('message.content', 'the typo');

    $fresh = $message->fresh();
    expect($fresh?->content)->toBe('the typo')
        ->and($fresh?->edited_at)->not->toBeNull();
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-channel.{$s->channel->id}"
        && $e->broadcastWith()['content'] === 'the typo');
});

it('does not let anyone else edit a message, not even the owner', function () {
    $s = serverScene();
    $message = postedMessage($s->channel, $s->member, 'mine');

    $this->actingAs($s->owner)
        ->patchJson("{$s->messagesUrl()}/{$message->id}", ['content' => 'hijacked'])
        ->assertForbidden();

    expect($message->fresh()?->content)->toBe('mine');
    Event::assertNotDispatched(MessageUpdated::class);
});

it('deletes messages for their author and for moderators, and broadcasts it', function () {
    $s = serverScene();
    $own = postedMessage($s->channel, $s->member);
    $other = postedMessage($s->channel, $s->owner);

    $this->actingAs($s->member)->deleteJson("{$s->messagesUrl()}/{$other->id}")->assertForbidden();
    $this->actingAs($s->member)->deleteJson("{$s->messagesUrl()}/{$own->id}")->assertNoContent();

    $moderator = $this->memberWith($s->server, Permission::ManageMessages);
    $this->actingAs($moderator)->deleteJson("{$s->messagesUrl()}/{$other->id}")->assertNoContent();

    expect(Message::count())->toBe(0);
    Event::assertDispatchedTimes(MessageDeleted::class, 2);
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e) => $e->messageId === $own->id
        && $e->broadcastOn()->name === "private-channel.{$s->channel->id}"
        && $e->broadcastWith() === ['id' => $own->id, 'channel_id' => $s->channel->id, 'conversation_id' => null]);
});

it('adds and removes reactions and broadcasts both', function () {
    $s = serverScene();
    $message = postedMessage($s->channel, $s->owner);
    $reactions = "{$s->messagesUrl()}/{$message->id}/reactions";

    $this->actingAs($s->member)->postJson($reactions, ['emoji' => '👍'])->assertSuccessful();
    expect($message->reactions()->count())->toBe(1);

    $this->actingAs($s->member)->deleteJson($reactions, ['emoji' => '👍'])->assertSuccessful();
    expect($message->reactions()->count())->toBe(0);

    Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $e) => $e->added && $e->emoji === '👍' && $e->userId === $s->member->id);
    Event::assertDispatched(ReactionsUpdated::class, fn (ReactionsUpdated $e) => ! $e->added
        && $e->broadcastOn()->name === "private-channel.{$s->channel->id}");
});

it('needs Add Reactions to start a reaction', function () {
    $s = serverScene();
    $message = postedMessage($s->channel, $s->owner);
    $this->revokeFromEveryone($s->server, Permission::AddReactions);

    $this->actingAs($s->member)
        ->postJson("{$s->messagesUrl()}/{$message->id}/reactions", ['emoji' => '🎉'])
        ->assertForbidden();

    Event::assertNotDispatched(ReactionsUpdated::class);
});

it('pins messages for members with Manage Messages only', function () {
    $s = serverScene();
    $message = postedMessage($s->channel, $s->member);
    $pin = "{$s->messagesUrl()}/{$message->id}/pin";

    $this->actingAs($s->member)->postJson($pin)->assertForbidden();
    Event::assertNotDispatched(MessagePinned::class);

    $this->actingAs($s->owner)->postJson($pin)->assertSuccessful();
    expect($message->fresh()?->pinned_at)->not->toBeNull();
    Event::assertDispatched(MessagePinned::class, fn (MessagePinned $e) => $e->message->is($message)
        && $e->broadcastOn()->name === "private-channel.{$s->channel->id}");

    $this->actingAs($s->member)->get("{$s->channelUrl()}/pins")->assertOk();

    $this->actingAs($s->owner)->deleteJson($pin)->assertSuccessful();
    expect($message->fresh()?->pinned_at)->toBeNull();
});

it('finds messages through search', function () {
    $s = serverScene();
    postedMessage($s->channel, $s->owner, 'the quick brown fox');
    postedMessage($s->channel, $s->owner, 'lazy dog');

    $this->actingAs($s->member)
        ->get("/servers/{$s->server->id}/search?q=fox")
        ->assertOk()
        ->assertSee('1 result')
        ->assertSee('the quick brown <mark', false)
        ->assertDontSee('lazy dog');
});
