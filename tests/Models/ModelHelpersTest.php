<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkItemPart;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Support\PartStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helper functions
function createTestOrder(array $attributes = []): WorkOrder
{
    return WorkOrder::create(array_merge([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ], $attributes));
}

function createTestItem(WorkOrder $order, array $attributes = []): WorkItem
{
    return WorkItem::create(array_merge([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
        'max_attempts' => 3,
    ], $attributes));
}

function createTestPart(WorkItem $item, array $attributes = []): WorkItemPart
{
    return WorkItemPart::create(array_merge([
        'work_item_id' => $item->id,
        'part_key' => 'test',
        'seq' => 1,
        'status' => PartStatus::DRAFT,
        'payload' => ['key' => 'value'],
        'submitted_by_agent_id' => 'agent-1',
    ], $attributes));
}

// WorkItemPart helper methods
test('WorkItemPart::isValidated returns true when status is validated', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part = createTestPart($item, ['status' => PartStatus::VALIDATED]);

    expect($part->isValidated())->toBeTrue();
});

test('WorkItemPart::isValidated returns false when status is not validated', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part = createTestPart($item, ['status' => PartStatus::DRAFT]);

    expect($part->isValidated())->toBeFalse();
});

test('WorkItemPart::isRejected returns true when status is rejected', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part = createTestPart($item, ['status' => PartStatus::REJECTED]);

    expect($part->isRejected())->toBeTrue();
});

test('WorkItemPart::isRejected returns false when status is not rejected', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part = createTestPart($item, ['status' => PartStatus::VALIDATED]);

    expect($part->isRejected())->toBeFalse();
});

test('WorkItemPart::generateChecksum returns consistent sha256 hash', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part = createTestPart($item, ['payload' => ['key' => 'value', 'number' => 42]]);

    $checksum1 = $part->generateChecksum();
    $checksum2 = $part->generateChecksum();

    expect($checksum1)
        ->toBe($checksum2)
        ->toMatch('/^[a-f0-9]{64}$/');
});

test('WorkItemPart::generateChecksum returns different hashes for different payloads', function () {
    $order = createTestOrder();
    $item = createTestItem($order);
    $part1 = createTestPart($item, ['payload' => ['key' => 'value1'], 'part_key' => 'part1']);
    $part2 = createTestPart($item, ['payload' => ['key' => 'value2'], 'part_key' => 'part2']);

    expect($part1->generateChecksum())->not->toBe($part2->generateChecksum());
});

test('WorkItemPart::generateChecksum handles empty payload', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    $part1 = createTestPart($item, ['payload' => []]);
    expect($part1->generateChecksum())->toMatch('/^[a-f0-9]{64}$/');
});

test('WorkItemPart::scopeValidated filters only validated parts', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    createTestPart($item, ['status' => PartStatus::VALIDATED, 'part_key' => 'part1']);
    createTestPart($item, ['status' => PartStatus::DRAFT, 'part_key' => 'part2']);
    createTestPart($item, ['status' => PartStatus::REJECTED, 'part_key' => 'part3']);

    $validated = WorkItemPart::query()->validated()->get();

    expect($validated)->toHaveCount(1);
    expect($validated->first()->status)->toBe(PartStatus::VALIDATED);
});

test('WorkItemPart::scopeForKey filters parts by key', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    createTestPart($item, ['part_key' => 'research']);
    createTestPart($item, ['part_key' => 'analysis']);

    $research = WorkItemPart::query()->forKey('research')->get();

    expect($research)->toHaveCount(1);
    expect($research->first()->part_key)->toBe('research');
});

test('WorkItemPart::scopeLatestPerKey returns latest part for each key', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    // Create multiple versions of the same key
    createTestPart($item, ['part_key' => 'research', 'seq' => 1]);
    createTestPart($item, ['part_key' => 'research', 'seq' => 2]);
    createTestPart($item, ['part_key' => 'analysis', 'seq' => 1]);

    $latestParts = WorkItemPart::query()->latestPerKey($item->id)->get();

    expect($latestParts)->toHaveCount(2);
    expect($latestParts->pluck('part_key')->unique()->count())->toBe(2);
});

