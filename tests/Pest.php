<?php

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Tests\FeatureTestCase;
use Tests\Support\ServerScene;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Function-style Pest tests under tests/Feature run on Tests\FeatureTestCase:
| a fresh database, the permission helpers ($this->memberWith(),
| $this->makeRole(), ...) and no Vite manifest needed. The class-based
| PHPUnit tests that already live there are not affected: Pest runs them
| as they are. tests/stubs/pest.stub types $this for PHPStan.
|
*/

pest()->extend(FeatureTestCase::class)->in('Feature');

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
    return $server->channels()->orderBy('id')->firstOrFail();
}

function messagesUrl(Channel $channel): string
{
    return "/servers/{$channel->server_id}/channels/{$channel->id}/messages";
}

/**
 * A new server with its owner, #general and one plain member.
 */
function serverScene(?User $member = null): ServerScene
{
    $owner = User::factory()->create();
    $server = serverOwnedBy($owner);

    return new ServerScene($owner, $server, generalChannel($server), memberOf($server, $member));
}
