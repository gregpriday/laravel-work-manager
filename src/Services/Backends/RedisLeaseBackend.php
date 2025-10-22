<?php

namespace GregPriday\WorkManager\Services\Backends;

use GregPriday\WorkManager\Contracts\LeaseBackend;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed lease implementation using SET NX EX pattern.
 *
 * Benefits over database backend:
 * - Reduced database contention
 * - Faster lease operations (< 5ms vs 50ms+)
 * - Native TTL support
 * - Better horizontal scaling
 *
 * Usage:
 * Set 'lease.backend' => 'redis' in config/work-manager.php
 */
class RedisLeaseBackend implements LeaseBackend
{
    protected string $connection;

    protected string $prefix;

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? config('work-manager.lease.redis_connection', 'default');
        $this->prefix = config('work-manager.lease.redis_prefix', 'work:lease:');
    }

    /**
     * Attempt to acquire a lease.
     *
     * Uses Redis SET with NX (only set if not exists) and EX (expiration) flags.
     *
     * @param  string  $key  Lease key (typically "item:{item_id}")
     * @param  string  $owner  Owner identifier (agent ID)
     * @param  int  $ttl  Time to live in seconds
     * @return bool True if lease acquired, false if already held
     */
    public function acquire(string $key, string $owner, int $ttl): bool
    {
        $fullKey = $this->prefix.$key;

        // SET key value EX ttl NX
        // Returns true if set, false if key already exists
        $result = Redis::connection($this->connection)->set(
            $fullKey,
            $owner,
            'EX',
            $ttl,
            'NX'
        );

        return $result === true;
    }

    /**
     * Extend an existing lease.
     *
     * Only succeeds if the current owner holds the lease.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @param  int  $ttl  New time to live in seconds
     * @return bool True if extended, false if not owned or expired
     */
    public function extend(string $key, string $owner, int $ttl): bool
    {
        $fullKey = $this->prefix.$key;

        // Verify ownership then extend using Lua script (atomic)
        $script = <<<'LUA'
            if redis.call("get", KEYS[1]) == ARGV[1] then
                redis.call("expire", KEYS[1], ARGV[2])
                return 1
            else
                return 0
            end
        LUA;

        $result = Redis::connection($this->connection)->eval(
            $script,
            1, // number of keys
            $fullKey,
            $owner,
            $ttl
        );

        return $result === 1;
    }

    /**
     * Release a lease.
     *
     * Only succeeds if the current owner holds the lease.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @return bool True if released, false if not owned or already expired
     */
    public function release(string $key, string $owner): bool
    {
        $fullKey = $this->prefix.$key;

        // Delete only if owner matches using Lua script (atomic)
        $script = <<<'LUA'
            if redis.call("get", KEYS[1]) == ARGV[1] then
                redis.call("del", KEYS[1])
                return 1
            else
                return 0
            end
        LUA;

        $result = Redis::connection($this->connection)->eval(
            $script,
            1, // number of keys
            $fullKey,
            $owner
        );

        return $result === 1;
    }

    /**
     * Reclaim expired leases.
     *
     * For Redis backend, leases expire automatically via TTL.
     * This method is a no-op for Redis but required by interface.
     *
     * @param  array  $expiredKeys  Keys to reclaim (ignored for Redis)
     * @return int Number reclaimed (always 0 for Redis - handled by TTL)
     */
    public function reclaim(array $expiredKeys): int
    {
        // Redis handles expiration automatically via TTL
        // No manual cleanup needed
        return 0;
    }

    /**
     * Get the current owner of a lease.
     *
     * @param  string  $key  Lease key
     * @return string|null Owner identifier or null if not leased
     */
    public function getOwner(string $key): ?string
    {
        $fullKey = $this->prefix.$key;

        $owner = Redis::connection($this->connection)->get($fullKey);

        return $owner ?: null;
    }

    /**
     * Get remaining TTL for a lease.
     *
     * @param  string  $key  Lease key
     * @return int|null Seconds remaining or null if not leased
     */
    public function getTtl(string $key): ?int
    {
        $fullKey = $this->prefix.$key;

        $ttl = Redis::connection($this->connection)->ttl($fullKey);

        // -2 means key doesn't exist, -1 means no expiration set
        return $ttl > 0 ? $ttl : null;
    }

    /**
     * Check if a lease is held by a specific owner.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier to check
     * @return bool True if lease is held by owner
     */
    public function isHeldBy(string $key, string $owner): bool
    {
        return $this->getOwner($key) === $owner;
    }

    /**
     * Get all active leases (for monitoring/debugging).
     *
     * Warning: This scans keys and can be slow. Use sparingly.
     *
     * @return array Array of [key => owner]
     */
    public function getAllLeases(): array
    {
        $pattern = $this->prefix.'*';

        $keys = Redis::connection($this->connection)->keys($pattern);

        $leases = [];
        foreach ($keys as $fullKey) {
            $key = substr($fullKey, strlen($this->prefix));
            $leases[$key] = Redis::connection($this->connection)->get($fullKey);
        }

        return $leases;
    }

    /**
     * Clear all leases (for testing only).
     *
     * @return int Number of leases cleared
     */
    public function clearAll(): int
    {
        $pattern = $this->prefix.'*';

        $keys = Redis::connection($this->connection)->keys($pattern);

        if (empty($keys)) {
            return 0;
        }

        return Redis::connection($this->connection)->del(...$keys);
    }
}
