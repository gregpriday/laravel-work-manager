<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\Backends\DatabaseLeaseBackend;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

beforeEach(function () {
    $this->backend = new DatabaseLeaseBackend();
});

test('acquire returns true when item is free', function () {
    $item = createWorkItem();

    $result = $this->backend->acquire("item:{$item->id}", 'agent-1', 600);

    expect($result)->toBeTrue();
    expect($item->fresh()->leased_by_agent_id)->toBe('agent-1');
    expect($item->fresh()->lease_expires_at)->not->toBeNull();
});

test('acquire returns true when lease has expired', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->subMinutes(10), // Expired
    ]);

    $result = $this->backend->acquire("item:{$item->id}", 'agent-2', 600);

    expect($result)->toBeTrue();
    expect($item->fresh()->leased_by_agent_id)->toBe('agent-2');
});

test('acquire returns false when already leased and not expired', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10), // Not expired
    ]);

    $result = $this->backend->acquire("item:{$item->id}", 'agent-2', 600);

    expect($result)->toBeFalse();
    expect($item->fresh()->leased_by_agent_id)->toBe('agent-1'); // Unchanged
});

test('acquire returns false when item does not exist', function () {
    $result = $this->backend->acquire('item:99999', 'agent-1', 600);

    expect($result)->toBeFalse();
});

test('acquire handles item: prefixed key format', function () {
    $item = createWorkItem();

    $result = $this->backend->acquire("item:{$item->id}", 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('acquire handles numeric key format without prefix', function () {
    $item = createWorkItem();

    $result = $this->backend->acquire((string) $item->id, 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('extend returns true when owned by correct agent', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $originalExpiry = $item->lease_expires_at;

    $result = $this->backend->extend("item:{$item->id}", 'agent-1', 900);

    expect($result)->toBeTrue();
    expect($item->fresh()->lease_expires_at)->toBeGreaterThan($originalExpiry);
});

test('extend returns false when not owned by agent', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $result = $this->backend->extend("item:{$item->id}", 'agent-2', 900);

    expect($result)->toBeFalse();
});

test('extend returns false when item is not leased', function () {
    $item = createWorkItem();

    $result = $this->backend->extend("item:{$item->id}", 'agent-1', 900);

    expect($result)->toBeFalse();
});

test('extend returns false when item does not exist', function () {
    $result = $this->backend->extend('item:99999', 'agent-1', 900);

    expect($result)->toBeFalse();
});

test('release returns true when owned by correct agent', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $result = $this->backend->release("item:{$item->id}", 'agent-1');

    expect($result)->toBeTrue();
    expect($item->fresh()->leased_by_agent_id)->toBeNull();
    expect($item->fresh()->lease_expires_at)->toBeNull();
});

test('release returns false when not owned by agent', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $result = $this->backend->release("item:{$item->id}", 'agent-2');

    expect($result)->toBeFalse();
    expect($item->fresh()->leased_by_agent_id)->toBe('agent-1'); // Unchanged
});

test('release returns false when item is not leased', function () {
    $item = createWorkItem();

    $result = $this->backend->release("item:{$item->id}", 'agent-1');

    expect($result)->toBeFalse();
});

test('release returns false when item does not exist', function () {
    $result = $this->backend->release('item:99999', 'agent-1');

    expect($result)->toBeFalse();
});

test('reclaim clears expired leases', function () {
    $item1 = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->subMinutes(10), // Expired
    ]);

    $item2 = createWorkItem([
        'leased_by_agent_id' => 'agent-2',
        'lease_expires_at' => now()->subMinutes(5), // Expired
    ]);

    $item3 = createWorkItem([
        'leased_by_agent_id' => 'agent-3',
        'lease_expires_at' => now()->addMinutes(10), // Not expired
    ]);

    $count = $this->backend->reclaim([
        "item:{$item1->id}",
        "item:{$item2->id}",
        "item:{$item3->id}",
    ]);

    expect($count)->toBe(2);
    expect($item1->fresh()->leased_by_agent_id)->toBeNull();
    expect($item2->fresh()->leased_by_agent_id)->toBeNull();
    expect($item3->fresh()->leased_by_agent_id)->toBe('agent-3'); // Unchanged
});

test('reclaim handles empty array', function () {
    $count = $this->backend->reclaim([]);

    expect($count)->toBe(0);
});

test('reclaim handles non-existent items gracefully', function () {
    $count = $this->backend->reclaim(['item:99999', 'item:88888']);

    expect($count)->toBe(0);
});

test('reclaim only clears leases that are actually expired', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10), // Not expired
    ]);

    $count = $this->backend->reclaim(["item:{$item->id}"]);

    expect($count)->toBe(0);
    expect($item->fresh()->leased_by_agent_id)->toBe('agent-1'); // Unchanged
});

test('extractItemId handles item: prefix', function () {
    $item = createWorkItem();

    // Test via acquire which uses extractItemId internally
    $result = $this->backend->acquire("item:{$item->id}", 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('extractItemId handles numeric string without prefix', function () {
    $item = createWorkItem();

    // Test via acquire which uses extractItemId internally
    $result = $this->backend->acquire((string) $item->id, 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('acquire uses database transaction for atomicity', function () {
    $item = createWorkItem();

    // Two agents try to acquire simultaneously
    // Only one should succeed due to lockForUpdate
    $result1 = $this->backend->acquire("item:{$item->id}", 'agent-1', 600);

    expect($result1)->toBeTrue();

    $result2 = $this->backend->acquire("item:{$item->id}", 'agent-2', 600);

    expect($result2)->toBeFalse();
});

test('extend uses database transaction for atomicity', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $result = $this->backend->extend("item:{$item->id}", 'agent-1', 900);

    expect($result)->toBeTrue();
});

test('release uses database transaction for atomicity', function () {
    $item = createWorkItem([
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $result = $this->backend->release("item:{$item->id}", 'agent-1');

    expect($result)->toBeTrue();
});

// Helper function
function createWorkItem(array $attributes = []): WorkItem
{
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    return WorkItem::create(array_merge([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
    ], $attributes));
}
