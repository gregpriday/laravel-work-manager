<?php

use GregPriday\WorkManager\Mcp\WorkManagerTools;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters with state and type using filter parameter', function () {
    $allocator = app(WorkAllocator::class);

    $allocator->propose('test.echo', ['message' => 'queued-echo']);

    $order2 = $allocator->propose('test.batch', ['batches' => [['id' => 'a', 'data' => []]]]);
    $order2->update(['state' => OrderState::COMPLETED]);

    $tools = app(WorkManagerTools::class);

    // Test state filter
    $result = $tools->list(filter: ['state' => 'queued']);

    expect($result['success'])->toBeTrue();
    foreach ($result['orders'] as $order) {
        expect($order['state'])->toBe('queued');
    }

    // Test type filter
    $result2 = $tools->list(filter: ['type' => 'test.echo']);

    expect($result2['success'])->toBeTrue();
    foreach ($result2['orders'] as $order) {
        expect($order['type'])->toBe('test.echo');
    }
});

it('supports filter parameter with exact filters', function () {
    $allocator = app(WorkAllocator::class);

    $allocator->propose('test.echo', ['message' => 'test']);

    $order2 = $allocator->propose('test.batch', ['batches' => [['id' => 'a', 'data' => []]]]);
    $order2->update(['state' => OrderState::COMPLETED]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'state' => 'queued',
        'type' => 'test.echo',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['type'])->toBe('test.echo');
    expect($result['orders'][0]['state'])->toBe('queued');
});

it('supports filter with requested_by_type', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'agent'],
        'priority' => 1,
        'requested_by_type' => ActorType::AGENT,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'user'],
        'priority' => 1,
        'requested_by_type' => ActorType::USER,
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'requested_by_type' => 'agent',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['payload']['message'])->toBe('agent');
});

it('supports filter with relation filter items.state', function () {
    $allocator = app(WorkAllocator::class);

    // Order with queued items
    $order1 = $allocator->propose('test.echo', ['message' => 'test1']);
    $allocator->plan($order1);

    // Order with in-progress items
    $order2 = $allocator->propose('test.echo', ['message' => 'test2']);
    $allocator->plan($order2);
    $order2->items()->update(['state' => ItemState::IN_PROGRESS]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'items.state' => 'queued',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['id'])->toBe($order1->id);
});

it('supports operator filter with priority greater than', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low'],
        'priority' => 30,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 70,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'higher'],
        'priority' => 90,
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'priority' => '>50',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(2);
    foreach ($result['orders'] as $order) {
        expect($order['priority'])->toBeGreaterThan(50);
    }
});

it('supports operator filter with created_at', function () {
    $cutoffDate = now()->subDays(5);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'old'],
        'priority' => 1,
        'created_at' => now()->subDays(10),
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'recent'],
        'priority' => 1,
        'created_at' => now()->subDays(2),
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'created_at' => '>=' . $cutoffDate->toIso8601String(),
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['payload']['message'])->toBe('recent');
});

it('supports JSON meta filter', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 1,
        'meta' => ['batch_id' => 42],
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'no-match'],
        'priority' => 1,
        'meta' => ['batch_id' => 99],
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'meta' => 'batch_id:42',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
});

it('supports has_available_items filter', function () {
    $allocator = app(WorkAllocator::class);
    $leaseService = app(LeaseService::class);

    // Order with available items
    $order1 = $allocator->propose('test.echo', ['message' => 'available']);
    $allocator->plan($order1);

    // Order with leased items
    $order2 = $allocator->propose('test.echo', ['message' => 'leased']);
    $allocator->plan($order2);
    $item = $order2->items()->first();
    $leaseService->acquire($item->id, 'agent-123');

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(filter: [
        'has_available_items' => true,
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['id'])->toBe($order1->id);
});

it('supports sort parameter', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low'],
        'priority' => 10,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 50,
    ]);

    $tools = app(WorkManagerTools::class);

    // Sort ascending by priority
    $result = $tools->list(sort: 'priority');

    expect($result['success'])->toBeTrue();
    expect($result['orders'][0]['priority'])->toBe(10);
    expect($result['orders'][1]['priority'])->toBe(50);
});

