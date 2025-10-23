<?php

use GregPriday\WorkManager\Exceptions\LeaseConflictException;
use GregPriday\WorkManager\Exceptions\LeaseExpiredException;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Support\Carbon;

it('throws LeaseExpiredException when extending expired lease', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Acquire lease
    $leaseService->acquire($item->id, 'agent-1');

    // Manually expire the lease
    $item->fresh()->update([
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    // Try to extend expired lease
    expect(fn () => $leaseService->extend($item->id, 'agent-1'))
        ->toThrow(LeaseExpiredException::class);
});

it('throws LeaseExpiredException when heartbeat on expired lease', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Acquire lease
    $leaseService->acquire($item->id, 'agent-1');

    // Manually expire the lease
    $item->fresh()->update([
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    // Heartbeat should fail on expired lease
    expect(fn () => $leaseService->extend($item->id, 'agent-1'))
        ->toThrow(LeaseExpiredException::class);
});

it('throws LeaseConflictException when acquiring already leased item', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Agent 1 acquires lease
    $leaseService->acquire($item->id, 'agent-1');

    // Agent 2 tries to acquire same item
    expect(fn () => $leaseService->acquire($item->id, 'agent-2'))
        ->toThrow(LeaseConflictException::class);
});

it('throws LeaseConflictException when extending lease held by different agent', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Agent 1 acquires lease
    $leaseService->acquire($item->id, 'agent-1');

    // Agent 2 tries to extend
    expect(fn () => $leaseService->extend($item->id, 'agent-2'))
        ->toThrow(LeaseConflictException::class);
});

it('throws LeaseConflictException when releasing lease held by different agent', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Agent 1 acquires lease
    $leaseService->acquire($item->id, 'agent-1');

    // Agent 2 tries to release
    expect(fn () => $leaseService->release($item->id, 'agent-2'))
        ->toThrow(LeaseConflictException::class);
});

it('only allows acquire from queued or in_progress state', function () {
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    // Test various states
    $invalidStates = [
        ItemState::COMPLETED,
        ItemState::ACCEPTED,
        ItemState::SUBMITTED,
        ItemState::REJECTED,
        ItemState::FAILED,
        ItemState::DEAD_LETTERED,
    ];

    foreach ($invalidStates as $state) {
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.echo',
            'state' => $state,
            'input' => ['test' => 'data'],
            'max_attempts' => 3,
        ]);

        expect(fn () => $leaseService->acquire($item->id, 'agent-1'))
            ->toThrow(LeaseConflictException::class);
    }
});

it('allows acquire from queued state', function () {
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['test' => 'data'],
        'max_attempts' => 3,
    ]);

    $result = $leaseService->acquire($item->id, 'agent-1');

    expect($result->state)->toBe(ItemState::LEASED);
    expect($result->leased_by_agent_id)->toBe('agent-1');
    expect($result->lease_expires_at)->not->toBeNull();
});

it('reclaims expired leases', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Acquire lease
    $leaseService->acquire($item->id, 'agent-1');

    // Manually expire the lease
    $item->fresh()->update([
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    // Reclaim expired leases
    $reclaimed = $leaseService->reclaimExpired();

    expect($reclaimed)->toBeGreaterThan(0);

    // Verify item is back to queued
    expect($item->fresh()->state)->toBe(ItemState::QUEUED);
    expect($item->fresh()->leased_by_agent_id)->toBeNull();
    expect($item->fresh()->lease_expires_at)->toBeNull();
});

it('increments attempts when reclaiming expired lease', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();
    $initialAttempts = $item->attempts;

    // Acquire lease
    $leaseService->acquire($item->id, 'agent-1');

    // Manually expire the lease
    $item->fresh()->update([
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    // Reclaim
    $leaseService->reclaimExpired();

    // Verify attempts incremented
    expect($item->fresh()->attempts)->toBe($initialAttempts + 1);
});

it('transitions to failed when max attempts reached on reclaim', function () {
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['test' => 'data'],
        'max_attempts' => 2,
        'attempts' => 1, // Already tried once
    ]);

    // Acquire lease
    $leaseService->acquire($item->id, 'agent-1');

    // Manually expire the lease
    $item->fresh()->update([
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    // Reclaim - should mark as failed since attempts will reach max_attempts
    $leaseService->reclaimExpired();

    expect($item->fresh()->state)->toBe(ItemState::FAILED);
});

it('extends lease with correct TTL', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Acquire lease
    Carbon::setTestNow(now());
    $leased = $leaseService->acquire($item->id, 'agent-1');
    $firstExpiry = $leased->lease_expires_at;

    // Advance time
    Carbon::setTestNow(now()->addSecond());

    // Extend lease
    $extended = $leaseService->extend($item->id, 'agent-1');

    // New expiry should be later than original
    expect($extended->lease_expires_at->gt($firstExpiry))->toBeTrue();

    // Should be approximately TTL seconds from now
    $ttl = config('work-manager.lease.ttl_seconds');
    $expectedExpiry = now()->addSeconds($ttl);

    expect($extended->lease_expires_at->diffInSeconds($expectedExpiry, false))->toBeLessThan(5);

    Carbon::setTestNow(); // Reset
});

it('updates last_heartbeat_at when extending lease', function () {
    $leaseService = app(LeaseService::class);
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Acquire lease
    Carbon::setTestNow(now());
    $leaseService->acquire($item->id, 'agent-1');

    $firstHeartbeat = $item->fresh()->last_heartbeat_at;

    // Advance time
    Carbon::setTestNow(now()->addSecond());

    // Extend lease (heartbeat)
    $leaseService->extend($item->id, 'agent-1');

    $secondHeartbeat = $item->fresh()->last_heartbeat_at;

    expect($secondHeartbeat)->not->toBeNull();
    if ($firstHeartbeat !== null) {
        expect($secondHeartbeat->gt($firstHeartbeat))->toBeTrue();
    }

    Carbon::setTestNow(); // Reset
});
