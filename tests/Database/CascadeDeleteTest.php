<?php

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkProvenance;

it('cascades delete from work_order to work_items', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => 'queued',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'input' => ['message' => 'test'],
    ]);

    expect(WorkItem::find($item->id))->not->toBeNull();

    $order->delete();

    expect(WorkItem::find($item->id))->toBeNull();
});

it('cascades delete from work_order to work_events', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => 'queued',
        'payload' => ['message' => 'test'],
    ]);

    $event = WorkEvent::create([
        'order_id' => $order->id,
        'event' => 'proposed',
        'created_at' => now(),
    ]);

    expect(WorkEvent::find($event->id))->not->toBeNull();

    $order->delete();

    expect(WorkEvent::find($event->id))->toBeNull();
});

it('cascades delete from work_order to work_provenances', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => 'queued',
        'payload' => ['message' => 'test'],
    ]);

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'test-agent',
        'created_at' => now(),
    ]);

    expect(WorkProvenance::find($provenance->id))->not->toBeNull();

    $order->delete();

    expect(WorkProvenance::find($provenance->id))->toBeNull();
});

it('cascades delete all related records when order is deleted', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => 'queued',
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'input' => ['message' => 'test'],
    ]);

    $orderEvent = WorkEvent::create([
        'order_id' => $order->id,
        'event' => 'proposed',
        'created_at' => now(),
    ]);

    $itemEvent = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => 'leased',
        'created_at' => now(),
    ]);

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'test-agent',
        'created_at' => now(),
    ]);

    // Verify all exist
    expect(WorkItem::count())->toBe(1);
    expect(WorkEvent::count())->toBe(2);
    expect(WorkProvenance::count())->toBe(1);

    $order->delete();

    // Verify all deleted
    expect(WorkItem::count())->toBe(0);
    expect(WorkEvent::count())->toBe(0);
    expect(WorkProvenance::count())->toBe(0);
});