// WorkItem helper methods
test('WorkItem::isTerminal returns true when state is terminal', function () {
    $order = createTestOrder();

    $item1 = createTestItem($order, ['state' => ItemState::COMPLETED]);
    expect($item1->isTerminal())->toBeTrue();

    $item2 = createTestItem($order, ['state' => ItemState::DEAD_LETTERED]);
    expect($item2->isTerminal())->toBeTrue();
});

test('WorkItem::isTerminal returns false when state is not terminal', function () {
    $order = createTestOrder();

    $item1 = createTestItem($order, ['state' => ItemState::QUEUED]);
    expect($item1->isTerminal())->toBeFalse();

    $item2 = createTestItem($order, ['state' => ItemState::IN_PROGRESS]);
    expect($item2->isTerminal())->toBeFalse();
});

test('WorkItem::isLeaseExpired returns true when lease expired', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['lease_expires_at' => now()->subMinutes(5)]);

    expect($item->isLeaseExpired())->toBeTrue();
});

test('WorkItem::isLeaseExpired returns false when lease not expired', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['lease_expires_at' => now()->addMinutes(5)]);

    expect($item->isLeaseExpired())->toBeFalse();
});

test('WorkItem::isLeaseExpired returns false when no lease', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['lease_expires_at' => null]);

    expect($item->isLeaseExpired())->toBeFalse();
});

test('WorkItem::isLeased returns true when validly leased', function () {
    $order = createTestOrder();
    $item = createTestItem($order, [
        'leased_by_agent_id' => 'agent-123',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    expect($item->isLeased())->toBeTrue();
});

test('WorkItem::isLeased returns false when lease expired', function () {
    $order = createTestOrder();
    $item = createTestItem($order, [
        'leased_by_agent_id' => 'agent-123',
        'lease_expires_at' => now()->subMinutes(5),
    ]);

    expect($item->isLeased())->toBeFalse();
});

test('WorkItem::isLeased returns false when not leased', function () {
    $order = createTestOrder();
    $item = createTestItem($order, [
        'leased_by_agent_id' => null,
        'lease_expires_at' => null,
    ]);

    expect($item->isLeased())->toBeFalse();
});

test('WorkItem::hasExhaustedAttempts returns true when attempts >= max', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['attempts' => 3, 'max_attempts' => 3]);

    expect($item->hasExhaustedAttempts())->toBeTrue();

    $item->attempts = 4;
    expect($item->hasExhaustedAttempts())->toBeTrue();
});

test('WorkItem::hasExhaustedAttempts returns false when attempts < max', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['attempts' => 2, 'max_attempts' => 3]);

    expect($item->hasExhaustedAttempts())->toBeFalse();
});

test('WorkItem::scopeInState filters by ItemState enum', function () {
    $order = createTestOrder();
    createTestItem($order, ['state' => ItemState::QUEUED]);
    createTestItem($order, ['state' => ItemState::LEASED]);
    createTestItem($order, ['state' => ItemState::IN_PROGRESS]);

    $queued = WorkItem::query()->inState(ItemState::QUEUED)->get();
    expect($queued)->toHaveCount(1);
    expect($queued->first()->state)->toBe(ItemState::QUEUED);
});

test('WorkItem::scopeInState filters by state string', function () {
    $order = createTestOrder();
    createTestItem($order, ['state' => ItemState::QUEUED]);
    createTestItem($order, ['state' => ItemState::LEASED]);

    $queued = WorkItem::query()->inState('queued')->get();
    expect($queued)->toHaveCount(1);
});

test('WorkItem::scopeWithExpiredLease finds items with expired leases', function () {
    $order = createTestOrder();
    createTestItem($order, ['lease_expires_at' => now()->subMinutes(5), 'state' => ItemState::LEASED]);
    createTestItem($order, ['lease_expires_at' => now()->addMinutes(5), 'state' => ItemState::LEASED]);
    createTestItem($order, ['lease_expires_at' => now()->subMinutes(5), 'state' => ItemState::COMPLETED]);

    $expired = WorkItem::query()->withExpiredLease()->get();
    expect($expired)->toHaveCount(1);
});

