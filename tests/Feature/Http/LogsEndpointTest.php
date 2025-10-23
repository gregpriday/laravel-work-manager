<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('agent/work', ['api']);

    // Authenticate as test user for all tests
    $this->actingAs(new TestUser);
});

it('returns events for item and its order', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'log-test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Create some events through normal workflow
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    // Heartbeat creates an event
    app(\GregPriday\WorkManager\Services\LeaseService::class)->extend($item->id, 'agent-1');

    // Submit creates an event
    $executor->submit($item->fresh(), ['ok' => true, 'verified' => true, 'echoed_message' => 'log-test'], 'agent-1');

    $response = $this->getJson("/agent/work/items/{$item->id}/logs");

    $response->assertOk()
        ->assertJsonStructure(['events']);

    $events = $response->json('events');

    expect($events)->not->toBeEmpty();
    expect(count($events))->toBeGreaterThan(0);

    // Check we have a mix of item and order events
    $itemEvents = collect($events)->filter(fn ($e) => $e['item_id'] === $item->id);
    $orderEvents = collect($events)->filter(fn ($e) => $e['order_id'] === $order->id && $e['item_id'] === null);

    expect($itemEvents->count())->toBeGreaterThan(0);
    expect($orderEvents->count())->toBeGreaterThan(0);
});

it('orders events by created_at desc (newest first)', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Create multiple events with explicit timestamps to ensure ordering
    $time1 = now()->subMinutes(3);
    $time2 = now()->subMinutes(2);
    $time3 = now()->subMinute();

    $event1 = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => EventType::FAILED,
        'created_at' => $time1,
    ]);

    $event2 = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => EventType::RELEASED,
        'created_at' => $time2,
    ]);

    $event3 = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => EventType::COMPLETED,
        'created_at' => $time3,
    ]);

    $response = $this->getJson("/agent/work/items/{$item->id}/logs");

    $response->assertOk();
    $events = $response->json('events');

    // Should be ordered newest first
    $eventIds = collect($events)->pluck('id')->toArray();

    // Find the positions of our test events
    $pos1 = array_search($event1->id, $eventIds);
    $pos2 = array_search($event2->id, $eventIds);
    $pos3 = array_search($event3->id, $eventIds);

    // Verify they're all present
    expect($pos1)->not->toBeFalse();
    expect($pos2)->not->toBeFalse();
    expect($pos3)->not->toBeFalse();

    // Verify correct order (newest first means lower index)
    expect($pos3)->toBeLessThan($pos2); // completed (newest) before released
    expect($pos2)->toBeLessThan($pos1); // released before failed (oldest)
});

it('limits results to 100 events', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Create 150 events using valid EventType values (cycling through them)
    $eventTypes = [EventType::HEARTBEAT, EventType::IN_PROGRESS, EventType::FAILED, EventType::RELEASED];
    for ($i = 0; $i < 150; $i++) {
        WorkEvent::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'event' => $eventTypes[$i % count($eventTypes)],
        ]);
    }

    $response = $this->getJson("/agent/work/items/{$item->id}/logs");

    $response->assertOk();
    $events = $response->json('events');

    // Should be limited to 100
    expect(count($events))->toBeLessThanOrEqual(100);
});

it('includes order-level events with null item_id', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    // Create an order-level event (no item_id) using valid EventType
    WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => null,
        'event' => EventType::APPROVED,
    ]);

    $response = $this->getJson("/agent/work/items/{$item->id}/logs");

    $response->assertOk();
    $events = $response->json('events');

    // Filter for events with null item_id (order-level events)
    $orderLevelEvents = collect($events)->filter(fn ($e) => $e['item_id'] === null);

    expect($orderLevelEvents->count())->toBeGreaterThanOrEqual(1);
    expect($orderLevelEvents->first()['item_id'])->toBeNull();
});

it('returns events with full structure', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();

    $response = $this->getJson("/agent/work/items/{$item->id}/logs");

    $response->assertOk();
    $events = $response->json('events');

    expect($events)->not->toBeEmpty();

    // Check first event has expected structure
    $firstEvent = $events[0];
    expect($firstEvent)->toHaveKeys(['id', 'order_id', 'event', 'created_at']);
});
