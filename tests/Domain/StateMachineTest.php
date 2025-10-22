<?php

use GregPriday\WorkManager\Exceptions\IllegalStateTransitionException;
use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\StateMachine;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

beforeEach(function () {
    $this->stateMachine = app(StateMachine::class);
});

it('transitions order to new state', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionOrder(
        $order,
        OrderState::CHECKED_OUT,
        ActorType::AGENT,
        'agent-1'
    );

    expect($order->fresh()->state)->toBe(OrderState::CHECKED_OUT);
    expect($order->fresh()->last_transitioned_at)->not->toBeNull();
});

it('records event when transitioning order', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionOrder(
        $order,
        OrderState::CHECKED_OUT,
        ActorType::AGENT,
        'agent-1',
        ['key' => 'value'],
        'Test message'
    );

    $events = WorkEvent::where('order_id', $order->id)->get();

    expect($events)->toHaveCount(1);
    expect($events->first()->event)->toBe(EventType::CHECKED_OUT);
    expect($events->first()->actor_type)->toBe(ActorType::AGENT);
    expect($events->first()->actor_id)->toBe('agent-1');
    expect($events->first()->payload)->toBe(['key' => 'value']);
    expect($events->first()->message)->toBe('Test message');
});

it('sets applied_at timestamp when transitioning to APPLIED', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPROVED,
        'payload' => ['message' => 'test'],
    ]);

    expect($order->applied_at)->toBeNull();

    $this->stateMachine->transitionOrder($order, OrderState::APPLIED);

    expect($order->fresh()->applied_at)->not->toBeNull();
});

it('sets completed_at timestamp when transitioning to COMPLETED', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPLIED,
        'payload' => ['message' => 'test'],
    ]);

    expect($order->completed_at)->toBeNull();

    $this->stateMachine->transitionOrder($order, OrderState::COMPLETED);

    expect($order->fresh()->completed_at)->not->toBeNull();
});

it('throws exception for illegal order transitions', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionOrder($order, OrderState::COMPLETED);
})->throws(IllegalStateTransitionException::class);

it('transitions item to new state', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionItem(
        $item,
        ItemState::LEASED,
        ActorType::AGENT,
        'agent-1'
    );

    expect($item->fresh()->state)->toBe(ItemState::LEASED);
});

it('records event when transitioning item', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionItem(
        $item,
        ItemState::LEASED,
        ActorType::AGENT,
        'agent-1',
        ['key' => 'value'],
        'Leased by agent'
    );

    $events = WorkEvent::where('item_id', $item->id)->get();

    expect($events)->toHaveCount(1);
    expect($events->first()->event)->toBe(EventType::LEASED);
    expect($events->first()->actor_type)->toBe(ActorType::AGENT);
    expect($events->first()->actor_id)->toBe('agent-1');
    expect($events->first()->payload)->toBe(['key' => 'value']);
    expect($events->first()->message)->toBe('Leased by agent');
});

it('sets accepted_at timestamp when transitioning to ACCEPTED', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::SUBMITTED,
        'input' => ['message' => 'test'],
    ]);

    expect($item->accepted_at)->toBeNull();

    $this->stateMachine->transitionItem($item, ItemState::ACCEPTED);

    expect($item->fresh()->accepted_at)->not->toBeNull();
});

it('throws exception for illegal item transitions', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $this->stateMachine->transitionItem($item, ItemState::COMPLETED);
})->throws(IllegalStateTransitionException::class);

it('records order event without state transition', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $event = $this->stateMachine->recordOrderEvent(
        $order,
        EventType::PROPOSED,
        ActorType::USER,
        'user-1',
        ['info' => 'data'],
        'Work order proposed'
    );

    expect($event)->toBeInstanceOf(WorkEvent::class);
    expect($event->order_id)->toBe($order->id);
    expect($event->event)->toBe(EventType::PROPOSED);
    expect($event->actor_type)->toBe(ActorType::USER);
    expect($event->actor_id)->toBe('user-1');
});

it('records item event without state transition', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    $event = $this->stateMachine->recordItemEvent(
        $item,
        EventType::HEARTBEAT,
        ActorType::AGENT,
        'agent-1',
        ['timestamp' => '2025-01-01'],
        'Heartbeat received'
    );

    expect($event)->toBeInstanceOf(WorkEvent::class);
    expect($event->order_id)->toBe($order->id);
    expect($event->item_id)->toBe($item->id);
    expect($event->event)->toBe(EventType::HEARTBEAT);
});

it('includes diff in order event when provided', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPROVED,
        'payload' => ['message' => 'test'],
    ]);

    $diff = [
        'before' => ['count' => 0],
        'after' => ['count' => 5],
        'changes' => ['count' => ['type' => 'modified', 'from' => 0, 'to' => 5]],
    ];

    $this->stateMachine->transitionOrder(
        $order,
        OrderState::APPLIED,
        diff: $diff
    );

    $event = WorkEvent::where('order_id', $order->id)->first();

    expect($event->diff)->toBe($diff);
});

it('uses database transaction for order transitions', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    // This will use a transaction internally
    $this->stateMachine->transitionOrder($order, OrderState::CHECKED_OUT);

    // Verify both state and event were saved
    expect($order->fresh()->state)->toBe(OrderState::CHECKED_OUT);
    expect(WorkEvent::where('order_id', $order->id)->count())->toBe(1);
});

it('uses database transaction for item transitions', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => ['message' => 'test'],
    ]);

    // This will use a transaction internally
    $this->stateMachine->transitionItem($item, ItemState::LEASED);

    // Verify both state and event were saved
    expect($item->fresh()->state)->toBe(ItemState::LEASED);
    expect(WorkEvent::where('item_id', $item->id)->count())->toBe(1);
});
