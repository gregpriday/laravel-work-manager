<?php

use GregPriday\WorkManager\Exceptions\IdempotencyConflictException;
use GregPriday\WorkManager\Models\WorkIdempotencyKey;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\IdempotencyService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Validation\ValidationException;

// Idempotency Service Tests
it('stores idempotency key with response', function () {
    $service = app(IdempotencyService::class);

    $service->store('test-scope', 'key-1', ['result' => 'success']);

    $record = WorkIdempotencyKey::where('scope', 'test-scope')->first();
    expect($record)->not->toBeNull();
    expect($record->response_snapshot)->toBe(['result' => 'success']);
});

it('checks stored idempotency key', function () {
    $service = app(IdempotencyService::class);

    $service->store('test-scope', 'key-1', ['result' => 'success']);
    $stored = $service->check('test-scope', 'key-1');

    expect($stored)->toBe(['result' => 'success']);
});

it('throws conflict on duplicate idempotency key', function () {
    $service = app(IdempotencyService::class);

    $service->store('test-scope', 'key-1', ['result' => 'first']);

    $service->store('test-scope', 'key-1', ['result' => 'second']);
})->throws(IdempotencyConflictException::class);

it('guards callback execution with idempotency', function () {
    $service = app(IdempotencyService::class);

    $counter = 0;
    $callback = function () use (&$counter) {
        $counter++;
        return ['count' => $counter];
    };

    $result1 = $service->guard('test-scope', 'key-1', $callback);
    $result2 = $service->guard('test-scope', 'key-1', $callback);

    expect($result1)->toBe(['count' => 1]);
    expect($result2)->toBe(['count' => 1]); // Same result, callback only ran once
    expect($counter)->toBe(1);
});

// WorkAllocator Tests
it('proposes and plans work order', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose(
        'test.echo',
        ['message' => 'test']
    );

    expect($order->state)->toBe(OrderState::QUEUED);
    expect($order->type)->toBe('test.echo');
    expect($order->payload)->toBe(['message' => 'test']);

    // Plan should create items
    $allocator->plan($order);

    expect($order->items()->count())->toBeGreaterThan(0);
});

it('validates payload against schema on propose', function () {
    $allocator = app(WorkAllocator::class);

    // Invalid payload - missing required field
    $allocator->propose('test.echo', []);
})->throws(\Exception::class);

// WorkExecutor Tests
it('submits work item with valid result', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items->first();
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    $result = ['ok' => true, 'verified' => true, 'echoed_message' => 'test'];

    $submitted = $executor->submit($item, $result, 'agent-1');

    expect($submitted->state)->toBe(ItemState::SUBMITTED);
    expect($submitted->result)->toBe($result);
});

it('validates submission against rules', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items->first();
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    // Invalid result - missing required 'verified' field
    $result = ['ok' => true];

    $executor->submit($item, $result, 'agent-1');
})->throws(ValidationException::class);

it('approves and applies work order', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Submit all items
    foreach ($order->items as $item) {
        $item->update([
            'state' => ItemState::IN_PROGRESS,
            'leased_by_agent_id' => 'agent-1',
            'lease_expires_at' => now()->addMinutes(10),
        ]);
        $result = ['ok' => true, 'verified' => true, 'echoed_message' => 'test'];
        $executor->submit($item, $result, 'agent-1');
    }

    // Approve and apply
    $result = $executor->approve($order->fresh());

    expect($result)->toHaveKeys(['order', 'diff']);
    expect($result['order']->state)->toBeIn([OrderState::APPLIED, OrderState::COMPLETED]);
    expect($result['order']->applied_at)->not->toBeNull();
    expect($result['diff'])->not->toBeEmpty();
});

it('rejects work order', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);

    $rejected = $executor->reject($order, ['reason' => 'Not acceptable']);

    expect($rejected->state)->toBe(OrderState::REJECTED);
});
