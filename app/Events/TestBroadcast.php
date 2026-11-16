<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Smoke-test event for the websocket stack. Broadcast it from tinker:
 *
 *     event(new App\Events\TestBroadcast('Hello from tinker'));
 *
 * The browser logs it to the console (see resources/js/echo.js).
 * It uses ShouldBroadcastNow, so it does not need a queue worker.
 */
class TestBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const CHANNEL = 'test';

    public const NAME = 'test.message';

    public function __construct(public string $message = 'Hello from Reverb!') {}

    public function broadcastOn(): Channel
    {
        return new Channel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
