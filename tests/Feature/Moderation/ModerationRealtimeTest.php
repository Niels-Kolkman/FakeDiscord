<?php

namespace Tests\Feature\Moderation;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-29, the websocket side end to end: what a kicked, banned or
 * timed-out user may still subscribe to when pusher-js reconnects
 * (routes/channels.php), and that a moderation action really reaches
 * Reverb (MemberModerated on the user's channel, then terminate
 * connections) and still goes through when Reverb is down.
 */
class ModerationRealtimeTest extends TestCase
{
    use GivesPermissions, RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    private User $moderator;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Mod Server');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;

        $this->moderator = $this->memberWith($this->server, Permission::KickMembers, Permission::BanMembers, Permission::TimeoutMembers);
        $this->member = $this->joinServer($this->server);
    }

    // --- Channel authorization after moderation --------------------------

    private function useReverbForChannelAuth(): void
    {
        // phpunit.xml uses the "null" broadcaster; channel auth needs Reverb.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');

        // Nothing may reach a real Reverb server from here.
        Event::fake([MemberModerated::class]);
        $this->mock(RealtimeSessions::class, fn (MockInterface $mock) => $mock->shouldReceive('disconnect'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorizeAs(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removals(): array
    {
        return [
            'kick' => ['members.kick'],
            'ban' => ['members.ban'],
        ];
    }

    #[DataProvider('removals')]
    public function test_a_removed_member_cannot_resubscribe_to_the_servers_channels(string $route): void
    {
        $this->useReverbForChannelAuth();

        $this->authorizeAs($this->member, "private-channel.{$this->channel->id}")->assertOk();
        $this->authorizeAs($this->member, "presence-typing.{$this->channel->id}")->assertOk();

        $this->actingAs($this->moderator)->post(route($route, [$this->server, $this->member]))->assertRedirect();

        $this->authorizeAs($this->member, "private-channel.{$this->channel->id}")->assertForbidden();
        $this->authorizeAs($this->member, "presence-typing.{$this->channel->id}")->assertForbidden();
        // Their own channel stays theirs, so MemberModerated reaches them.
        $this->authorizeAs($this->member, "private-App.Models.User.{$this->member->id}")->assertOk();
    }

    public function test_a_timed_out_member_can_still_listen_to_the_server(): void
    {
        $this->useReverbForChannelAuth();

        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 600])
            ->assertRedirect();

        $this->authorizeAs($this->member, "private-channel.{$this->channel->id}")->assertOk();
        $this->authorizeAs($this->member, "presence-typing.{$this->channel->id}")->assertOk();
    }

    // --- Reaching Reverb -------------------------------------------------

    private function usePusher(Pusher $pusher): void
    {
        Broadcast::extend('fake-reverb', fn () => new PusherBroadcaster($pusher));
        config(['broadcasting.connections.fake-reverb' => ['driver' => 'fake-reverb'], 'broadcasting.default' => 'fake-reverb']);
    }

    public function test_a_ban_tells_the_user_over_their_channel_and_then_closes_their_sockets(): void
    {
        $calls = [];
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->once()->andReturnUsing(function (array $channels, string $event, array $payload) use (&$calls): object {
            $calls[] = ['trigger', $channels, $event, $payload];

            return (object) [];
        });
        $pusher->shouldReceive('terminateUserConnections')->once()->andReturnUsing(function (string $userId) use (&$calls): object {
            $calls[] = ['terminate', $userId];

            return (object) [];
        });
        $this->usePusher($pusher);

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['reason' => 'secret moderator note'])
            ->assertRedirect(route('members.index', $this->server));

        $this->assertCount(2, $calls);
        [$trigger, $terminate] = $calls;
        $this->assertSame(['private-App.Models.User.'.$this->member->id], $trigger[1]);
        $this->assertSame('member.moderated', $trigger[2]);
        $this->assertSame('banned', $trigger[3]['type']);
        $this->assertSame(['id' => $this->server->id, 'name' => 'Mod Server'], $trigger[3]['server']);
        $this->assertStringNotContainsString('secret moderator note', (string) json_encode($trigger[3]));
        $this->assertSame(['terminate', (string) $this->member->id], $terminate, 'Sockets close after the user was told.');
    }

    public function test_a_timeout_is_broadcast_but_keeps_the_user_connected(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->once()->withArgs(fn (array $channels, string $event, array $payload): bool => $channels === ['private-App.Models.User.'.$this->member->id]
            && $payload['type'] === 'timed_out'
            && is_string($payload['until']))->andReturn((object) []);
        $pusher->shouldNotReceive('terminateUserConnections');
        $this->usePusher($pusher);

        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60])
            ->assertRedirect(route('members.index', $this->server));
    }

    #[DataProvider('removals')]
    public function test_moderation_still_happens_when_reverb_is_down(string $route): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->once()->andThrow(new ApiErrorException('Reverb is not running'));
        $pusher->shouldReceive('terminateUserConnections')->once()->andThrow(new ApiErrorException('Reverb is not running'));
        $this->usePusher($pusher);

        $this->actingAs($this->moderator)
            ->post(route($route, [$this->server, $this->member]))
            ->assertRedirect(route('members.index', $this->server))
            ->assertSessionHas('status');

        $this->assertFalse($this->server->hasMember($this->member));
    }

    public function test_a_timeout_still_happens_when_reverb_is_down(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->once()->andThrow(new ApiErrorException('Reverb is not running'));
        $this->usePusher($pusher);

        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60])
            ->assertRedirect(route('members.index', $this->server));

        $this->assertTrue($this->server->membershipOf($this->member)?->isTimedOut() ?? false);
    }
}
