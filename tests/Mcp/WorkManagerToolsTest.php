<?php

use GregPriday\WorkManager\Mcp\WorkManagerTools;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

it('propose returns success structure with order data', function () {
    $tools = app(WorkManagerTools::class);

    $result = $tools->propose(
        type: 'test.echo',
        payload: ['message' => 'test'],
        priority: 5
    );

    expect($result)->toHaveKeys(['success', 'order', 'items_count']);
    expect($result['success'])->toBeTrue();
    expect($result['order'])->toHaveKeys(['id', 'type', 'state', 'priority', 'payload', 'created_at']);
    expect($result['order']['type'])->toBe('test.echo');
    expect($result['order']['state'])->toBe('queued');
    expect($result['items_count'])->toBeGreaterThan(0);
});

it('propose uses idempotency caching', function () {
    $tools = app(WorkManagerTools::class);
    $key = 'test-key-' . uniqid();

    // First call
    $result1 = $tools->propose(
        type: 'test.echo',
        payload: ['message' => 'first'],
        idempotencyKey: $key
    );

    $orderId1 = $result1['order']['id'];

    // Second call with same key should return cached response
    $result2 = $tools->propose(
        type: 'test.echo',
        payload: ['message' => 'different'], // Different payload
        idempotencyKey: $key
    );

    expect($result2['order']['id'])->toBe($orderId1);
    expect($result2['order']['payload']['message'])->toBe('first'); // Original payload
});

it('list returns success structure with orders array', function () {
    $allocator = app(WorkAllocator::class);

    // Create some test orders
    $allocator->propose('test.echo', ['message' => 'test1']);
    $allocator->propose('test.echo', ['message' => 'test2']);

    $tools = app(WorkManagerTools::class);
    $result = $tools->list();

    expect($result)->toHaveKeys(['success', 'count', 'orders']);
    expect($result['success'])->toBeTrue();
    expect($result['count'])->toBeGreaterThanOrEqual(2);
    expect($result['orders'])->toBeArray();

    $firstOrder = $result['orders'][0];
    expect($firstOrder)->toHaveKeys(['id', 'type', 'state', 'priority', 'items_count', 'created_at']);
});

it('list filters by state', function () {
    $allocator = app(WorkAllocator::class);

    $allocator->propose('test.echo', ['message' => 'test1']);

    $order2 = $allocator->propose('test.echo', ['message' => 'test2']);
    $order2->update(['state' => OrderState::COMPLETED]);

    $tools = app(WorkManagerTools::class);
    $result = $tools->list(state: 'queued');

    expect($result['success'])->toBeTrue();

    foreach ($result['orders'] as $order) {
        expect($order['state'])->toBe('queued');
    }
});

it('list filters by type', function () {
    $allocator = app(WorkAllocator::class);

    $allocator->propose('test.echo', ['message' => 'test1']);
    $allocator->propose('test.batch', ['batches' => [['id' => 'a', 'data' => []]]]);

    $tools = app(WorkManagerTools::class);
    $result = $tools->list(type: 'test.echo');

    expect($result['success'])->toBeTrue();

    foreach ($result['orders'] as $order) {
        expect($order['type'])->toBe('test.echo');
    }
});

it('get returns detailed order information', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);
    $result = $tools->get($order->id);

    expect($result)->toHaveKeys(['success', 'order', 'items', 'recent_events']);
    expect($result['success'])->toBeTrue();
    expect($result['order']['id'])->toBe($order->id);
    expect($result['items'])->toBeArray();
    expect($result['items'])->not->toBeEmpty();
    expect($result['recent_events'])->toBeArray();
});

it('checkout returns success with item details', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);
    $result = $tools->checkout(orderId: $order->id, agentId: 'agent-1');

    expect($result)->toHaveKeys(['success', 'item']);
    expect($result['success'])->toBeTrue();
    expect($result['item'])->toHaveKeys([
        'id',
        'order_id',
        'type',
        'input',
        'lease_expires_at',
        'heartbeat_every_seconds',
        'max_attempts',
        'current_attempt',
    ]);
    expect($result['item']['order_id'])->toBe($order->id);
});

it('checkout returns error when no items available', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    // Don't plan the order - no items available

    $tools = app(WorkManagerTools::class);
    $result = $tools->checkout(orderId: $order->id, agentId: 'agent-1');

    expect($result)->toHaveKeys(['success', 'error', 'code']);
    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('no_items_available');
});

it('checkout returns no_items_available when all items completed', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);

    // Mark all items as completed
    foreach ($order->items as $item) {
        $item->update(['state' => ItemState::COMPLETED]);
    }

    $tools = app(WorkManagerTools::class);

    // Try to checkout - should fail
    $result = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('no_items_available');
});

it('heartbeat returns success with updated lease time', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    // Checkout first
    $checkoutResult = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    $itemId = $checkoutResult['item']['id'];

    // Send heartbeat
    $result = $tools->heartbeat($itemId, 'agent-1');

    expect($result)->toHaveKeys(['success', 'lease_expires_at', 'heartbeat_every_seconds']);
    expect($result['success'])->toBeTrue();
});

it('heartbeat returns error when wrong agent', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    // Agent 1 checks out
    $checkoutResult = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    $itemId = $checkoutResult['item']['id'];

    // Agent 2 tries to heartbeat
    $result = $tools->heartbeat($itemId, 'agent-2');

    expect($result)->toHaveKeys(['success', 'error', 'code']);
    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('lease_error');
});

it('submit returns success with item details', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    // Checkout first
    $checkoutResult = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    $itemId = $checkoutResult['item']['id'];

    // Submit results
    $result = $tools->submit(
        itemId: $itemId,
        result: ['ok' => true, 'verified' => true, 'echoed_message' => 'test'],
        agentId: 'agent-1'
    );

    expect($result)->toHaveKeys(['success', 'item']);
    expect($result['success'])->toBeTrue();
    expect($result['item']['state'])->toBe('submitted');
});

it('submit uses idempotency for duplicate submissions', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    // Checkout first
    $checkoutResult = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    $itemId = $checkoutResult['item']['id'];

    $key = 'submit-key-' . uniqid();

    // First submit
    $result1 = $tools->submit(
        itemId: $itemId,
        result: ['ok' => true, 'verified' => true, 'echoed_message' => 'first'],
        agentId: 'agent-1',
        idempotencyKey: $key
    );

    expect($result1['success'])->toBeTrue();

    // Second submit with same key should return cached response
    $result2 = $tools->submit(
        itemId: $itemId,
        result: ['ok' => false, 'verified' => false, 'echoed_message' => 'different'],
        agentId: 'agent-1',
        idempotencyKey: $key
    );

    expect($result2['success'])->toBeTrue();
    expect($result2['item']['result']['echoed_message'])->toBe('first'); // Original result
});

it('logs returns events for work item', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    $tools = app(WorkManagerTools::class);
    $result = $tools->logs($item->id);

    expect($result)->toHaveKeys(['success', 'events']);
    expect($result['success'])->toBeTrue();
    expect($result['events'])->toBeArray();
    expect($result['events'])->not->toBeEmpty();
});

it('release returns success when releasing lease', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $tools = app(WorkManagerTools::class);

    // Checkout first
    $checkoutResult = $tools->checkout(orderId: $order->id, agentId: 'agent-1');
    $itemId = $checkoutResult['item']['id'];

    // Release
    $result = $tools->release($itemId, 'agent-1');

    expect($result)->toHaveKeys(['success', 'item']);
    expect($result['success'])->toBeTrue();
});
