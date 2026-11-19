<?php

/**
 * ISSUE-39: servers from creation to leaving, through invites and
 * moderation (Pest). Moderation broadcasts are checked with Event::fake.
 */

use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Models\Ban;
use App\Models\Invite;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('creates a server with an owner, an @everyone role and a #general channel', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post('/servers', ['name' => '  My   Server '])
        ->assertRedirect();

    $server = Server::sole();
    expect($server->name)->toBe('My Server')
        ->and($server->owner_id)->toBe($owner->id)
        ->and($server->hasMember($owner))->toBeTrue()
        ->and($server->defaultRole()->permissions)->toBe(Permission::everyoneDefaults())
        ->and(generalChannel($server)->name)->toBe('general');
});

it('validates the server name', function (string $name) {
    $this->actingAs(User::factory()->create())
        ->post('/servers', ['name' => $name])
        ->assertSessionHasErrors('name');

    expect(Server::count())->toBe(0);
})->with(['empty' => '', 'too short' => 'a', 'too long' => str_repeat('a', 101)]);

it('shows a server to its members only', function () {
    $server = serverOwnedBy();
    $member = memberOf($server);

    $this->actingAs($member)->get("/servers/{$server->id}")->assertOk()->assertSee($server->name);
    $this->actingAs(User::factory()->create())->get("/servers/{$server->id}")->assertForbidden();
});

it('lets members with Manage Server rename it, and nobody else', function () {
    $server = serverOwnedBy();
    $manager = $this->memberWith($server, Permission::ManageServer);

    $this->actingAs(memberOf($server))
        ->patch("/servers/{$server->id}", ['name' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($manager)
        ->patch("/servers/{$server->id}", ['name' => 'Renamed'])
        ->assertRedirect(route('servers.edit', $server));

    expect($server->fresh()->name)->toBe('Renamed');
});

it('deletes a server only for the owner who types its name', function () {
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner, 'Doomed');

    $this->actingAs($this->memberWith($server, Permission::Administrator))
        ->delete("/servers/{$server->id}", ['confirm_name' => 'Doomed'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete("/servers/{$server->id}", ['confirm_name' => 'doomed'])
        ->assertSessionHasErrors('confirm_name');
    expect(Server::count())->toBe(1);

    $this->actingAs($owner)
        ->delete("/servers/{$server->id}", ['confirm_name' => 'Doomed'])
        ->assertRedirect(route('dashboard'));
    expect(Server::count())->toBe(0);
});

it('joins a server through an invite and counts the use', function () {
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner);

    $this->actingAs($owner)
        ->post("/servers/{$server->id}/invites", ['expires_in' => 60, 'max_uses' => 1])
        ->assertRedirect(route('servers.invites.index', $server))
        ->assertSessionHas('invite_url');

    $invite = Invite::sole();
    $guest = User::factory()->create();

    $this->actingAs($guest)->get("/invite/{$invite->code}")->assertOk()->assertSee($server->name);
    $this->actingAs($guest)->post("/invite/{$invite->code}")->assertRedirect(route('servers.show', $server));

    expect($server->hasMember($guest))->toBeTrue()
        ->and($invite->fresh()->uses)->toBe(1);

    // A one-use invite is used up.
    $late = User::factory()->create();
    $this->actingAs($late)->post("/invite/{$invite->code}");
    expect($server->hasMember($late))->toBeFalse();
});

it('404s unknown and wrongly cased invite codes', function () {
    $server = serverOwnedBy();
    $invite = Invite::factory()->create(['server_id' => $server->id, 'inviter_id' => $server->owner_id, 'code' => 'AbCdEfGh']);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/invite/nothing')->assertNotFound();
    $this->actingAs($user)->post('/invite/abcdefgh')->assertNotFound();
    expect($invite->fresh()->uses)->toBe(0);
});

it('lets members leave, but not the owner', function () {
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner);
    $member = memberOf($server);

    $this->actingAs($member)->delete("/servers/{$server->id}/leave")->assertRedirect(route('dashboard'));
    expect($server->hasMember($member))->toBeFalse();

    $this->actingAs($owner)->delete("/servers/{$server->id}/leave")->assertForbidden();
    expect($server->hasMember($owner))->toBeTrue();
});

it('kicks a member and tells them in real time', function () {
    Event::fake([MemberModerated::class]);
    $server = serverOwnedBy();
    $moderator = $this->memberWith($server, Permission::KickMembers);
    $target = memberOf($server);

    $this->actingAs($moderator)
        ->post("/servers/{$server->id}/members/{$target->id}/kick")
        ->assertRedirect(route('members.index', $server));

    expect($server->hasMember($target))->toBeFalse();
    Event::assertDispatched(MemberModerated::class, function (MemberModerated $event) use ($server, $target) {
        return $event->type === MemberModerated::KICKED
            && $event->server->is($server)
            && $event->recipient->is($target)
            && $event->broadcastOn()->name === "private-App.Models.User.{$target->id}";
    });
});

it('refuses kicks without the permission or against the owner', function () {
    Event::fake([MemberModerated::class]);
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner);
    $target = memberOf($server);

    $this->actingAs(memberOf($server))->post("/servers/{$server->id}/members/{$target->id}/kick")->assertForbidden();
    $this->actingAs($this->memberWith($server, Permission::KickMembers))
        ->post("/servers/{$server->id}/members/{$owner->id}/kick")
        ->assertForbidden();

    expect($server->hasMember($target))->toBeTrue()->and($server->hasMember($owner))->toBeTrue();
    Event::assertNotDispatched(MemberModerated::class);
});

it('bans a member so their invite no longer works', function () {
    Event::fake([MemberModerated::class]);
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner);
    $target = memberOf($server);

    $this->actingAs($owner)
        ->post("/servers/{$server->id}/members/{$target->id}/ban", ['reason' => 'Spam'])
        ->assertRedirect(route('members.index', $server));

    expect($server->hasMember($target))->toBeFalse()
        ->and(Ban::where('server_id', $server->id)->where('user_id', $target->id)->value('reason'))->toBe('Spam');
    Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e) => $e->type === MemberModerated::BANNED && $e->recipient->is($target));

    $invite = Invite::factory()->create(['server_id' => $server->id, 'inviter_id' => $owner->id]);
    $this->actingAs($target)->post("/invite/{$invite->code}");
    expect($server->hasMember($target))->toBeFalse();

    // Unbanning lets them back in.
    $this->actingAs($owner)->delete("/servers/{$server->id}/bans/{$target->id}")->assertRedirect();
    $this->actingAs($target)->post("/invite/{$invite->code}")->assertRedirect(route('servers.show', $server));
    expect($server->hasMember($target))->toBeTrue();
});

it('times a member out and lifts it again', function () {
    Event::fake([MemberModerated::class]);
    $server = serverOwnedBy();
    $moderator = $this->memberWith($server, Permission::TimeoutMembers);
    $target = memberOf($server);

    $this->actingAs($moderator)
        ->put("/servers/{$server->id}/members/{$target->id}/timeout", ['duration' => 60])
        ->assertRedirect(route('members.index', $server));

    $this->actingAs($target)
        ->postJson(messagesUrl(generalChannel($server)), ['content' => 'let me talk'])
        ->assertForbidden();

    $this->actingAs($moderator)
        ->delete("/servers/{$server->id}/members/{$target->id}/timeout")
        ->assertRedirect(route('members.index', $server));

    Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e) => $e->type === MemberModerated::TIMED_OUT && $e->until !== null);
    Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e) => $e->type === MemberModerated::TIMEOUT_REMOVED);
});
