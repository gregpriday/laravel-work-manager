<?php

namespace GregPriday\WorkManager\Services\Backends;

use GregPriday\WorkManager\Contracts\LeaseBackend;
use GregPriday\WorkManager\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed lease implementation using row-level locks.
 *
 * Benefits:
 * - No additional infrastructure required
 * - Transactional consistency with work items
 * - Simpler deployment
 *
 * Drawbacks vs Redis:
 * - Higher database load
 * - Slower operations (50ms+ vs <5ms)
 * - Potential contention at scale
 *
 * Usage:
 * Set 'lease.backend' => 'database' in config/work-manager.php (default)
 */
class DatabaseLeaseBackend implements LeaseBackend
{
    /**
     * Attempt to acquire a lease.
     *
     * @param  string  $key  Lease key (typically "item:{item_id}")
     * @param  string  $owner  Owner identifier (agent ID)
     * @param  int  $ttl  Time to live in seconds
     * @return bool True if lease acquired, false if already held
     */
    public function acquire(string $key, string $owner, int $ttl): bool
    {
        $itemId = $this->extractItemId($key);

        return DB::transaction(function () use ($itemId, $owner, $ttl) {
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                return false;
            }

            // Check if already leased and not expired
            if ($item->leased_by_agent_id && ! $item->isLeaseExpired()) {
                return false;
            }

            // Acquire lease
            $item->leased_by_agent_id = $owner;
            $item->lease_expires_at = now()->addSeconds($ttl);
            $item->save();

            return true;
        });
    }

    /**
     * Extend an existing lease.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @param  int  $ttl  New time to live in seconds
     * @return bool True if extended, false if not owned or expired
     */
    public function extend(string $key, string $owner, int $ttl): bool
    {
        $itemId = $this->extractItemId($key);

        return DB::transaction(function () use ($itemId, $owner, $ttl) {
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item || $item->leased_by_agent_id !== $owner) {
                return false;
            }

            // Extend lease
            $item->lease_expires_at = now()->addSeconds($ttl);
            $item->save();

            return true;
        });
    }

    /**
     * Release a lease.
     *
     * @param  string  $key  Lease key
     * @param  string  $owner  Owner identifier (must match current owner)
     * @return bool True if released, false if not owned
     */
    public function release(string $key, string $owner): bool
    {
        $itemId = $this->extractItemId($key);

        return DB::transaction(function () use ($itemId, $owner) {
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item || $item->leased_by_agent_id !== $owner) {
                return false;
            }

            // Release lease
            $item->leased_by_agent_id = null;
            $item->lease_expires_at = null;
            $item->save();

            return true;
        });
    }

    /**
     * Reclaim expired leases.
     *
     * @param  array  $expiredKeys  Keys to reclaim
     * @return int Number reclaimed
     */
    public function reclaim(array $expiredKeys): int
    {
        $itemIds = array_map([$this, 'extractItemId'], $expiredKeys);

        return WorkItem::whereIn('id', $itemIds)
            ->whereNotNull('leased_by_agent_id')
            ->where('lease_expires_at', '<', now())
            ->update([
                'leased_by_agent_id' => null,
                'lease_expires_at' => null,
            ]);
    }

    /**
     * Extract item ID from lease key.
     *
     * @param  string  $key  Lease key (e.g., "item:uuid" or "uuid")
     * @return string Item ID
     */
    protected function extractItemId(string $key): string
    {
        // Handle both "item:uuid" and "uuid" formats
        if (str_starts_with($key, 'item:')) {
            return substr($key, 5);
        }

        return $key;
    }
}
