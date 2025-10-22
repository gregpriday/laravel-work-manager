<?php

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('WorkEvent can be created with all attributes', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $event = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
        'payload' => ['key' => 'value'],
        'diff' => ['added' => ['field' => 'value']],
        'message' => 'Test event',
    ]);

    expect($event)->toBeInstanceOf(WorkEvent::class);
    expect($event->order_id)->toBe($order->id);
    expect($event->item_id)->toBe($item->id);
    expect($event->event)->toBe(EventType::PROPOSED);
    expect($event->actor_type)->toBe(ActorType::AGENT);
    expect($event->actor_id)->toBe('agent-1');
});

test('WorkEvent::scopeOfType filters by EventType enum', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);

    $proposed = WorkEvent::query()->ofType(EventType::PROPOSED)->get();
    expect($proposed)->toHaveCount(1);
    expect($proposed->first()->event)->toBe(EventType::PROPOSED);
});

test('WorkEvent::scopeOfType filters by event type string', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);

    $proposed = WorkEvent::query()->ofType('proposed')->get();
    expect($proposed)->toHaveCount(1);
});

test('WorkEvent::scopeByActor filters by ActorType enum', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::USER,
        'actor_id' => 'user-1',
    ]);

    $agentEvents = WorkEvent::query()->byActor(ActorType::AGENT)->get();
    expect($agentEvents)->toHaveCount(1);
    expect($agentEvents->first()->actor_type)->toBe(ActorType::AGENT);
});

test('WorkEvent::scopeByActor filters by actor type string', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::USER,
        'actor_id' => 'user-1',
    ]);

    $agentEvents = WorkEvent::query()->byActor('agent')->get();
    expect($agentEvents)->toHaveCount(1);
});

test('WorkEvent::scopeByActor filters by actor type and ID', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-2',
    ]);

    $agent1Events = WorkEvent::query()->byActor(ActorType::AGENT, 'agent-1')->get();
    expect($agent1Events)->toHaveCount(1);
    expect($agent1Events->first()->actor_id)->toBe('agent-1');
});

test('WorkEvent::scopeRecent filters events by hours', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    $recentEvent = WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    $recentEvent->created_at = now()->subHours(12);
    $recentEvent->save();

    $oldEvent = WorkEvent::create([
        'order_id' => $order->id,
        'event' => EventType::APPROVED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);
    $oldEvent->created_at = now()->subHours(48);
    $oldEvent->save();

    $recent = WorkEvent::query()->recent(24)->get();
    expect($recent)->toHaveCount(1);
    expect($recent->first()->id)->toBe($recentEvent->id);
});

test('WorkEvent relationships work correctly', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $event = WorkEvent::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'event' => EventType::PROPOSED,
        'actor_type' => ActorType::AGENT,
        'actor_id' => 'agent-1',
    ]);

    expect($event->order->id)->toBe($order->id);
    expect($event->item->id)->toBe($item->id);
});
