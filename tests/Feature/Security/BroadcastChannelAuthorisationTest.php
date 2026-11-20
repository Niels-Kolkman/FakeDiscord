<?php

/**
 * ISSUE-41: authorisation review of every broadcast channel in
 * routes/channels.php, through /broadcasting/auth on the Reverb driver.
 *
 * The list of channels is pinned, so a new one fails here until it is
 * added to the matrix below with who may and may not join it. The
 * per-feature tests (MessageChannelAuthTest, TypingChannelAuthTest,
 * ConversationChannelAuthTest, ...) cover the finer permission cases.
 */

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Tests\FeatureTestCase;

function useReverbForChannelAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1001',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    require base_path('routes/channels.php');
}

function channelAuthStatus(FeatureTestCase $test, ?User $user, string $channel): int
{
    if ($user !== null) {
        $test->actingAs($user);
    }

    return $test->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->getStatusCode();
}

beforeEach(function () {
    useReverbForChannelAuth();
});

it('has exactly the reviewed channels', function () {
    $channels = array_keys(Broadcast::driver()->getChannels()->all());
    sort($channels);

    expect($channels)->toBe([
        'App.Models.User.{id}',
        'channel.{channel}',
        'conversation-typing.{conversation}',
        'conversation.{conversation}',
        'server.{server}',
        'typing.{channel}',
    ]);
});

it('lets only the right people join each channel', function () {
    $scene = serverScene();
    $outsider = User::factory()->create();
    $otherMember = memberOf($scene->server);
    $private = Channel::factory()->create(['server_id' => $scene->server->id, 'is_private' => true]);
    $dm = Conversation::factory()->between($scene->member, $scene->owner)->create();

    // [channel, allowed, refused]
    $matrix = [
        ["private-App.Models.User.{$scene->member->id}", [$scene->member], [$scene->owner, $outsider]],
        ["private-channel.{$scene->channel->id}", [$scene->owner, $scene->member], [$outsider]],
        ["private-channel.{$private->id}", [$scene->owner], [$scene->member, $outsider]],
        ["presence-typing.{$scene->channel->id}", [$scene->owner, $scene->member], [$outsider]],
        ["presence-typing.{$private->id}", [$scene->owner], [$scene->member, $outsider]],
        ["presence-server.{$scene->server->id}", [$scene->owner, $scene->member], [$outsider]],
        ["private-conversation.{$dm->id}", [$scene->member, $scene->owner], [$otherMember, $outsider]],
        ["presence-conversation-typing.{$dm->id}", [$scene->member, $scene->owner], [$otherMember, $outsider]],
        // Unknown ids are refused, not a 404 or an error.
        ['private-channel.999999', [], [$scene->owner]],
        ['private-conversation.999999', [], [$scene->owner]],
        ['presence-server.999999', [], [$scene->owner]],
        ['private-unknown', [], [$scene->owner]],
    ];

    foreach ($matrix as [$channel, $allowed, $refused]) {
        foreach ($allowed as $user) {
            expect(channelAuthStatus($this, $user, $channel))->toBe(200, "{$user->username} should join {$channel}");
        }
        foreach ($refused as $user) {
            expect(channelAuthStatus($this, $user, $channel))->toBe(403, "{$user->username} should not join {$channel}");
        }
    }

    // Guests never get a signature, whatever the channel.
    $this->app['auth']->forgetGuards();
    foreach ($matrix as [$channel]) {
        expect(channelAuthStatus($this, null, $channel))->toBe(403, "a guest should not join {$channel}");
    }
});

it('shares no private details as presence member info', function (string $kind) {
    $scene = serverScene();
    $dm = Conversation::factory()->between($scene->member, $scene->owner)->create();

    $channel = match ($kind) {
        'typing' => "presence-typing.{$scene->channel->id}",
        'server' => "presence-server.{$scene->server->id}",
        'conversation-typing' => "presence-conversation-typing.{$dm->id}",
        default => throw new InvalidArgumentException("Unknown presence channel {$kind}"),
    };

    $data = json_decode((string) $this->actingAs($scene->member)
        ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
        ->assertOk()
        ->json('channel_data'), true);

    // Pusher sends the user id as a string.
    expect((string) $data['user_id'])->toBe((string) $scene->member->id)
        ->and(array_keys($data['user_info']))->toBeIn([['id', 'name'], ['id', 'status']])
        ->and(json_encode($data))->not->toContain($scene->member->email);
})->with(['typing', 'server', 'conversation-typing']);
