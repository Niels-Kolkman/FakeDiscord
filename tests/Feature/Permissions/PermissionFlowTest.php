<?php

/**
 * ISSUE-39: roles, the role hierarchy and channel overrides as members
 * meet them over HTTP (Pest).
 */

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->server = serverOwnedBy($this->owner);
    $this->channel = generalChannel($this->server);
});

it('lets members with Manage Roles create roles, and nobody else', function () {
    $this->actingAs(memberOf($this->server))
        ->post("/servers/{$this->server->id}/roles", ['name' => 'Mods'])
        ->assertForbidden();

    $manager = $this->memberWith($this->server, Permission::ManageRoles, Permission::KickMembers);
    $this->actingAs($manager)
        ->post("/servers/{$this->server->id}/roles", [
            'name' => 'Helpers',
            'color' => '#ABCDEF',
            'permissions' => [Permission::KickMembers->value],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $role = Role::where('name', 'Helpers')->sole();
    expect($role->color)->toBe('#abcdef')
        ->and($role->permissions)->toBe(Permission::KickMembers->value);
});

it('does not let managers grant permissions they lack', function () {
    $manager = $this->memberWith($this->server, Permission::ManageRoles);

    $this->actingAs($manager)
        ->post("/servers/{$this->server->id}/roles", ['name' => 'Admins', 'permissions' => [Permission::Administrator->value]])
        ->assertSessionHasErrors('permissions');

    expect(Role::where('name', 'Admins')->exists())->toBeFalse();
});

it('refuses the reserved @everyone name and bad colours', function (array $payload, string $field) {
    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/roles", $payload)
        ->assertSessionHasErrors($field);
})->with([
    'reserved name' => [['name' => '@EVERYONE'], 'name'],
    'bad colour' => [['name' => 'Mods', 'color' => 'red'], 'color'],
    'unknown permission' => [['name' => 'Mods', 'permissions' => [1 << 9]], 'permissions.0'],
]);

it('gives a member the role\'s permissions once it is assigned', function () {
    $member = memberOf($this->server);
    $role = $this->makeRole($this->server, 'Builders', Permission::ManageChannels->value);
    $create = fn () => $this->actingAs($member)->post("/servers/{$this->server->id}/channels", ['name' => 'built']);

    $create()->assertForbidden();

    $this->actingAs($this->owner)
        ->post("/servers/{$this->server->id}/roles/{$role->id}/members", ['user_id' => $member->id])
        ->assertRedirect(route('roles.edit', [$this->server, $role]));

    $create()->assertRedirect();
    expect(Channel::where('name', 'built')->exists())->toBeTrue();

    // Taking the role away takes the permission with it.
    $this->actingAs($this->owner)
        ->delete("/servers/{$this->server->id}/roles/{$role->id}/members/{$member->id}")
        ->assertRedirect();
    $this->actingAs($member)->post("/servers/{$this->server->id}/channels", ['name' => 'again'])->assertForbidden();
});

it('only lets managers hand out roles below their own highest role', function () {
    $low = $this->makeRole($this->server, 'Low', 0, 1);
    $manager = $this->joinServer($this->server);
    $this->giveRole($manager, $this->makeRole($this->server, 'Manager', Permission::ManageRoles->value, 2));
    $high = $this->makeRole($this->server, 'High', 0, 3);
    $target = memberOf($this->server);

    $this->actingAs($manager)
        ->post("/servers/{$this->server->id}/roles/{$high->id}/members", ['user_id' => $target->id])
        ->assertForbidden();
    $this->actingAs($manager)
        ->post("/servers/{$this->server->id}/roles/{$low->id}/members", ['user_id' => $target->id])
        ->assertRedirect();

    expect($target->roles()->pluck('roles.id')->all())->toBe([$low->id]);
});

it('hides a channel when @everyone is denied View Channels there', function () {
    $member = memberOf($this->server);
    $this->overrideChannel($this->channel, $this->server->defaultRole(), deny: Permission::ViewChannels->value);

    $this->actingAs($member)->get("/servers/{$this->server->id}/channels/{$this->channel->id}")->assertForbidden();
    $this->actingAs($member)->postJson(messagesUrl($this->channel), ['content' => 'hi'])->assertForbidden();
});

it('lets an allow on one role beat a deny on another', function () {
    $member = memberOf($this->server);
    $muted = $this->makeRole($this->server, 'Muted', 0);
    $speaker = $this->makeRole($this->server, 'Speaker', 0);
    $this->giveRole($member, $muted);
    $this->overrideChannel($this->channel, $muted, deny: Permission::SendMessages->value);

    $this->actingAs($member)->postJson(messagesUrl($this->channel), ['content' => 'one'])->assertForbidden();

    $this->giveRole($member, $speaker);
    $this->overrideChannel($this->channel, $speaker, allow: Permission::SendMessages->value);

    $this->actingAs($member)->postJson(messagesUrl($this->channel), ['content' => 'two'])->assertCreated();
});

it('lets Administrators past every override and private channel', function () {
    $admin = $this->memberWith($this->server, Permission::Administrator);
    $this->channel->update(['is_private' => true]);
    $this->overrideChannel($this->channel, $this->server->defaultRole(), deny: Permission::mask(Permission::ViewChannels, Permission::SendMessages));

    $this->actingAs($admin)->get("/servers/{$this->server->id}/channels/{$this->channel->id}")->assertOk();
    $this->actingAs($admin)->postJson(messagesUrl($this->channel), ['content' => 'admin here'])->assertCreated();
    $this->actingAs($admin)->get("/servers/{$this->server->id}/settings")->assertOk();
});

it('maps each server permission to the pages it opens', function (Permission $permission, string $page) {
    $url = str_replace('{server}', (string) $this->server->id, $page);

    $this->actingAs(memberOf($this->server))->get($url)->assertForbidden();
    $this->actingAs($this->memberWith($this->server, $permission))->get($url)->assertOk();
})->with([
    'Manage Server opens settings' => [Permission::ManageServer, '/servers/{server}/settings'],
    'Manage Roles opens roles' => [Permission::ManageRoles, '/servers/{server}/roles'],
    'Manage Channels opens new channel' => [Permission::ManageChannels, '/servers/{server}/channels/create'],
    'Ban Members opens bans' => [Permission::BanMembers, '/servers/{server}/bans'],
]);

it('keeps the owner in charge whatever @everyone is allowed', function () {
    $this->server->defaultRole()->update(['permissions' => 0]);

    $this->actingAs($this->owner)->get("/servers/{$this->server->id}/settings")->assertOk();
    $this->actingAs($this->owner)->postJson(messagesUrl($this->channel), ['content' => 'still here'])->assertCreated();
    $this->actingAs(memberOf($this->server))->get("/servers/{$this->server->id}/channels/{$this->channel->id}")->assertForbidden();
});
