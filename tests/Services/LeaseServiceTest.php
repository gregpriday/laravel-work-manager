<?php

use GregPriday\WorkManager\Exceptions\LeaseConflictException;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Support\ItemState;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->leaseService = app(LeaseService::class);
});

it('acquires lease from queued state', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $leased = $this->leaseService->acquire($item->id, 'agent-1');

    expect($leased->state)->toBe(ItemState::LEASED);
    expect($leased->leased_by_agent_id)->toBe('agent-1');
    expect($leased->lease_expires_at)->not->toBeNull();
    expect($leased->lease_expires_at->isFuture())->toBeTrue();
});

it('throws conflict when acquiring already leased item', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $this->leaseService->acquire($item->id, 'agent-1');

    $this->leaseService->acquire($item->id, 'agent-2');
})->throws(LeaseConflictException::class);

it('extends lease for same agent', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $leased = $this->leaseService->acquire($item->id, 'agent-1');
    $originalExpiry = $leased->lease_expires_at;

    Carbon::setTestNow(now()->addSeconds(30));

    $extended = $this->leaseService->extend($item->id, 'agent-1');

    expect($extended->lease_expires_at->greaterThan($originalExpiry))->toBeTrue();
    expect($extended->last_heartbeat_at)->not->toBeNull();

    Carbon::setTestNow();
});

it('throws conflict when extending lease with different agent', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $this->leaseService->acquire($item->id, 'agent-1');
    $this->leaseService->extend($item->id, 'agent-2');
})->throws(LeaseConflictException::class);

it('releases lease and returns item to queued', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $leased = $this->leaseService->acquire($item->id, 'agent-1');
    $released = $this->leaseService->release($item->id, 'agent-1');

    expect($released->state)->toBe(ItemState::QUEUED);
    expect($released->leased_by_agent_id)->toBeNull();
    expect($released->lease_expires_at)->toBeNull();
});

it('reclaims expired leases', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
        'max_attempts' => 2,
    ]);

    // Acquire and expire the lease
    $leased = $this->leaseService->acquire($item->id, 'agent-1');
    $leased->update(['lease_expires_at' => now()->subMinutes(10)]);

    $reclaimedCount = $this->leaseService->reclaimExpired();

    expect($reclaimedCount)->toBe(1);

    $item->refresh();
    expect($item->state)->toBe(ItemState::QUEUED);
    expect($item->attempts)->toBe(1);
    expect($item->leased_by_agent_id)->toBeNull();
});

it('marks items as failed after max attempts', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::LEASED,
        'input' => ['message' => 'test'],
        'attempts' => 1,
        'max_attempts' => 2,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->subMinutes(10),
    ]);

    $reclaimedCount = $this->leaseService->reclaimExpired();

    expect($reclaimedCount)->toBe(1);

    $item->refresh();
    expect($item->state)->toBe(ItemState::FAILED);
    expect($item->error)->not->toBeNull();
    expect($item->error['code'])->toBe('max_attempts_exceeded');
});
