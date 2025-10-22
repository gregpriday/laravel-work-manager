<?php

namespace GregPriday\WorkManager\Contracts;

interface LeaseBackend
{
    /**
     * Attempt to acquire a lease.
     *
     * @param  string  $key  Lease key (typically "item:{item_id}")
     * @param  string  $owner  Owner identifier (agent ID)
     * @param  int  $ttl  Time to live in seconds
     * @return bool True if lease acquired, false if already held
     */
    public function acquire(string $key, string $owner, int $ttl): bool;

    /**
     * Extend an existing lease (heartbeat).
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @param  int  $ttl  New time to live in seconds
     * @return bool True if extended, false if not owned or expired
     */
    public function extend(string $key, string $owner, int $ttl): bool;

    /**
     * Release a lease explicitly.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @return bool True if released, false if not owned
     */
    public function release(string $key, string $owner): bool;

    /**
     * Reclaim expired leases.
     *
     * @param  array  $expiredKeys  Keys to reclaim
     * @return int Number of leases reclaimed
     */
    public function reclaim(array $expiredKeys): int;
}
