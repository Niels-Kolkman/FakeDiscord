<?php

namespace Tests\Feature\Moderation;

use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Tests\TestCase;

/**
 * Closing a kicked or banned user's websocket connections through
 * Reverb's Pusher-compatible "terminate user connections" API.
 */
class RealtimeSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function usePusher(Pusher $pusher): void
    {
        Broadcast::extend('fake-reverb', fn () => new PusherBroadcaster($pusher));
        config(['broadcasting.connections.fake-reverb' => ['driver' => 'fake-reverb'], 'broadcasting.default' => 'fake-reverb']);
    }

    public function test_it_asks_reverb_to_close_the_users_connections(): void
    {
        $user = User::factory()->create();
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('terminateUserConnections')->once()->with((string) $user->id)->andReturn((object) []);
        $this->usePusher($pusher);

        app(RealtimeSessions::class)->disconnect($user);
    }

    public function test_a_reverb_error_is_reported_and_ignored(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('terminateUserConnections')->once()->andThrow(new ApiErrorException('Reverb is not running'));
        $this->usePusher($pusher);

        app(RealtimeSessions::class)->disconnect(User::factory()->create());

        $this->addToAssertionCount(1);
    }

    public function test_it_does_nothing_without_a_websocket_broadcaster(): void
    {
        config(['broadcasting.default' => 'null']);

        app(RealtimeSessions::class)->disconnect(User::factory()->create());

        $this->addToAssertionCount(1);
    }
}
