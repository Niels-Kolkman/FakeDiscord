<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cache per server (ISSUE-42) for what every page of a server works out
 * again: each member's standing for App\Services\PermissionService, the
 * channel overrides, and the member list (App\Support\MemberList). It uses
 * the default cache store, the file store on a Raspberry Pi: no Redis
 * needed, and no SQLite writes for it.
 *
 * Correctness comes first, since a stale permission is a security bug:
 *
 * - Every key carries the server's version. forget() gives the server a
 *   new version, so everything cached for it is gone at once. Whatever
 *   changes roles, role holders, members, channel overrides, the owner or
 *   a member's profile calls it (App\Observers\ForgetsServerCache, and the
 *   actions that write with plain queries).
 * - The new version is only set once the change is committed. Until then
 *   this process does not cache that server (it reads its own uncommitted
 *   change), and other processes keep the old version, which matches what
 *   they can see.
 * - A value is only written to the cache once the data it was read from
 *   is committed, so a rolled-back change never ends up there.
 * - Entries expire after TTL seconds anyway, and migrating the database
 *   (fresh or not) starts a new epoch, so a rebuilt database with the same
 *   ids never meets old entries. After restoring a backup, run
 *   `php artisan cache:clear`.
 *
 * Values are also kept in memory for the rest of the request (or queue
 * job: the class is scoped), so a page asking about fifty roles reads the
 * cache once. Values must not be null.
 */
class ServerCache
{
    /** Seconds an entry is kept at most. */
    public const TTL = 3600;

    private const PREFIX = 'server-cache:';

    private ?string $epoch = null;

    /** @var array<int, string> server id => version */
    private array $versions = [];

    /** @var array<string, mixed> cache key => value, for this request */
    private array $values = [];

    /** @var array<int, true> servers changed in a transaction that has not committed yet */
    private array $changing = [];

    public function __construct(private readonly Repository $cache) {}

    /**
     * The value for $key of the server, from this request, the cache, or
     * $compute.
     *
     * @template T
     *
     * @param  Closure(): T  $compute  must not return null
     * @return T
     */
    public function remember(int $serverId, string $key, Closure $compute): mixed
    {
        if (isset($this->changing[$serverId])) {
            return $compute();
        }

        $cacheKey = $this->key($serverId, $key);

        if (array_key_exists($cacheKey, $this->values)) {
            return $this->values[$cacheKey];
        }

        $value = $this->cache->get($cacheKey);

        if ($value === null) {
            $value = $compute();

            // Right away outside a transaction, else once it commits.
            DB::afterCommit(fn () => $this->cache->put($cacheKey, $value, self::TTL));
        }

        return $this->values[$cacheKey] = $value;
    }

    /**
     * Something the cache holds for these servers changed.
     */
    public function forget(int ...$serverIds): void
    {
        foreach (array_unique($serverIds) as $serverId) {
            $this->changing[$serverId] = true;
            $this->dropFromMemory($serverId);

            DB::afterCommit(function () use ($serverId): void {
                $this->dropFromMemory($serverId);
                $this->versions[$serverId] = Str::random(16);
                $this->cache->forever($this->versionKey($serverId), $this->versions[$serverId]);
                unset($this->changing[$serverId]);
            });
        }
    }

    /**
     * Forget every server (a new epoch), e.g. after migrating.
     */
    public function flush(): void
    {
        $this->epoch = Str::random(16);
        $this->cache->forever(self::PREFIX.'epoch', $this->epoch);
        $this->versions = [];
        $this->values = [];
    }

    private function key(int $serverId, string $key): string
    {
        return self::PREFIX.$this->epoch().':'.$serverId.':'.$this->version($serverId).':'.$key;
    }

    private function version(int $serverId): string
    {
        return $this->versions[$serverId] ??= $this->stored($this->versionKey($serverId));
    }

    private function versionKey(int $serverId): string
    {
        return self::PREFIX.$this->epoch().':'.$serverId.':version';
    }

    private function epoch(): string
    {
        return $this->epoch ??= $this->stored(self::PREFIX.'epoch');
    }

    /** The token stored under $key, or a new one. */
    private function stored(string $key): string
    {
        $token = $this->cache->get($key);

        if (! is_string($token) || $token === '') {
            $token = Str::random(16);
            $this->cache->forever($key, $token);
        }

        return $token;
    }

    private function dropFromMemory(int $serverId): void
    {
        unset($this->versions[$serverId]);

        $prefix = self::PREFIX.$this->epoch().':'.$serverId.':';
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }
}
