<?php

/**
 * ISSUE-42: broadcasts go onto the database queue and the worker sends
 * them (App\Events\Concerns\QueuesBroadcast). What goes out is still
 * decided by the request.
 */

use App\Actions\Servers\CreateServer;
use App\Events\Concerns\QueuesBroadcast;
use App\Events\MessageSent;
use App\Events\TestBroadcast;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/**
 * Records what reaches the broadcaster, and queues on the database queue.
 *
 * @return object{sent: list<array{list<string>, string, array<string, mixed>}>}
 */
function queuedBroadcastSpy(bool $fail = false): object
{
    $spy = new class($fail) extends Broadcaster
    {
        /** @var list<array{list<string>, string, array<string, mixed>}> */
        public array $sent = [];

        public function __construct(private bool $fail) {}

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        /**
         * @param  array<int, mixed>  $channels
         * @param  array<string, mixed>  $payload
         */
        public function broadcast(array $channels, $event, array $payload = [])
        {
            if ($this->fail) {
                throw new RuntimeException('Reverb is not running');
            }

            $this->sent[] = [array_map(fn ($channel): string => (string) $channel, $channels), (string) $event, $payload];
        }
    };

    Broadcast::extend('spy', fn () => $spy);
    config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy', 'queue.default' => 'database']);

    return $spy;
}

/**
 * @return array{User, Channel}
 */
function queuedBroadcastScene(): array
{
    $owner = User::factory()->create(['name' => 'Owner']);
    $server = app(CreateServer::class)->create($owner, 'Queued');
    /** @var Channel $channel */
    $channel = $server->channels()->firstOrFail();

    return [$owner, $channel];
}

it('queues every app broadcast except the Reverb smoke test', function () {
    foreach (glob(app_path('Events/*.php')) ?: [] as $file) {
        $class = 'App\\Events\\'.basename($file, '.php');
        $interfaces = class_implements($class) ?: [];

        if ($class === TestBroadcast::class) {
            // `php artisan reverb:test`-style smoke test: no worker needed.
            expect($interfaces)->toContain(ShouldBroadcastNow::class);

            continue;
        }

        expect($interfaces)->toContain(ShouldBroadcast::class)
            ->and($interfaces)->not->toContain(ShouldBroadcastNow::class)
            ->and(class_uses($class))->toContain(QueuesBroadcast::class);
    }
});

it('only queues during the request and lets the worker send', function () {
    $spy = queuedBroadcastSpy();
    [$owner, $channel] = queuedBroadcastScene();

    $this->actingAs($owner)
        ->postJson("/servers/{$channel->server_id}/channels/{$channel->id}/messages", ['content' => 'Queued hello'], ['X-Socket-ID' => '1234.5678'])
        ->assertCreated();

    expect($spy->sent)->toHaveCount(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->runQueueWorker();

    expect($spy->sent)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    [$channels, $name, $payload] = $spy->sent[0];
    expect($channels)->toBe(["private-channel.{$channel->id}"])
        ->and($name)->toBe(MessageSent::NAME)
        ->and($payload['content'])->toBe('Queued hello')
        // toOthers() still skips the sender's socket.
        ->and($payload['socket'])->toBe('1234.5678');
});

it('queues a snapshot of the payload, not the models', function () {
    queuedBroadcastSpy();
    [$owner, $channel] = queuedBroadcastScene();

    $this->actingAs($owner)
        ->postJson("/servers/{$channel->server_id}/channels/{$channel->id}/messages", ['content' => 'Snapshot'])
        ->assertCreated();

    $command = json_decode((string) DB::table('jobs')->value('payload'), true)['data']['command'];

    expect($command)->toContain('Snapshot')
        ->and($command)->not->toContain('App\\Models\\');
});

it('sends what the message looked like when it was queued', function () {
    $spy = queuedBroadcastSpy();
    [$owner, $channel] = queuedBroadcastScene();

    $this->actingAs($owner)
        ->postJson("/servers/{$channel->server_id}/channels/{$channel->id}/messages", ['content' => 'Before'])
        ->assertCreated();

    // Changed and deleted before the worker gets to it.
    Message::query()->update(['content' => 'After']);
    Message::query()->delete();

    $this->runQueueWorker();

    expect($spy->sent)->toHaveCount(1)
        ->and($spy->sent[0][2]['content'])->toBe('Before');
});

it('reports a failed broadcast without retrying it', function () {
    queuedBroadcastSpy(fail: true);
    [$owner, $channel] = queuedBroadcastScene();

    $this->actingAs($owner)
        ->postJson("/servers/{$channel->server_id}/channels/{$channel->id}/messages", ['content' => 'Saved anyway'])
        ->assertCreated();

    $this->runQueueWorker();

    // Stale by the time it would be retried: one try, then failed_jobs.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(Message::withTrashed()->sole()->content)->toBe('Saved anyway');
});
