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

class LeaseService
{
    public function __construct(
        protected StateMachine $stateMachine
    ) {
    }

    /**
     * Attempt to acquire a lease on a work item.
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
                throw new LeaseConflictException();
            }

            // Check if item is in a leasable state
            if (!in_array($item->state->value, ['queued', 'in_progress'])) {
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
     * Extend an existing lease (heartbeat).
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
                throw new LeaseExpiredException();
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
     * Release a lease explicitly.
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
     * Reclaim expired leases (called by maintenance).
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

                if (!$item || !$item->isLeaseExpired()) {
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
}
