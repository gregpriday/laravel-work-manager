<?php

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

beforeEach(function () {
    $allocator = app(WorkAllocator::class);
    $this->order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($this->order);
});

test('orders:requeue can move orders back to queued', function () {
    // Move order to in_progress
    $this->order->state = OrderState::IN_PROGRESS;
    $this->order->save();

    $this->artisan('work-manager:orders:requeue', ['--order' => $this->order->id])
        ->assertExitCode(0);

    expect($this->order->fresh()->state)->toBe(OrderState::QUEUED);
});

test('orders:requeue with submitted orders requires flag', function () {
    // Move order to submitted state
    $this->order->state = OrderState::SUBMITTED;
    $this->order->save();

    // Should skip without flag
    $this->artisan('work-manager:orders:requeue', ['--order' => $this->order->id])
        ->assertExitCode(0);

    expect($this->order->fresh()->state)->toBe(OrderState::SUBMITTED);

    // Should work with flag
    $this->artisan('work-manager:orders:requeue', [
        '--order' => $this->order->id,
        '--requeue-submitted' => true,
    ])->assertExitCode(0);

    expect($this->order->fresh()->state)->toBe(OrderState::QUEUED);
});

test('items:retry resets failed items', function () {
    $item = $this->order->items->first();

    // Mark as failed
    $item->state = ItemState::FAILED;
    $item->error = ['code' => 'test_error', 'message' => 'Test error'];
    $item->save();

    $this->artisan('work-manager:items:retry', ['--order' => $this->order->id])
        ->assertExitCode(0);

    expect($item->fresh()->state)->toBe(ItemState::QUEUED);
    expect($item->fresh()->error)->toBeNull();
});

test('orders:review can approve submitted orders', function () {
    // Submit all items (required for approval readiness)
    $leases = app(LeaseService::class);
    $executor = app(WorkExecutor::class);

    foreach ($this->order->items as $item) {
        $item = $leases->acquire($item->id, 'test-agent');
        $executor->submit($item, ['ok' => true, 'verified' => true, 'echoed_message' => 'test'], 'test-agent');
    }

    // Order should now be ready for approval
    $this->artisan('work-manager:orders:review', [
        'order' => $this->order->id,
        '--approve' => true,
    ])->assertExitCode(0);

    // After approval & apply, order should be applied or completed
    $finalState = $this->order->fresh()->state;
    expect(in_array($finalState, [OrderState::APPLIED, OrderState::COMPLETED], true))->toBeTrue();
});

test('orders:review can reject orders', function () {
    $this->order->state = OrderState::SUBMITTED;
    $this->order->save();

    $this->artisan('work-manager:orders:review', [
        'order' => $this->order->id,
        '--reject' => true,
        '--reason' => 'Test rejection',
    ])->assertExitCode(0);

    expect($this->order->fresh()->state)->toBe(OrderState::REJECTED);
});

test('orders:review requires approve or reject flag', function () {
    // Test that command requires exactly one of --approve or --reject
    $this->artisan('work-manager:orders:review', [
        'order' => $this->order->id,
    ])->assertExitCode(1);

    // Test both flags fail
    $this->artisan('work-manager:orders:review', [
        'order' => $this->order->id,
        '--approve' => true,
        '--reject' => true,
        '--reason' => 'test',
    ])->assertExitCode(1);
});

test('dead-letter:clone creates new order from dead-lettered', function () {
    // Dead letter the order
    $this->order->state = OrderState::DEAD_LETTERED;
    $this->order->save();

    $this->artisan('work-manager:dead-letter:clone', [
        'order' => $this->order->id,
    ])->assertExitCode(0);

    // Should have a new order
    $newOrder = WorkOrder::where('id', '!=', $this->order->id)
        ->where('type', $this->order->type)
        ->first();

    expect($newOrder)->not->toBeNull();
    expect($newOrder->state)->toBe(OrderState::QUEUED);
    expect($newOrder->meta['cloned_from'])->toBe($this->order->id);
});
