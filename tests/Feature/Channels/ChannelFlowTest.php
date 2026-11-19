<?php

/**
 * ISSUE-39: managing channels and categories, private channels and
 * unread tracking (Pest).
 */

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\ChannelRead;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->server = serverOwnedBy($this->owner);
});

it('creates a channel with a normalised name', function () {
    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/channels", ['name' => '  Off Topic!! Chat ', 'topic' => 'Anything goes'])
        ->assertRedirect();

    $channel = Channel::where('name', 'off-topic-chat')->sole();
    expect($channel->server_id)->toBe($this->server->id)
        ->and($channel->topic)->toBe('Anything goes');

    $this->actingAs($this->owner)
        ->get("/servers/{$this->server->id}/channels/{$channel->id}")
        ->assertOk()
        ->assertSee('off-topic-chat');
});

it('rejects channel names without letters or numbers', function () {
    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/channels", ['name' => '!!!'])
        ->assertSessionHasErrors('name');

    expect($this->server->channels()->count())->toBe(1);
});

it('needs Manage Channels to create, edit or delete channels', function () {
    $member = memberOf($this->server);
    $channel = generalChannel($this->server);
    $base = "/servers/{$this->server->id}/channels";

    $this->actingAs($member)->post($base, ['name' => 'nope'])->assertForbidden();
    $this->actingAs($member)->patch("{$base}/{$channel->id}", ['name' => 'nope'])->assertForbidden();
    $this->actingAs($member)->delete("{$base}/{$channel->id}")->assertForbidden();

    $manager = $this->memberWith($this->server, Permission::ManageChannels);
    $this->actingAs($manager)->patch("{$base}/{$channel->id}", ['name' => 'Lobby'])->assertRedirect();
    expect($channel->fresh()->name)->toBe('lobby');

    $this->actingAs($manager)->delete("{$base}/{$channel->id}")->assertRedirect(route('servers.show', $this->server));
    expect(Channel::find($channel->id))->toBeNull();
});

it('puts channels in categories that belong to the same server', function () {
    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/categories", ['name' => 'Text Channels'])
        ->assertRedirect();
    $category = ChannelCategory::sole();

    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/channels", ['name' => 'rules', 'category_id' => $category->id]);
    expect(Channel::where('name', 'rules')->value('category_id'))->toBe($category->id);

    $other = serverOwnedBy($this->owner, 'Other');
    $this->actingAs($this->owner)
        ->post("/servers/{$other->id}/channels", ['name' => 'stray', 'category_id' => $category->id])
        ->assertSessionHasErrors('category_id');
});

it('reorders the channel list', function () {
    $general = generalChannel($this->server);
    $second = $this->server->channels()->create(['name' => 'second', 'position' => 1]);

    $this->actingAs($this->owner)
        ->putJson("/servers/{$this->server->id}/layout", [
            'uncategorized' => [$second->id, $general->id],
            'categories' => [],
        ])
        ->assertOk();

    expect($second->fresh()->position)->toBeLessThan($general->fresh()->position);

    // A layout that leaves a channel out is refused.
    $this->actingAs($this->owner)
        ->putJson("/servers/{$this->server->id}/layout", ['uncategorized' => [$general->id], 'categories' => []])
        ->assertUnprocessable();
});

it('hides private channels from members until a role is allowed in', function () {
    $channel = generalChannel($this->server);
    $member = memberOf($this->server);
    $url = "/servers/{$this->server->id}/channels/{$channel->id}";

    $this->actingAs($this->owner)
        ->patch("{$url}/privacy", ['is_private' => true])
        ->assertRedirect(route('channels.permissions.edit', [$this->server, $channel]));

    $this->actingAs($member)->get($url)->assertForbidden();
    $this->actingAs($member)->getJson("{$url}/messages")->assertForbidden();

    $role = $this->makeRole($this->server, 'Staff', 0);
    $this->giveRole($member, $role);

    $states = collect(Permission::channelOverridable())
        ->mapWithKeys(fn (Permission $p) => [$p->name => $p === Permission::ViewChannels ? 'allow' : 'inherit'])
        ->all();
    $this->actingAs($this->owner)
        ->put("{$url}/permissions/{$role->id}", ['permissions' => $states])
        ->assertRedirect();

    $this->actingAs($member)->get($url)->assertOk();
    // The owner always sees every channel.
    $this->actingAs($this->owner)->get($url)->assertOk();
});

it('marks a channel read when a member opens it', function () {
    $channel = generalChannel($this->server);
    $member = memberOf($this->server);
    $message = $channel->messages()->create(['user_id' => $this->owner->id, 'content' => 'hi']);

    $this->actingAs($member)->get("/servers/{$this->server->id}/channels/{$channel->id}")->assertOk();

    $read = ChannelRead::where('channel_id', $channel->id)->where('user_id', $member->id)->sole();
    expect($read->last_read_message_id)->toBe($message->id);
});
