<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Models\WorkItem;

interface LeaseBackend
{
    /**
     * Attempt to acquire a lease on a work item.
     * Returns true if successful, false if already leased.
     */
    public function acquire(WorkItem $item, string $agentId, int $ttlSeconds): bool;

    /**
     * Extend an existing lease (heartbeat).
     */
    public function extend(WorkItem $item, int $ttlSeconds): bool;

    /**
     * Release a lease explicitly.
     */
    public function release(WorkItem $item): bool;

    /**
     * Check if a lease has expired.
     */
    public function isExpired(WorkItem $item): bool;

    /**
     * Reclaim expired leases (called by maintenance).
     * Returns the number of leases reclaimed.
     */
    public function reclaimExpired(): int;
}
