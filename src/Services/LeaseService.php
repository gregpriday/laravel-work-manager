<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Events\WorkItemHeartbeat;
use GregPriday\WorkManager\Events\WorkItemLeased;
use GregPriday\WorkManager\Events\WorkItemLeaseExpired;
use GregPriday\WorkManager\Exceptions\LeaseConflictException;
use GregPriday\WorkManager\Exceptions\LeaseExpiredException;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use Illuminate\Support\Facades\DB;

/**
 * Manages exclusive TTL leases with heartbeat extensions and reclaim logic.
 * Invariants: one agent per item; uses row-level locks to prevent races.
 *
 * @internal Service layer
 *
 * @see docs/concepts/architecture-overview.md
 */
class LeaseService
{
    public function __construct(
        protected StateMachine $stateMachine
    ) {}

    /**
     * Lock item FOR UPDATE, check leasable state, set TTL lease, transition to LEASED, record event.
     *
     * @throws LeaseConflictException When already leased or not in leasable state
     */
    public function acquire(string $itemId, string $agentId): WorkItem
    {
        return DB::transaction(function () use ($itemId, $agentId) {
            // Use SELECT FOR UPDATE to prevent race conditions
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check if already leased and not expired
            if ($item->isLeased()) {
                throw new LeaseConflictException;
            }

            // Check if item is in a leasable state
            if (! in_array($item->state->value, ['queued', 'in_progress'])) {
                throw new LeaseConflictException('Item is not in a leasable state');
            }

            // Acquire the lease
            $ttl = config('work-manager.lease.ttl_seconds');
            $item->leased_by_agent_id = $agentId;
            $item->lease_expires_at = now()->addSeconds($ttl);
            $item->last_heartbeat_at = now();

            // Transition to leased state if currently queued
            if ($item->state === ItemState::QUEUED) {
                $item->state = ItemState::LEASED;
            }

            $item->save();

            // Record event
            $this->stateMachine->recordItemEvent(
                $item,
                EventType::LEASED,
                ActorType::AGENT,
                $agentId,
                ['lease_expires_at' => $item->lease_expires_at->toIso8601String()]
            );

            event(new WorkItemLeased($item));

            return $item;
        });
    }

    /**
     * Verify ownership, update TTL and last_heartbeat_at, record HEARTBEAT event.
     *
     * @throws LeaseConflictException When agent doesn't own lease
     * @throws LeaseExpiredException When lease already expired
     */
    public function extend(string $itemId, string $agentId): WorkItem
    {
        return DB::transaction(function () use ($itemId, $agentId) {
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Verify the agent owns this lease
            if ($item->leased_by_agent_id !== $agentId) {
                throw new LeaseConflictException('This item is leased by a different agent');
            }

            // Check if lease has expired
            if ($item->isLeaseExpired()) {
                throw new LeaseExpiredException;
            }

            // Extend the lease
            $ttl = config('work-manager.lease.ttl_seconds');
            $item->lease_expires_at = now()->addSeconds($ttl);
            $item->last_heartbeat_at = now();
            $item->save();

            // Record event
            $this->stateMachine->recordItemEvent(
                $item,
                EventType::HEARTBEAT,
                ActorType::AGENT,
                $agentId,
                ['lease_expires_at' => $item->lease_expires_at->toIso8601String()]
            );

            event(new WorkItemHeartbeat($item));

            return $item;
        });
    }