it('supports sort by created_at descending', function () {
    $old = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'old'],
        'priority' => 1,
        'created_at' => now()->subDays(5),
    ]);

    $new = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'new'],
        'priority' => 1,
        'created_at' => now(),
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(sort: '-created_at');

    expect($result['success'])->toBeTrue();
    expect($result['orders'][0]['id'])->toBe($new->id);
    expect($result['orders'][1]['id'])->toBe($old->id);
});

it('supports include parameter with itemsCount', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.batch', ['batches' => [
        ['id' => 'a', 'data' => []],
        ['id' => 'b', 'data' => []],
        ['id' => 'c', 'data' => []],
    ]]);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(include: 'itemsCount');

    expect($result['success'])->toBeTrue();
    expect($result['orders'][0]['items_count'])->toBe(3);
});

it('supports pagination with page parameter', function () {
    for ($i = 0; $i < 25; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(page: ['size' => 10, 'number' => 2]);

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(10);
    expect($result['meta']['current_page'])->toBe(2);
    expect($result['meta']['per_page'])->toBe(10);
});

it('uses default page size when no pagination specified', function () {
    for ($i = 0; $i < 25; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $tools = app(WorkManagerTools::class);

    $result = $tools->list();

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(20); // default page size
    expect($result['meta']['per_page'])->toBe(20);
});

it('enforces maximum limit of 100', function () {
    for ($i = 0; $i < 150; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(page: ['size' => 200]);

    expect($result['success'])->toBeTrue();
    expect($result['meta']['per_page'])->toBe(100);
});

it('preserves default sort order priority desc then created_at asc', function () {
    // Lower priority, older
    $order1 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-old'],
        'priority' => 1,
        'created_at' => now()->subMinutes(10),
    ]);

    // Lower priority, newer
    $order2 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-new'],
        'priority' => 1,
        'created_at' => now(),
    ]);

    // Highest priority
    $order3 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 10,
        'created_at' => now()->subMinutes(5),
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list();

    expect($result['success'])->toBeTrue();

    // Highest priority first
    expect($result['orders'][0]['id'])->toBe($order3->id);
    expect($result['orders'][0]['priority'])->toBe(10);

    // Among same priority, older comes first
    expect($result['orders'][1]['id'])->toBe($order1->id);
    expect($result['orders'][2]['id'])->toBe($order2->id);
});

it('combines multiple filters sorts and includes', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 60,
        'created_at' => now()->subDays(2),
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'no-match-state'],
        'priority' => 70,
        'created_at' => now()->subDays(1),
    ]);

    WorkOrder::create([
        'type' => 'test.batch',
        'state' => OrderState::QUEUED,
        'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
        'priority' => 80,
        'created_at' => now(),
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-priority'],
        'priority' => 40,
        'created_at' => now()->subDays(3),
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(
        filter: [
            'state' => 'queued',
            'type' => 'test.echo',
            'priority' => '>50',
        ],
        sort: '-created_at',
        include: 'itemsCount'
    );

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
    expect($result['orders'][0]['payload']['message'])->toBe('match');
    expect($result['orders'][0]['priority'])->toBe(60);
});

it('returns pagination metadata in response', function () {
    for ($i = 0; $i < 30; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(page: ['size' => 10, 'number' => 2]);

    expect($result['success'])->toBeTrue();
    expect($result['meta'])->toHaveKeys(['current_page', 'last_page', 'per_page', 'total']);
    expect($result['meta']['current_page'])->toBe(2);
    expect($result['meta']['per_page'])->toBe(10);
    expect($result['meta']['total'])->toBe(30);
    expect($result['meta']['last_page'])->toBe(3);
});

it('combines multiple filter criteria', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 60,
    ]);

    WorkOrder::create([
        'type' => 'test.batch',
        'state' => OrderState::QUEUED,
        'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
        'priority' => 40,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'no-match'],
        'priority' => 70,
    ]);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list(
        filter: [
            'state' => 'queued',
            'priority' => '>50',
        ]
    );

    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBe(1);
});

it('includes items by default', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    $result = $tools->list();

    expect($result['success'])->toBeTrue();
    expect($result['orders'][0]['items_count'])->toBeGreaterThan(0);
});
