<?php

use GregPriday\WorkManager\Exceptions\ForbiddenDirectMutationException;
use GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Register a test route with the middleware
    Route::post('/_test/protected', function () {
        return response()->json(['success' => true]);
    })->middleware(EnforceWorkOrderOnly::class);
});

it('rejects mutation without work order context', function () {
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/_test/protected'))
        ->toThrow(
            ForbiddenDirectMutationException::class,
            'This action requires a valid work order context'
        );
});

it('rejects mutation with non-existent work order id', function () {
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/_test/protected', [], [
        'X-Work-Order-ID' => 99999,
    ]))
        ->toThrow(
            ForbiddenDirectMutationException::class,
            'The specified work order does not exist'
        );
});

it('allows mutation with valid work order id in header', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson('/_test/protected', [], [
        'X-Work-Order-ID' => $order->id,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('allows mutation with valid work order id in body', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson('/_test/protected', [
        '_work_order_id' => $order->id,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('attaches work order to request', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    Route::post('/_test/check-attachment', function (\Illuminate\Http\Request $request) {
        $workOrder = $request->input('_work_order');
        return response()->json([
            'has_order' => $workOrder !== null,
            'order_id' => $workOrder?->id,
        ]);
    })->middleware(EnforceWorkOrderOnly::class);

    $response = $this->postJson('/_test/check-attachment', [], [
        'X-Work-Order-ID' => $order->id,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'has_order' => true,
            'order_id' => $order->id,
        ]);
});

it('rejects when order not in allowed states', function () {
    $this->withoutExceptionHandling();

    Route::post('/_test/state-protected', function () {
        return response()->json(['success' => true]);
    })->middleware(EnforceWorkOrderOnly::class . ':approved,applied');

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED, // Not in allowed states
        'payload' => ['message' => 'test'],
    ]);

    expect(fn () => $this->postJson('/_test/state-protected', [], [
        'X-Work-Order-ID' => $order->id,
    ]))
        ->toThrow(
            ForbiddenDirectMutationException::class,
            'Work order must be in one of these states: approved, applied'
        );
});

it('allows when order in allowed states', function () {
    Route::post('/_test/state-allowed', function () {
        return response()->json(['success' => true]);
    })->middleware(EnforceWorkOrderOnly::class . ':approved,applied');

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPROVED, // In allowed states
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson('/_test/state-allowed', [], [
        'X-Work-Order-ID' => $order->id,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('allows any state when no states specified', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::REJECTED, // Any state should work
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson('/_test/protected', [], [
        'X-Work-Order-ID' => $order->id,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('prefers header over body when both provided', function () {
    $order1 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'order1'],
    ]);

    $order2 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'order2'],
    ]);

    Route::post('/_test/check-precedence', function (\Illuminate\Http\Request $request) {
        return response()->json([
            'order_id' => $request->input('_work_order')->id,
        ]);
    })->middleware(EnforceWorkOrderOnly::class);

    $response = $this->postJson('/_test/check-precedence', [
        '_work_order_id' => $order2->id, // Body
    ], [
        'X-Work-Order-ID' => $order1->id, // Header
    ]);

    $response->assertStatus(200)
        ->assertJson(['order_id' => $order1->id]); // Header should win
});

it('validates multiple allowed states correctly', function () {
    Route::post('/_test/multi-state', function () {
        return response()->json(['success' => true]);
    })->middleware(EnforceWorkOrderOnly::class . ':queued,in_progress,submitted');

    // Test each allowed state
    $states = [OrderState::QUEUED, OrderState::IN_PROGRESS, OrderState::SUBMITTED];

    foreach ($states as $state) {
        $order = WorkOrder::create([
            'type' => 'test.echo',
            'state' => $state,
            'payload' => ['message' => 'test'],
        ]);

        $response = $this->postJson('/_test/multi-state', [], [
            'X-Work-Order-ID' => $order->id,
        ]);

        $response->assertStatus(200);
    }

    // Test disallowed state
    $this->withoutExceptionHandling();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED, // Not in allowed states
        'payload' => ['message' => 'test'],
    ]);

    expect(fn () => $this->postJson('/_test/multi-state', [], [
        'X-Work-Order-ID' => $order->id,
    ]))
        ->toThrow(ForbiddenDirectMutationException::class);
});
