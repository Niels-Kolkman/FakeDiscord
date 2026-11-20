<?php

namespace Tests\Feature;

use App\Events\TestBroadcast;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * End-to-end checks of the Reverb (Pusher-protocol) broadcaster:
 * channel authorization through /broadcasting/auth and synchronous delivery
 * of the tinker smoke-test event.
 */
class ReverbChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key';

    private const SECRET = 'test-secret';

    /**
     * phpunit.xml uses the "null" broadcaster. Switch to Reverb with fixed
     * credentials and re-register routes/channels.php on that driver.
     */
    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => self::KEY,
            'broadcasting.connections.reverb.secret' => self::SECRET,
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        require base_path('routes/channels.php');
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorize(string $channel, string $socketId = '1234.5678'): TestResponse
    {
        return $this->post('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => $socketId,
        ]);
    }

    public function test_user_can_authorize_their_own_private_channel(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $channel = "private-App.Models.User.{$user->id}";

        $response = $this->actingAs($user)->authorize($channel)->assertOk();

        // Pusher protocol: auth = "<key>:<hmac_sha256(socket_id:channel, secret)>"
        $expected = self::KEY.':'.hash_hmac('sha256', "1234.5678:{$channel}", self::SECRET);
        $this->assertSame($expected, $response->json('auth'));
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $this->useReverb();
        [$alice, $bob] = User::factory()->count(2)->create();

        $this->actingAs($alice)
            ->authorize("private-App.Models.User.{$bob->id}")
            ->assertForbidden();
    }

    public function test_guest_cannot_authorize_a_private_channel(): void
    {
        $this->useReverb();
        $user = User::factory()->create();

        $this->authorize("private-App.Models.User.{$user->id}")->assertForbidden();
    }

    public function test_unknown_private_channel_is_rejected(): void
    {
        $this->useReverb();
        $user = User::factory()->create();

        $this->actingAs($user)->authorize('private-does-not-exist')->assertForbidden();
    }

    public function test_reverb_server_and_client_credentials_come_from_the_same_env(): void
    {
        // If these drift apart, the browser connects but every broadcast is rejected.
        $client = config('broadcasting.connections.reverb');
        $server = collect(config()->array('reverb.apps.apps'))->first();

        $this->assertSame($client['key'], $server['key']);
        $this->assertSame($client['secret'], $server['secret']);
        $this->assertSame($client['app_id'], $server['app_id']);
    }

    public function test_event_helper_broadcasts_synchronously_even_with_a_real_queue(): void
    {
        // In dev QUEUE_CONNECTION=database; tinker must not need a worker.
        config(['queue.default' => 'database']);
        Queue::fake();

        $spy = new class extends BaseBroadcaster
        {
            /** @var list<array{list<string>, string, array<string, mixed>}> */
            public array $sent = [];

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = [array_map(fn ($c) => (string) $c, $channels), $event, $payload];
            }
        };
        Broadcast::extend('spy', fn () => $spy);
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);

        event(new TestBroadcast('Hello from tinker'));

        Queue::assertNothingPushed();
        $this->assertCount(1, $spy->sent);
        [$channels, $name, $payload] = $spy->sent[0];
        $this->assertSame(['test'], $channels);
        $this->assertSame('test.message', $name);
        $this->assertSame('Hello from tinker', $payload['message']);
    }

    public function test_test_event_has_a_default_message_and_iso8601_timestamp(): void
    {
        $this->travelTo('2026-01-02 03:04:05');

        $payload = (new TestBroadcast)->broadcastWith();

        $this->assertSame('Hello from Reverb!', $payload['message']);
        $this->assertSame(now()->toIso8601String(), $payload['sent_at']);
        $this->assertStringStartsWith('2026-01-02T03:04:05', $payload['sent_at']);
    }
}
