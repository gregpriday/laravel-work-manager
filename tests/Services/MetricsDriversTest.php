<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\Metrics\LogMetricsDriver;
use GregPriday\WorkManager\Services\Metrics\NullMetricsDriver;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// LogMetricsDriver tests
test('LogMetricsDriver::increment logs counter metric', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->increment('test_counter', 5, ['label1' => 'value1']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'COUNTER')
                && str_contains($message, 'test_counter')
                && $context['metric_type'] === 'counter'
                && $context['metric_value'] === 5;
        });
});

test('LogMetricsDriver::gauge logs gauge metric', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->gauge('test_gauge', 42.5, ['type' => 'test']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'GAUGE')
                && str_contains($message, 'test_gauge')
                && $context['metric_type'] === 'gauge'
                && $context['metric_value'] === 42.5;
        });
});

test('LogMetricsDriver::histogram logs histogram metric', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->histogram('test_histogram', 123.45, ['bucket' => '100']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'HISTOGRAM')
                && str_contains($message, 'test_histogram')
                && $context['metric_type'] === 'histogram'
                && $context['metric_value'] === 123.45;
        });
});

test('LogMetricsDriver::recordOrderCreated logs order creation', function () {
    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.sync',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
        'priority' => 5,
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordOrderCreated($order);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'orders_created_total')
                && $context['metric_labels']['type'] === 'test.sync'
                && $context['metric_labels']['priority'] === 5;
        });
});

test('LogMetricsDriver::recordLeaseAcquired logs lease acquisition', function () {
    Log::spy();

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
        'state' => ItemState::LEASED,
        'input' => [],
        'max_attempts' => 3,
        'leased_by_agent_id' => 'agent-123',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordLeaseAcquired($item, 'agent-123');

    Log::shouldHaveReceived('info')
        ->twice() // Once for increment, once for gauge
        ->withArgs(function ($message) {
            return str_contains($message, 'leases_acquired_total')
                || str_contains($message, 'leases_active');
        });
});

test('LogMetricsDriver::recordLeaseReleased logs lease release', function () {
    Log::spy();

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

    $driver = new LogMetricsDriver();
    $driver->recordLeaseReleased($item, 'agent-123');

    Log::shouldHaveReceived('info')
        ->twice() // Once for increment, once for gauge
        ->withArgs(function ($message) {
            return str_contains($message, 'leases_released_total')
                || str_contains($message, 'leases_active');
        });
});

test('LogMetricsDriver::recordLeaseExpired logs expired lease', function () {
    Log::spy();

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
        'state' => ItemState::LEASED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordLeaseExpired($item);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'leases_expired_total');
        });
});

test('LogMetricsDriver::recordItemSubmitted logs item submission', function () {
    Log::spy();

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
        'state' => ItemState::SUBMITTED,
        'input' => [],
        'max_attempts' => 3,
        'leased_by_agent_id' => 'agent-123',
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordItemSubmitted($item);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'items_submitted_total');
        });
});

test('LogMetricsDriver::recordOrderApproved logs approval with duration', function () {
    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPROVED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
        'created_at' => now()->subMinutes(10),
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordOrderApproved($order);

    Log::shouldHaveReceived('info')
        ->twice() // Once for counter, once for histogram
        ->withArgs(function ($message) {
            return str_contains($message, 'orders_approved_total')
                || str_contains($message, 'order_time_to_approval_seconds');
        });
});

test('LogMetricsDriver::recordOrderRejected logs rejection', function () {
    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::REJECTED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordOrderRejected($order);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'orders_rejected_total');
        });
});

test('LogMetricsDriver::recordApplyDuration logs apply duration', function () {
    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPLIED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordApplyDuration($order, 2.5);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'order_apply_duration_seconds')
                && $context['metric_value'] === 2.5;
        });
});

test('LogMetricsDriver::recordApplyFailure logs apply failure', function () {
    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $exception = new \RuntimeException('Test error');

    $driver = new LogMetricsDriver();
    $driver->recordApplyFailure($order, $exception);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'orders_apply_failed_total')
                && $context['metric_labels']['exception_class'] === 'RuntimeException';
        });
});

test('LogMetricsDriver::recordItemFailure logs item failure', function () {
    Log::spy();

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
        'state' => ItemState::FAILED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new LogMetricsDriver();
    $driver->recordItemFailure($item, ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid data']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'items_failed_total')
                && $context['metric_labels']['error_code'] === 'VALIDATION_ERROR';
        });
});

test('LogMetricsDriver::recordQueueDepth logs queue depth', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->recordQueueDepth('test.echo', 42);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'queue_depth');
        });
});

test('LogMetricsDriver formats labels correctly', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->increment('test', 1, ['key1' => 'value1', 'key2' => 'value2']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'key1=value1')
                && str_contains($message, 'key2=value2');
        });
});

test('LogMetricsDriver handles empty labels', function () {
    Log::spy();

    $driver = new LogMetricsDriver();
    $driver->increment('test', 1, []);

    Log::shouldHaveReceived('info')->once();
});

// NullMetricsDriver tests (ensure all methods are covered)
test('NullMetricsDriver::increment does nothing', function () {
    $driver = new NullMetricsDriver();
    $driver->increment('test', 1, ['label' => 'value']);

    // Just ensure no exception is thrown
    expect(true)->toBeTrue();
});

test('NullMetricsDriver::gauge does nothing', function () {
    $driver = new NullMetricsDriver();
    $driver->gauge('test', 1.0, ['label' => 'value']);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::histogram does nothing', function () {
    $driver = new NullMetricsDriver();
    $driver->histogram('test', 1.0, ['label' => 'value']);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordOrderCreated does nothing', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordOrderCreated($order);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordLeaseAcquired does nothing', function () {
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
        'state' => ItemState::LEASED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordLeaseAcquired($item, 'agent-1');

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordLeaseReleased does nothing', function () {
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

    $driver = new NullMetricsDriver();
    $driver->recordLeaseReleased($item, 'agent-1');

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordLeaseExpired does nothing', function () {
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
        'state' => ItemState::LEASED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordLeaseExpired($item);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordItemSubmitted does nothing', function () {
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
        'state' => ItemState::SUBMITTED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordItemSubmitted($item);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordOrderApproved does nothing', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPROVED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordOrderApproved($order);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordOrderRejected does nothing', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::REJECTED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordOrderRejected($order);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordApplyDuration does nothing', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::APPLIED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordApplyDuration($order, 1.5);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordApplyFailure does nothing', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordApplyFailure($order, new \RuntimeException('Test'));

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordItemFailure does nothing', function () {
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
        'state' => ItemState::FAILED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $driver = new NullMetricsDriver();
    $driver->recordItemFailure($item, ['code' => 'TEST']);

    expect(true)->toBeTrue();
});

test('NullMetricsDriver::recordQueueDepth does nothing', function () {
    $driver = new NullMetricsDriver();
    $driver->recordQueueDepth('test.echo', 10);

    expect(true)->toBeTrue();
});
