<?php

namespace Tests\Feature;

use App\Events\TestBroadcast;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Mockery;
use Tests\TestCase;

/**
 * Reverb is the broadcaster and App\Events\TestBroadcast reaches
 * the public "test" channel that the browser listens on.
 */
class ReverbBroadcastingTest extends TestCase
{
    public function test_reverb_connection_is_configured_from_env(): void
    {
        $reverb = config('broadcasting.connections.reverb');

        $this->assertSame('reverb', $reverb['driver']);
        $this->assertArrayHasKey('key', $reverb);
        $this->assertArrayHasKey('secret', $reverb);
        $this->assertArrayHasKey('app_id', $reverb);
        $this->assertNotEmpty(config('reverb.apps.apps'), 'config/reverb.php should define an app');
    }

    public function test_broadcast_auth_route_is_registered(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'broadcasting/auth'),
            'routes/channels.php should be loaded so /broadcasting/auth exists',
        );
    }

    public function test_test_event_broadcasts_immediately_on_the_public_test_channel(): void
    {
        $event = new TestBroadcast('Hello from tinker');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event, 'Should not need a queue worker');

        $channel = $event->broadcastOn();
        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('test', $channel->name);
        $this->assertSame('test.message', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame('Hello from tinker', $payload['message']);
        $this->assertNotFalse(strtotime($payload['sent_at']));
    }

    public function test_dispatching_the_event_sends_it_to_the_broadcaster(): void
    {
        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->withArgs(function (array $channels, string $name, array $payload) {
                return count($channels) === 1
                    && $channels[0]->name === 'test'
                    && $name === 'test.message'
                    && $payload['message'] === 'ping';
            });

        $factory = Mockery::mock(BroadcastFactory::class);
        $factory->shouldReceive('connection')->andReturn($broadcaster);

        (new BroadcastEvent(new TestBroadcast('ping')))->handle($factory);
    }
}