test('WorkItem::scopeAvailableForLease finds queued items without valid leases', function () {
    $order = createTestOrder();
    createTestItem($order, ['state' => ItemState::QUEUED, 'lease_expires_at' => null]);
    createTestItem($order, ['state' => ItemState::QUEUED, 'lease_expires_at' => now()->subMinutes(5)]);
    createTestItem($order, ['state' => ItemState::LEASED, 'lease_expires_at' => now()->addMinutes(5)]);

    $available = WorkItem::query()->availableForLease()->get();
    expect($available)->toHaveCount(2);
});

test('WorkItem::scopeLeasedBy filters by agent ID with valid lease', function () {
    $order = createTestOrder();
    createTestItem($order, [
        'leased_by_agent_id' => 'agent-123',
        'lease_expires_at' => now()->addMinutes(5),
    ]);
    createTestItem($order, [
        'leased_by_agent_id' => 'agent-456',
        'lease_expires_at' => now()->addMinutes(5),
    ]);
    createTestItem($order, [
        'leased_by_agent_id' => 'agent-123',
        'lease_expires_at' => now()->subMinutes(5),
    ]);

    $items = WorkItem::query()->leasedBy('agent-123')->get();
    expect($items)->toHaveCount(1);
});

test('WorkItem::supportsPartialSubmissions returns true when parts_required is set', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['parts_required' => ['research', 'analysis']]);

    expect($item->supportsPartialSubmissions())->toBeTrue();
});

test('WorkItem::supportsPartialSubmissions returns false when parts_required is empty', function () {
    $order = createTestOrder();
    $item1 = createTestItem($order, ['parts_required' => []]);
    expect($item1->supportsPartialSubmissions())->toBeFalse();

    $item2 = createTestItem($order, ['parts_required' => null]);
    expect($item2->supportsPartialSubmissions())->toBeFalse();
});

test('WorkItem::getLatestPart returns most recent part for a key', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    createTestPart($item, [
        'part_key' => 'research',
        'seq' => 1,
        'created_at' => now()->subHours(2),
    ]);
    $latest = createTestPart($item, [
        'part_key' => 'research',
        'seq' => 2,
        'created_at' => now()->subHour(),
    ]);

    $result = $item->getLatestPart('research');

    expect($result->id)->toBe($latest->id);
});

test('WorkItem::getLatestPart returns null when key not found', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    $result = $item->getLatestPart('nonexistent');

    expect($result)->toBeNull();
});

test('WorkItem::getLatestParts returns one part per unique key', function () {
    $order = createTestOrder();
    $item = createTestItem($order);

    createTestPart($item, ['part_key' => 'research', 'seq' => 1]);
    createTestPart($item, ['part_key' => 'research', 'seq' => 2]);
    createTestPart($item, ['part_key' => 'analysis', 'seq' => 1]);

    $latest = $item->getLatestParts();

    expect($latest)->toHaveCount(2);
    expect($latest->pluck('part_key')->unique()->count())->toBe(2);
});

test('WorkItem::hasAllRequiredParts returns true when all parts validated', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['parts_required' => ['research', 'analysis']]);

    createTestPart($item, [
        'part_key' => 'research',
        'status' => PartStatus::VALIDATED,
    ]);
    createTestPart($item, [
        'part_key' => 'analysis',
        'status' => PartStatus::VALIDATED,
    ]);

    expect($item->hasAllRequiredParts())->toBeTrue();
});

test('WorkItem::hasAllRequiredParts returns false when parts missing', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['parts_required' => ['research', 'analysis', 'conclusion']]);

    createTestPart($item, [
        'part_key' => 'research',
        'status' => PartStatus::VALIDATED,
    ]);

    expect($item->hasAllRequiredParts())->toBeFalse();
});

test('WorkItem::hasAllRequiredParts returns true when no parts required', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['parts_required' => []]);

    expect($item->hasAllRequiredParts())->toBeTrue();
});

test('WorkItem::hasAllRequiredParts ignores non-validated parts', function () {
    $order = createTestOrder();
    $item = createTestItem($order, ['parts_required' => ['research']]);

    createTestPart($item, [
        'part_key' => 'research',
        'status' => PartStatus::DRAFT,
    ]);

    expect($item->hasAllRequiredParts())->toBeFalse();
});

// WorkOrder helper methods
test('WorkOrder::isTerminal returns true when state is terminal', function () {
    $order1 = createTestOrder(['state' => OrderState::COMPLETED]);
    expect($order1->isTerminal())->toBeTrue();

    $order2 = createTestOrder(['state' => OrderState::DEAD_LETTERED]);
    expect($order2->isTerminal())->toBeTrue();
});