    /**
     * Verify ownership, clear lease fields, transition LEASED→QUEUED, record RELEASED event.
     *
     * @throws LeaseConflictException When agent doesn't own lease
     */
    public function release(string $itemId, string $agentId): WorkItem
    {
        return DB::transaction(function () use ($itemId, $agentId) {
            $item = WorkItem::where('id', $itemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Verify the agent owns this lease
            if ($item->leased_by_agent_id !== $agentId) {
                throw new LeaseConflictException('This item is leased by a different agent');
            }

            // Release the lease
            $item->leased_by_agent_id = null;
            $item->lease_expires_at = null;
            $item->last_heartbeat_at = null;

            // Transition back to queued if currently leased
            if ($item->state === ItemState::LEASED) {
                $item->state = ItemState::QUEUED;
            }

            $item->save();

            // Record event
            $this->stateMachine->recordItemEvent(
                $item,
                EventType::RELEASED,
                ActorType::AGENT,
                $agentId
            );

            return $item;
        });
    }

    /**
     * Find expired leases, increment attempts, reset to QUEUED (or FAILED if max attempts); record events.
     *
     * @return int Number of items reclaimed/failed
     */
    public function reclaimExpired(): int
    {
        $items = WorkItem::withExpiredLease()->get();
        $count = 0;

        foreach ($items as $item) {
            DB::transaction(function () use ($item, &$count) {
                $item = WorkItem::where('id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if (! $item || ! $item->isLeaseExpired()) {
                    return;
                }

                $item->attempts++;

                // Check if max attempts reached
                if ($item->hasExhaustedAttempts()) {
                    $item->state = ItemState::FAILED;
                    $item->leased_by_agent_id = null;
                    $item->lease_expires_at = null;
                    $item->error = [
                        'code' => 'max_attempts_exceeded',
                        'message' => 'Maximum retry attempts exceeded',
                    ];
                } else {
                    // Reset lease and return to queued
                    $item->leased_by_agent_id = null;
                    $item->lease_expires_at = null;
                    $item->state = ItemState::QUEUED;
                }

                $item->save();

                // Record event
                $this->stateMachine->recordItemEvent(
                    $item,
                    EventType::LEASE_EXPIRED,
                    ActorType::SYSTEM,
                    null,
                    ['attempts' => $item->attempts]
                );

                event(new WorkItemLeaseExpired($item));

                $count++;
            });
        }

        return $count;
    }

    /**
     * Get the next available item for checkout.
     */
    public function getNextAvailable(string $orderId): ?WorkItem
    {
        return WorkItem::where('order_id', $orderId)
            ->availableForLease()
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Acquire the next available work item across all orders (global checkout).
     *
     * @param  string  $agentId  The agent requesting work
     * @param  array{type?:string,min_priority?:int,tenant_id?:string}  $filters  Optional filters
     * @return WorkItem|null The acquired item, or null if none available
     */
    public function acquireNextAvailable(string $agentId, array $filters = []): ?WorkItem
    {
        // Check per-agent concurrency limit outside transaction
        if (! $this->canAgentAcquireMore($agentId)) {
            return null;
        }

        // Find the best available item
        $query = WorkItem::query()
            ->join('work_orders', 'work_items.order_id', '=', 'work_orders.id')
            ->where('work_items.state', 'queued')
            ->where(function ($q) {
                $q->whereNull('work_items.lease_expires_at')
                    ->orWhere('work_items.lease_expires_at', '<', now());
            });

        // Apply type filter
        if (! empty($filters['type'])) {
            $query->where('work_orders.type', $filters['type']);
        }

        // Apply minimum priority filter
        if (isset($filters['min_priority'])) {
            $query->where('work_orders.priority', '>=', $filters['min_priority']);
        }

        // Apply tenant filter (JSON contains on payload->tenant_id)
        if (! empty($filters['tenant_id'])) {
            $query->whereJsonContains('work_orders.payload->tenant_id', $filters['tenant_id']);
        }

        // Global scheduling: highest priority first, then FIFO within priority
        $item = $query
            ->select('work_items.*', 'work_orders.type as order_type')
            ->orderByDesc('work_orders.priority')
            ->orderBy('work_items.created_at')
            ->first();

        if (! $item) {
            return null;
        }

        // Check per-type concurrency limit
        if (! $this->canTypeAcquireMore($item->order_type)) {
            return null;
        }

        // Now use the existing acquire() method to actually lease the item
        // This ensures we reuse tested logic and maintain consistency
        try {
            return $this->acquire($item->id, $agentId);
        } catch (\Exception $e) {
            // If acquisition fails (e.g., race condition), return null
            return null;
        }
    }

    /**
     * Check if an agent can acquire more leases.
     *
     * @param  string  $agentId  The agent identifier
     * @return bool True if the agent can acquire more leases
     */
    protected function canAgentAcquireMore(string $agentId): bool
    {
        $max = config('work-manager.lease.max_leases_per_agent');
        if (! $max) {
            return true;
        }

        $current = WorkItem::where('leased_by_agent_id', $agentId)
            ->where('lease_expires_at', '>', now())
            ->count();

        return $current < $max;
    }

    /**
     * Check if an order type can have more leases.
     *
     * @param  string  $orderType  The order type identifier
     * @return bool True if the type can have more leases
     */
    protected function canTypeAcquireMore(string $orderType): bool
    {
        $max = config('work-manager.lease.max_leases_per_type');
        if (! $max) {
            return true;
        }

        $current = WorkItem::join('work_orders', 'work_items.order_id', '=', 'work_orders.id')
            ->where('work_orders.type', $orderType)
            ->where('work_items.lease_expires_at', '>', now())
            ->count();

        return $current < $max;
    }
}
