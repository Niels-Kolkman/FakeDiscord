<?php

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration (ISSUE-39)
|--------------------------------------------------------------------------
|
| Function-style Pest tests under tests/Feature run on the app's TestCase
| with a fresh database and the permission helpers ($this->memberWith(),
| $this->makeRole(), ...). The class-based PHPUnit tests that already live
| there are not affected: Pest runs them as they are.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class, GivesPermissions::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A server created the way the app creates one (owner, @everyone, #general).
 */
function serverOwnedBy(?User $owner = null, string $name = 'Test Server'): Server
{
    return app(CreateServer::class)->create($owner ?? User::factory()->create(), $name);
}

/**
 * A plain member (only @everyone) of $server.
 */
function memberOf(Server $server, ?User $user = null): User
{
    $user ??= User::factory()->create();
    ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);

    return $user;
}

/**
 * The server's first text channel (#general on a new server).
 */
function generalChannel(Server $server): Channel
{
    /** @var Channel */
    return $server->channels()->orderBy('id')->firstOrFail();
}

function messagesUrl(Channel $channel): string
{
    return "/servers/{$channel->server_id}/channels/{$channel->id}/messages";
}