test('WorkOrder::isTerminal returns false when state is not terminal', function () {
    $order1 = createTestOrder(['state' => OrderState::QUEUED]);
    expect($order1->isTerminal())->toBeFalse();

    $order2 = createTestOrder(['state' => OrderState::IN_PROGRESS]);
    expect($order2->isTerminal())->toBeFalse();

    $order3 = createTestOrder(['state' => OrderState::REJECTED]);
    expect($order3->isTerminal())->toBeFalse();
});

test('WorkOrder::allItemsComplete returns true when all items are complete', function () {
    $order = createTestOrder();

    createTestItem($order, ['state' => ItemState::COMPLETED]);
    createTestItem($order, ['state' => ItemState::COMPLETED]);

    expect($order->allItemsComplete())->toBeTrue();
});

test('WorkOrder::allItemsComplete returns true when items are completed, rejected, or dead_lettered', function () {
    $order = createTestOrder();

    createTestItem($order, ['state' => ItemState::COMPLETED]);
    createTestItem($order, ['state' => ItemState::REJECTED]);
    createTestItem($order, ['state' => ItemState::DEAD_LETTERED]);

    expect($order->allItemsComplete())->toBeTrue();
});

test('WorkOrder::allItemsComplete returns false when items are in progress', function () {
    $order = createTestOrder();

    createTestItem($order, ['state' => ItemState::COMPLETED]);
    createTestItem($order, ['state' => ItemState::IN_PROGRESS]);

    expect($order->allItemsComplete())->toBeFalse();
});

test('WorkOrder::scopeInState filters by OrderState enum', function () {
    createTestOrder(['state' => OrderState::QUEUED]);
    createTestOrder(['state' => OrderState::IN_PROGRESS]);
    createTestOrder(['state' => OrderState::COMPLETED]);

    $queued = WorkOrder::query()->inState(OrderState::QUEUED)->get();
    expect($queued)->toHaveCount(1);
    expect($queued->first()->state)->toBe(OrderState::QUEUED);
});

test('WorkOrder::scopeInState filters by state string', function () {
    createTestOrder(['state' => OrderState::QUEUED]);
    createTestOrder(['state' => OrderState::IN_PROGRESS]);

    $queued = WorkOrder::query()->inState('queued')->get();
    expect($queued)->toHaveCount(1);
});

test('WorkOrder::scopeOfType filters by type', function () {
    createTestOrder(['type' => 'sync.users']);
    createTestOrder(['type' => 'sync.posts']);
    createTestOrder(['type' => 'sync.users']);

    $userSyncs = WorkOrder::query()->ofType('sync.users')->get();
    expect($userSyncs)->toHaveCount(2);
});

test('WorkOrder::scopeRequestedBy filters by ActorType enum', function () {
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-123',
    ]);
    createTestOrder([
        'requested_by_type' => ActorType::USER,
        'requested_by_id' => 'user-456',
    ]);

    $agentOrders = WorkOrder::query()->requestedBy(ActorType::AGENT)->get();
    expect($agentOrders)->toHaveCount(1);
    expect($agentOrders->first()->requested_by_type)->toBe(ActorType::AGENT);
});

test('WorkOrder::scopeRequestedBy filters by actor type string', function () {
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-123',
    ]);
    createTestOrder([
        'requested_by_type' => ActorType::USER,
        'requested_by_id' => 'user-456',
    ]);

    $agentOrders = WorkOrder::query()->requestedBy('agent')->get();
    expect($agentOrders)->toHaveCount(1);
});

test('WorkOrder::scopeRequestedBy filters by actor type and ID', function () {
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-123',
    ]);
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-456',
    ]);

    $orders = WorkOrder::query()->requestedBy(ActorType::AGENT, 'agent-123')->get();
    expect($orders)->toHaveCount(1);
    expect($orders->first()->requested_by_id)->toBe('agent-123');
});

test('WorkOrder::scopeRequestedBy allows null ID', function () {
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-123',
    ]);
    createTestOrder([
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-456',
    ]);

    $orders = WorkOrder::query()->requestedBy(ActorType::AGENT, null)->get();
    expect($orders)->toHaveCount(2);
});
