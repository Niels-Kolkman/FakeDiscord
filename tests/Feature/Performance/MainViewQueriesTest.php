<?php

/**
 * ISSUE-42: no N+1 queries on the main chat views. A server, a channel,
 * its history API, the pins, a DM and its history run the same number of
 * queries for a small server as for a big one, with replies, reactions,
 * attachments, mentions, pins and roles on every page. Lazy loading in a
 * loop throws outside production (AppServiceProvider), which catches the
 * rest.
 *
 * Measured with an empty ServerCache, seen by a member with a role (the
 * owner skips the permission lookups).
 */

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\ServerCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\FeatureTestCase;

/**
 * A server with $members members (half with a role) and $messages channel
 * messages, plus a DM with as many messages, all seen by one member.
 *
 * @return array{viewer: User, server: Server, channel: Channel, dm: Conversation}
 */
function busyServer(int $members, int $messages): array
{
    $owner = User::factory()->create();
    $server = app(CreateServer::class)->create($owner, 'Busy');
    /** @var Channel $channel */
    $channel = $server->channels()->firstOrFail();
    $role = $server->roles()->create(['name' => 'Regulars', 'permissions' => 0, 'position' => 1, 'is_default' => false]);

    $users = [$owner];
    foreach (range(1, $members) as $i) {
        $user = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);
        if ($i % 2 === 1) {
            $role->users()->attach($user->id);
        }
        $users[] = $user;
    }
    $viewer = $users[1];

    $previous = null;
    foreach (range(0, $messages - 1) as $i) {
        $factory = Message::factory()->inChannel($channel);
        if ($previous !== null && $i % 3 === 0) {
            $factory = $factory->replyTo($previous);
        }
        $message = $factory->create([
            'user_id' => $users[$i % count($users)]->id,
            'content' => "hello @{$viewer->username} number {$i}",
            'pinned_at' => $i % 5 === 0 ? now() : null,
            'pinned_by_id' => $i % 5 === 0 ? $owner->id : null,
        ]);
        Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $users[($i + 1) % count($users)]->id, 'emoji' => '👍']);
        if ($i % 4 === 0) {
            MessageAttachment::factory()->create(['message_id' => $message->id]);
        }
        $previous = $message;
    }

    $dm = Conversation::factory()->between($viewer, $owner)->create();
    foreach (range(0, $messages - 1) as $i) {
        Message::factory()->inConversation($dm)->create(['user_id' => $i % 2 === 0 ? $viewer->id : $owner->id]);
    }

    return ['viewer' => $viewer, 'server' => $server, 'channel' => $channel, 'dm' => $dm];
}

/**
 * @param  array{viewer: User, server: Server, channel: Channel, dm: Conversation}  $scene
 * @return array<string, string>
 */
function mainViews(array $scene): array
{
    $server = $scene['server']->id;
    $channel = $scene['channel']->id;
    $dm = $scene['dm']->id;

    return [
        'server' => "/servers/{$server}",
        'channel' => "/servers/{$server}/channels/{$channel}",
        'channel history' => "/servers/{$server}/channels/{$channel}/messages",
        'pins' => "/servers/{$server}/channels/{$channel}/pins",
        'dm' => "/dms/{$dm}",
        'dm history' => "/dms/{$dm}/messages",
    ];
}

/**
 * The queries one GET of $url runs, starting with an empty ServerCache
 * unless $warm.
 */
function queriesFor(FeatureTestCase $test, User $viewer, string $url, bool $warm = false): int
{
    if (! $warm) {
        app(ServerCache::class)->flush();
    }

    $count = 0;
    Event::listen(QueryExecuted::class, function () use (&$count): void {
        $count++;
    });

    $json = str_contains($url, '/messages') || str_ends_with($url, '/pins');
    ($json ? $test->actingAs($viewer)->getJson($url) : $test->actingAs($viewer)->get($url))->assertOk();
    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $count;
}

it('runs as many queries for a big server as for a small one', function () {
    // 16 messages: enough that some pinned message is also a reply.
    $small = busyServer(members: 3, messages: 16);
    $big = busyServer(members: 24, messages: 45);

    $bigViews = mainViews($big);
    foreach (mainViews($small) as $view => $url) {
        expect(queriesFor($this, $big['viewer'], $bigViews[$view]))
            ->toBe(queriesFor($this, $small['viewer'], $url), "The {$view} view queries per item");
    }
});

it('runs fewer or as many queries once the server is cached', function () {
    $scene = busyServer(members: 10, messages: 12);

    foreach (mainViews($scene) as $view => $url) {
        $cold = queriesFor($this, $scene['viewer'], $url);
        $warm = queriesFor($this, $scene['viewer'], $url, warm: true);

        expect($warm)->toBeLessThanOrEqual($cold, "The {$view} view");
    }

    // The member list and the viewer's permissions come from the cache.
    $channel = mainViews($scene)['channel'];
    $cold = queriesFor($this, $scene['viewer'], $channel);
    expect(queriesFor($this, $scene['viewer'], $channel, warm: true))->toBeLessThan($cold);
});
