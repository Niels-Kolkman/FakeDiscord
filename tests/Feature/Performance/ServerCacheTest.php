<?php

/**
 * ISSUE-42: permissions and member lists are cached per server
 * (App\Support\ServerCache). A stale permission would be a security bug,
 * so every change they depend on must drop the cache, and nothing
 * uncommitted may end up in it.
 */

use App\Actions\Roles\AssignRole;
use App\Actions\Roles\MoveRole;
use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\MemberGroup;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * @return array{Server, Channel}
 */
function cachedServer(): array
{
    $server = app(CreateServer::class)->create(User::factory()->create(), 'Cached');
    /** @var Channel $channel */
    $channel = $server->channels()->firstOrFail();

    return [$server, $channel];
}

/** Like a new request: nothing kept in memory, only the cache store. */
function nextRequest(): PermissionService
{
    app()->forgetScopedInstances();

    return app(PermissionService::class);
}

/**
 * @return list<string>
 */
function memberNames(Server $server): array
{
    return collect($server->memberList()->allGroups)
        ->flatMap(fn (MemberGroup $group) => $group->members)
        ->map(fn (ServerMember $member): string => $member->displayName())
        ->values()
        ->all();
}

it('caches a member\'s permissions between requests', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server);
    nextRequest()->permissions($member, $server);

    DB::enableQueryLog();
    nextRequest()->permissions($member, $server);

    expect(DB::getQueryLog())->toBe([]);
});

it('drops them when a role changes, is given, taken or moved', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server);
    $role = $this->makeRole($server, 'Helpers', 0);
    expect(nextRequest()->has($member, $server, Permission::ManageMessages))->toBeFalse();

    $role->users()->attach($member->id);
    $role->update(['permissions' => Permission::ManageMessages->value]);
    expect(nextRequest()->has($member, $server, Permission::ManageMessages))->toBeTrue();

    $role->users()->detach($member->id);
    expect(nextRequest()->has($member, $server, Permission::ManageMessages))->toBeFalse();

    // AssignRole writes role_user with a plain insert.
    app(AssignRole::class)->assign($role, $member);
    expect(nextRequest()->has($member, $server, Permission::ManageMessages))->toBeTrue();

    // MoveRole changes positions with plain updates.
    $higher = $this->makeRole($server, 'Higher', 0);
    $before = nextRequest()->highestPosition($member, $server);
    app(MoveRole::class)->move($higher, MoveRole::DOWN);
    expect(nextRequest()->highestPosition($member, $server))->toBeGreaterThan($before);
});

it('drops them when @everyone, an override or the membership changes', function () {
    [$server, $channel] = cachedServer();
    $member = $this->joinServer($server);
    expect(nextRequest()->hasInChannel($member, $channel, Permission::ViewChannels))->toBeTrue();

    $this->overrideChannel($channel, $server->defaultRole(), deny: Permission::ViewChannels->value);
    expect(nextRequest()->hasInChannel($member, $channel, Permission::ViewChannels))->toBeFalse();

    $this->overrideChannel($channel, $server->defaultRole());
    $this->revokeFromEveryone($server, Permission::SendMessages);
    expect(nextRequest()->has($member, $server, Permission::SendMessages))->toBeFalse()
        ->and(nextRequest()->hasInChannel($member, $channel, Permission::ViewChannels))->toBeTrue();

    // LeaveServer deletes with plain queries.
    app(LeaveServer::class)->leave($server, $member);
    expect(nextRequest()->permissions($member, $server))->toBe(0);
});

it('lets a cached timeout run out on time', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server);
    $server->membershipOf($member)?->update(['timed_out_until' => now()->addMinutes(5)]);

    expect(nextRequest()->isTimedOut($member, $server))->toBeTrue()
        ->and(nextRequest()->has($member, $server, Permission::SendMessages))->toBeFalse();

    $this->travel(6)->minutes();

    expect(nextRequest()->isTimedOut($member, $server))->toBeFalse()
        ->and(nextRequest()->has($member, $server, Permission::SendMessages))->toBeTrue();
});

it('never caches a change that was rolled back', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server);
    $role = $this->makeRole($server, 'Helpers', Permission::ManageMessages->value);

    try {
        DB::transaction(function () use ($role, $member, $server): void {
            $role->users()->attach($member->id);
            // Inside the transaction it sees its own change...
            expect(app(PermissionService::class)->has($member, $server, Permission::ManageMessages))->toBeTrue();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // ...which must not outlive the rollback.
    }

    expect(nextRequest()->has($member, $server, Permission::ManageMessages))->toBeFalse();
});

it('drops the member list when a member renames themselves or someone joins', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server, User::factory()->create(['name' => 'Before']));

    nextRequest();
    expect(memberNames($server))->toContain('Before');

    $member->update(['name' => 'After']);
    nextRequest();
    expect(memberNames($server))->toContain('After')
        ->and(memberNames($server))->not->toContain('Before');

    $this->joinServer($server, User::factory()->create(['name' => 'Newcomer']));
    nextRequest();
    expect(memberNames($server))->toContain('Newcomer');
});

it('keeps password hashes out of the cache files', function () {
    $path = storage_path('framework/testing/cache-'.uniqid());
    config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    Cache::clearResolvedInstances();
    app()->forgetScopedInstances();

    try {
        [$server] = cachedServer();
        $this->joinServer($server);
        memberNames($server);

        $contents = collect(File::allFiles($path))->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))->implode("\n");
        // The member list is in there, without the users' password hashes.
        expect($contents)->toContain('App\\Models\\ServerMember')
            ->and($contents)->not->toContain('$2y$')
            ->and($contents)->not->toContain('remember_token";s:');
    } finally {
        File::deleteDirectory($path);
    }
});

it('starts over after migrating', function () {
    [$server] = cachedServer();
    $member = $this->joinServer($server);
    nextRequest()->permissions($member, $server);

    event(new MigrationsEnded('up'));

    DB::enableQueryLog();
    nextRequest()->permissions($member, $server);
    expect(DB::getQueryLog())->not->toBe([]);
});
