<?php

use GregPriday\WorkManager\Exceptions\IllegalStateTransitionException;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\StateMachine;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

it('throws on illegal order transition from completed to queued', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'test'],
    ]);

    expect(fn () => $sm->transitionOrder($order, OrderState::QUEUED))
        ->toThrow(IllegalStateTransitionException::class);
});

it('throws on illegal order transition from applied to queued', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPLIED,
        'payload' => ['message' => 'test'],
    ]);

    expect(fn () => $sm->transitionOrder($order, OrderState::QUEUED))
        ->toThrow(IllegalStateTransitionException::class);
});

it('throws on illegal item transition from completed to queued', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::COMPLETED,
        'input' => ['test' => 'data'],
        'max_attempts' => 3,
    ]);

    expect(fn () => $sm->transitionItem($item, ItemState::QUEUED))
        ->toThrow(IllegalStateTransitionException::class);
});

it('throws on illegal item transition from accepted to queued', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::ACCEPTED,
        'input' => ['test' => 'data'],
        'max_attempts' => 3,
    ]);

    expect(fn () => $sm->transitionItem($item, ItemState::QUEUED))
        ->toThrow(IllegalStateTransitionException::class);
});

it('allows legal order transition from queued to checked_out', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $result = $sm->transitionOrder($order, OrderState::CHECKED_OUT);

    expect($result->state)->toBe(OrderState::CHECKED_OUT);
    expect($result->last_transitioned_at)->not->toBeNull();
});

it('allows legal item transition from queued to leased', function () {
    $sm = app(StateMachine::class);

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

    $result = $sm->transitionItem($item, ItemState::LEASED);

    expect($result->state)->toBe(ItemState::LEASED);
});

it('throws when attempting to transition from terminal state', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'test'],
    ]);

    // Completed is terminal - should not allow any transition
    expect(fn () => $sm->transitionOrder($order, OrderState::APPLIED))
        ->toThrow(IllegalStateTransitionException::class);
});

it('includes helpful error message with transition details', function () {
    $sm = app(StateMachine::class);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'test'],
    ]);

    try {
        $sm->transitionOrder($order, OrderState::QUEUED);
        $this->fail('Should have thrown IllegalStateTransitionException');
    } catch (IllegalStateTransitionException $e) {
        expect($e->getMessage())->toContain('completed');
        expect($e->getMessage())->toContain('queued');
    }
});

it('validates order state transitions match config', function () {
    $allowedTransitions = config('work-manager.state_machine.order_transitions');

    $sm = app(StateMachine::class);

    // Test each disallowed transition throws
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    // Try transitioning to completed (not in allowed transitions from queued)
    $queuedAllowed = $allowedTransitions[OrderState::QUEUED->value] ?? [];

    if (! in_array(OrderState::COMPLETED->value, $queuedAllowed)) {
        expect(fn () => $sm->transitionOrder($order, OrderState::COMPLETED))
            ->toThrow(IllegalStateTransitionException::class);
    }
});

it('validates item state transitions match config', function () {
    $allowedTransitions = config('work-manager.state_machine.item_transitions');

    $sm = app(StateMachine::class);

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

    // Try transitioning to completed (not in allowed transitions from queued)
    $queuedAllowed = $allowedTransitions[ItemState::QUEUED->value] ?? [];

    if (! in_array(ItemState::COMPLETED->value, $queuedAllowed)) {
        expect(fn () => $sm->transitionItem($item, ItemState::COMPLETED))
            ->toThrow(IllegalStateTransitionException::class);
    }
});
