<?php

/**
 * Roles, the role hierarchy and channel overrides as members
 * meet them over HTTP (Pest).
 */

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;

it('lets members with Manage Roles create roles, and nobody else', function () {
    $s = serverScene();

    $this->actingAs($s->member)
        ->post("/servers/{$s->server->id}/roles", ['name' => 'Mods'])
        ->assertForbidden();

    $manager = $this->memberWith($s->server, Permission::ManageRoles, Permission::KickMembers);
    $this->actingAs($manager)
        ->post("/servers/{$s->server->id}/roles", [
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
    $s = serverScene();
    $manager = $this->memberWith($s->server, Permission::ManageRoles);

    $this->actingAs($manager)
        ->post("/servers/{$s->server->id}/roles", ['name' => 'Admins', 'permissions' => [Permission::Administrator->value]])
        ->assertSessionHasErrors('permissions');

    expect(Role::where('name', 'Admins')->exists())->toBeFalse();
});

it('refuses the reserved @everyone name and bad colours', function (array $payload, string $field) {
    $s = serverScene();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/roles", $payload)
        ->assertSessionHasErrors($field);
})->with([
    'reserved name' => [['name' => '@EVERYONE'], 'name'],
    'bad colour' => [['name' => 'Mods', 'color' => 'red'], 'color'],
    'unknown permission' => [['name' => 'Mods', 'permissions' => [1 << 9]], 'permissions.0'],
]);

it('gives a member the role\'s permissions once it is assigned', function () {
    $s = serverScene();
    $role = $this->makeRole($s->server, 'Builders', Permission::ManageChannels->value);
    $channels = "/servers/{$s->server->id}/channels";

    $this->actingAs($s->member)->post($channels, ['name' => 'built'])->assertForbidden();

    $this->actingAs($s->owner)
        ->post("/servers/{$s->server->id}/roles/{$role->id}/members", ['user_id' => $s->member->id])
        ->assertRedirect(route('roles.edit', [$s->server, $role]));

    $this->actingAs($s->member)->post($channels, ['name' => 'built'])->assertRedirect();
    expect(Channel::where('name', 'built')->exists())->toBeTrue();

    // Taking the role away takes the permission with it.
    $this->actingAs($s->owner)
        ->delete("/servers/{$s->server->id}/roles/{$role->id}/members/{$s->member->id}")
        ->assertRedirect();
    $this->actingAs($s->member)->post($channels, ['name' => 'again'])->assertForbidden();
});

it('only lets managers hand out roles below their own highest role', function () {
    $s = serverScene();
    $low = $this->makeRole($s->server, 'Low', 0, 1);
    $manager = $this->joinServer($s->server);
    $this->giveRole($manager, $this->makeRole($s->server, 'Manager', Permission::ManageRoles->value, 2));
    $high = $this->makeRole($s->server, 'High', 0, 3);

    $this->actingAs($manager)
        ->post("/servers/{$s->server->id}/roles/{$high->id}/members", ['user_id' => $s->member->id])
        ->assertForbidden();
    $this->actingAs($manager)
        ->post("/servers/{$s->server->id}/roles/{$low->id}/members", ['user_id' => $s->member->id])
        ->assertRedirect();

    expect($s->member->roles()->pluck('roles.id')->all())->toBe([$low->id]);
});

it('hides a channel when @everyone is denied View Channels there', function () {
    $s = serverScene();
    $this->overrideChannel($s->channel, $s->server->defaultRole(), deny: Permission::ViewChannels->value);

    $this->actingAs($s->member)->get($s->channelUrl())->assertForbidden();
    $this->actingAs($s->member)->postJson($s->messagesUrl(), ['content' => 'hi'])->assertForbidden();
});

it('lets an allow on one role beat a deny on another', function () {
    $s = serverScene();
    $muted = $this->makeRole($s->server, 'Muted', 0);
    $speaker = $this->makeRole($s->server, 'Speaker', 0);
    $this->giveRole($s->member, $muted);
    $this->overrideChannel($s->channel, $muted, deny: Permission::SendMessages->value);

    $this->actingAs($s->member)->postJson($s->messagesUrl(), ['content' => 'one'])->assertForbidden();

    $this->giveRole($s->member, $speaker);
    $this->overrideChannel($s->channel, $speaker, allow: Permission::SendMessages->value);

    $this->actingAs($s->member)->postJson($s->messagesUrl(), ['content' => 'two'])->assertCreated();
});

it('lets Administrators past every override and private channel', function () {
    $s = serverScene();
    $admin = $this->memberWith($s->server, Permission::Administrator);
    $s->channel->update(['is_private' => true]);
    $this->overrideChannel($s->channel, $s->server->defaultRole(), deny: Permission::mask(Permission::ViewChannels, Permission::SendMessages));

    $this->actingAs($admin)->get($s->channelUrl())->assertOk();
    $this->actingAs($admin)->postJson($s->messagesUrl(), ['content' => 'admin here'])->assertCreated();
    $this->actingAs($admin)->get("/servers/{$s->server->id}/settings")->assertOk();
});

it('maps each server permission to the pages it opens', function (Permission $permission, string $page) {
    $s = serverScene();
    $url = str_replace('{server}', (string) $s->server->id, $page);

    $this->actingAs($s->member)->get($url)->assertForbidden();
    $this->actingAs($this->memberWith($s->server, $permission))->get($url)->assertOk();
})->with([
    'Manage Server opens settings' => [Permission::ManageServer, '/servers/{server}/settings'],
    'Manage Roles opens roles' => [Permission::ManageRoles, '/servers/{server}/roles'],
    'Manage Channels opens new channel' => [Permission::ManageChannels, '/servers/{server}/channels/create'],
    'Ban Members opens bans' => [Permission::BanMembers, '/servers/{server}/bans'],
]);

it('keeps the owner in charge whatever @everyone is allowed', function () {
    $s = serverScene();
    $s->server->defaultRole()->update(['permissions' => 0]);

    $this->actingAs($s->owner)->get("/servers/{$s->server->id}/settings")->assertOk();
    $this->actingAs($s->owner)->postJson($s->messagesUrl(), ['content' => 'still here'])->assertCreated();
    $this->actingAs($s->member)->get($s->channelUrl())->assertForbidden();
});
