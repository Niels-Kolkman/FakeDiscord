<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\Channel;

/**
 * Queued broadcasting (ISSUE-42). The app's events used to be
 * ShouldBroadcastNow: the request that sent a message waited for the HTTP
 * call to Reverb, once per event. They are ShouldBroadcast now, so they go
 * onto the database queue and the queue worker talks to Reverb.
 *
 * What goes out is still decided while the request runs: Laravel
 * serializes the event when it queues it, and __serialize() takes a
 * snapshot then (channels(), payload()). The worker only sends that
 * snapshot, so
 *
 * - links in the payload are made from the request's host, as before,
 *   not from APP_URL;
 * - the payload shows things as they were when the event happened, even if
 *   the worker gets to it later or the models are gone by then;
 * - the job holds a small array instead of serialized models, and the
 *   worker runs no queries.
 *
 * An event using this implements channels() and payload() in place of
 * broadcastOn() and broadcastWith(), which this trait provides.
 *
 * A failed broadcast is not retried ($tries): by the time it would be,
 * it is stale. It is reported (failed_jobs and the log), and the others
 * see the change after a reload, as before.
 */
trait QueuesBroadcast
{
    public int $tries = 1;

    /** @var array{on: Channel|list<Channel>, with: array<string, mixed>}|null */
    private ?array $snapshot = null;

    /**
     * @return Channel|list<Channel>
     */
    abstract protected function channels(): Channel|array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * What channels() returned: one channel or a list.
     *
     * @return Channel|list<Channel>
     */
    public function broadcastOn(): Channel|array
    {
        return $this->snapshot()['on'];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot()['with'];
    }

    /**
     * @return array{socket: string|null, tries: int, snapshot: array{on: Channel|list<Channel>, with: array<string, mixed>}}
     */
    public function __serialize(): array
    {
        return ['socket' => $this->socket, 'tries' => $this->tries, 'snapshot' => $this->snapshot()];
    }

    /**
     * @param  array{socket: string|null, tries: int, snapshot: array{on: Channel|list<Channel>, with: array<string, mixed>}}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->socket = $data['socket'];
        $this->tries = $data['tries'];
        $this->snapshot = $data['snapshot'];
    }

    /**
     * @return array{on: Channel|list<Channel>, with: array<string, mixed>}
     */
    private function snapshot(): array
    {
        return $this->snapshot ??= ['on' => $this->channels(), 'with' => $this->payload()];
    }
}
