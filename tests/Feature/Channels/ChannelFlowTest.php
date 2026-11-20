<?php

/**
 * Managing channels and categories, private channels and
 * unread tracking (Pest).
 */

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\ChannelRead;

it('creates a channel with a normalised name', function () {
    $s = serverScene();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/channels", ['name' => '  Off Topic!! Chat ', 'topic' => 'Anything goes'])
        ->assertRedirect();

    $channel = Channel::where('name', 'off-topic-chat')->sole();
    expect($channel->server_id)->toBe($s->server->id)
        ->and($channel->topic)->toBe('Anything goes');

    $this->actingAs($s->owner)
        ->get("/servers/{$s->server->id}/channels/{$channel->id}")
        ->assertOk()
        ->assertSee('off-topic-chat');
});

it('rejects channel names without letters or numbers', function () {
    $s = serverScene();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/channels", ['name' => '!!!'])
        ->assertSessionHasErrors('name');

    expect($s->server->channels()->count())->toBe(1);
});

it('needs Manage Channels to create, edit or delete channels', function () {
    $s = serverScene();
    $base = "/servers/{$s->server->id}/channels";

    $this->actingAs($s->member)->post($base, ['name' => 'nope'])->assertForbidden();
    $this->actingAs($s->member)->patch($s->channelUrl(), ['name' => 'nope'])->assertForbidden();
    $this->actingAs($s->member)->delete($s->channelUrl())->assertForbidden();

    $manager = $this->memberWith($s->server, Permission::ManageChannels);
    $this->actingAs($manager)->patch($s->channelUrl(), ['name' => 'Lobby'])->assertRedirect();
    expect($s->channel->fresh()?->name)->toBe('lobby');

    $this->actingAs($manager)->delete($s->channelUrl())->assertRedirect(route('servers.show', $s->server));
    expect(Channel::find($s->channel->id))->toBeNull();
});

it('puts channels in categories that belong to the same server', function () {
    $s = serverScene();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/categories", ['name' => 'Text Channels'])
        ->assertRedirect();
    $category = ChannelCategory::sole();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/channels", ['name' => 'rules', 'category_id' => $category->id]);
    expect(Channel::where('name', 'rules')->value('category_id'))->toBe($category->id);

    $other = serverOwnedBy($s->owner, 'Other');
    $this->actingAs($s->owner)
        ->post("/servers/{$other->id}/channels", ['name' => 'stray', 'category_id' => $category->id])
        ->assertSessionHasErrors('category_id');
});

it('reorders the channel list', function () {
    $s = serverScene();
    $second = $s->server->channels()->create(['name' => 'second', 'position' => 1]);

    $this->actingAs($s->owner)
        ->putJson("/servers/{$s->server->id}/layout", [
            'uncategorized' => [$second->id, $s->channel->id],
            'categories' => [],
        ])
        ->assertOk();

    expect($second->fresh()?->position)->toBeLessThan($s->channel->fresh()?->position);

    // A layout that leaves a channel out is refused.
    $this->actingAs($s->owner)
        ->putJson("/servers/{$s->server->id}/layout", ['uncategorized' => [$s->channel->id], 'categories' => []])
        ->assertUnprocessable();
});

it('hides private channels from members until a role is allowed in', function () {
    $s = serverScene();
    $url = $s->channelUrl();

    $this->actingAs($s->owner)
        ->patch("{$url}/privacy", ['is_private' => true])
        ->assertRedirect(route('channels.permissions.edit', [$s->server, $s->channel]));

    $this->actingAs($s->member)->get($url)->assertForbidden();
    $this->actingAs($s->member)->getJson("{$url}/messages")->assertForbidden();

    $role = $this->makeRole($s->server, 'Staff', 0);
    $this->giveRole($s->member, $role);

    $states = collect(Permission::channelOverridable())
        ->mapWithKeys(fn (Permission $p) => [$p->name => $p === Permission::ViewChannels ? 'allow' : 'inherit'])
        ->all();
    $this->actingAs($s->owner)
        ->put("{$url}/permissions/{$role->id}", ['permissions' => $states])
        ->assertRedirect();

    $this->actingAs($s->member)->get($url)->assertOk();
    // The owner always sees every channel.
    $this->actingAs($s->owner)->get($url)->assertOk();
});

it('marks a channel read when a member opens it', function () {
    $s = serverScene();
    $message = $s->channel->messages()->create(['user_id' => $s->owner->id, 'content' => 'hi']);

    $this->actingAs($s->member)->get($s->channelUrl())->assertOk();

    $read = ChannelRead::where('channel_id', $s->channel->id)->where('user_id', $s->member->id)->sole();
    expect($read->last_read_message_id)->toBe($message->id);
});
