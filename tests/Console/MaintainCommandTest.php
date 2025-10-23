<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('maintain command runs all tasks when no options specified', function () {
    $this->artisan('work-manager:maintain')
        ->expectsOutput('Reclaiming expired leases...')
        ->expectsOutput('Processing failed orders for dead lettering...')
        ->expectsOutput('Checking for stale orders...')
        ->assertExitCode(0);
});

test('maintain command runs only reclaim-leases when flag specified', function () {
    $this->artisan('work-manager:maintain', ['--reclaim-leases' => true])
        ->expectsOutput('Reclaiming expired leases...')
        ->doesntExpectOutput('Processing failed orders for dead lettering...')
        ->doesntExpectOutput('Checking for stale orders...')
        ->assertExitCode(0);
});

test('maintain command runs only dead-letter when flag specified', function () {
    $this->artisan('work-manager:maintain', ['--dead-letter' => true])
        ->expectsOutput('Processing failed orders for dead lettering...')
        ->doesntExpectOutput('Reclaiming expired leases...')
        ->doesntExpectOutput('Checking for stale orders...')
        ->assertExitCode(0);
});

test('maintain command runs only check-stale when flag specified', function () {
    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutput('Checking for stale orders...')
        ->doesntExpectOutput('Reclaiming expired leases...')
        ->doesntExpectOutput('Processing failed orders for dead lettering...')
        ->assertExitCode(0);
});

test('maintain command reclaims expired leases', function () {
    // Create items with expired leases
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'test'],
    ]);

    WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::IN_PROGRESS,
        'input' => [],
        'max_attempts' => 3,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->subMinutes(10), // Expired
    ]);

    WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::IN_PROGRESS,
        'input' => [],
        'max_attempts' => 3,
        'leased_by_agent_id' => 'agent-2',
        'lease_expires_at' => now()->subMinutes(5), // Expired
    ]);

    $this->artisan('work-manager:maintain', ['--reclaim-leases' => true])
        ->expectsOutput('Reclaiming expired leases...')
        ->expectsOutputToContain('Reclaimed 2 expired lease(s)')
        ->assertExitCode(0);
});

test('maintain command dead letters failed orders after threshold', function () {
    config()->set('work-manager.maintenance.dead_letter_after_hours', 48);

    // Create failed order that's old enough to be dead-lettered
    $oldOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'payload' => ['message' => 'old'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    // Manually set updated_at to simulate old order
    $oldOrder->updated_at = now()->subHours(50);
    $oldOrder->save();

    // Create failed order that's too recent
    $recentOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'payload' => ['message' => 'recent'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $recentOrder->updated_at = now()->subHours(24);
    $recentOrder->save();

    $this->artisan('work-manager:maintain', ['--dead-letter' => true])
        ->expectsOutput('Processing failed orders for dead lettering...')
        ->expectsOutputToContain('Dead lettered order:')
        ->expectsOutputToContain('Dead lettered 1 order(s)/item(s)')
        ->assertExitCode(0);

    // Verify old order was dead-lettered
    expect($oldOrder->fresh()->state)->toBe(OrderState::DEAD_LETTERED);

    // Verify recent order was NOT dead-lettered
    expect($recentOrder->fresh()->state)->toBe(OrderState::FAILED);
});

test('maintain command dead letters failed items after threshold', function () {
    config()->set('work-manager.maintenance.dead_letter_after_hours', 48);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);

    // Create failed item that's old enough
    $oldItem = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::FAILED,
        'input' => [],
        'max_attempts' => 3,
    ]);
    $oldItem->updated_at = now()->subHours(72);
    $oldItem->save();

    // Create failed item that's too recent
    $recentItem = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::FAILED,
        'input' => [],
        'max_attempts' => 3,
    ]);
    $recentItem->updated_at = now()->subHours(12);
    $recentItem->save();

    $this->artisan('work-manager:maintain', ['--dead-letter' => true])
        ->expectsOutput('Processing failed orders for dead lettering...')
        ->assertExitCode(0);

    expect($oldItem->fresh()->state)->toBe(ItemState::DEAD_LETTERED);
    expect($recentItem->fresh()->state)->toBe(ItemState::FAILED);
});

test('maintain command uses custom dead letter threshold from config', function () {
    config()->set('work-manager.maintenance.dead_letter_after_hours', 12);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'payload' => ['message' => 'test'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->updated_at = now()->subHours(15);
    $order->save();

    $this->artisan('work-manager:maintain', ['--dead-letter' => true])
        ->assertExitCode(0);

    expect($order->fresh()->state)->toBe(OrderState::DEAD_LETTERED);
});

test('maintain command reports no stale orders when none found', function () {
    // Create a completed order (should not be flagged as stale)
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'done'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->created_at = now()->subDays(10);
    $order->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutput('Checking for stale orders...')
        ->expectsOutput('  No stale orders found')
        ->assertExitCode(0);
});

test('maintain command reports stale orders', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);

    // Create stale order (queued for more than 24 hours)
    $staleOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'stale'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $staleOrder->created_at = now()->subHours(48);
    $staleOrder->save();

    // Create recent order (should not be flagged)
    $recentOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'recent'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $recentOrder->created_at = now()->subHours(12);
    $recentOrder->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutput('Checking for stale orders...')
        ->expectsOutputToContain('Found 1 stale order(s):')
        ->expectsOutputToContain($staleOrder->id)
        ->assertExitCode(0);
});

test('maintain command does not flag completed orders as stale', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);

    // Completed order, even if old, should not be stale
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'done'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->created_at = now()->subDays(30);
    $order->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutput('  No stale orders found')
        ->assertExitCode(0);
});

test('maintain command does not flag dead lettered orders as stale', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);

    // Dead lettered order should not be flagged as stale
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::DEAD_LETTERED,
        'payload' => ['message' => 'dead'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->created_at = now()->subDays(30);
    $order->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutput('  No stale orders found')
        ->assertExitCode(0);
});

test('maintain command logs warning when alerts enabled and stale orders found', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);
    config()->set('work-manager.maintenance.enable_alerts', true);

    Log::spy();

    $staleOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'stale'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $staleOrder->created_at = now()->subHours(48);
    $staleOrder->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->assertExitCode(0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function ($message, $context) use ($staleOrder) {
            return $message === 'Stale work orders detected'
                && $context['count'] === 1
                && in_array($staleOrder->id, $context['order_ids']);
        });
});

test('maintain command does not log when alerts disabled', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);
    config()->set('work-manager.maintenance.enable_alerts', false);

    Log::spy();

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'stale'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->created_at = now()->subHours(48);
    $order->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->assertExitCode(0);

    Log::shouldNotHaveReceived('warning');
});

test('maintain command uses custom stale order threshold from config', function () {
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 6);

    // Order that's 8 hours old should be stale with 6-hour threshold
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $order->created_at = now()->subHours(8);
    $order->save();

    $this->artisan('work-manager:maintain', ['--check-stale' => true])
        ->expectsOutputToContain('Found 1 stale order(s):')
        ->assertExitCode(0);
});

test('maintain command can run multiple tasks simultaneously', function () {
    config()->set('work-manager.maintenance.dead_letter_after_hours', 48);
    config()->set('work-manager.maintenance.stale_order_threshold_hours', 24);

    // Create data for all tasks
    $failedOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::FAILED,
        'payload' => ['message' => 'failed'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $failedOrder->updated_at = now()->subHours(72);
    $failedOrder->save();

    $staleOrder = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'stale'],
        'requested_by_type' => 'agent',
        'requested_by_id' => 'agent-1',
    ]);
    $staleOrder->created_at = now()->subHours(48);
    $staleOrder->save();

    $this->artisan('work-manager:maintain', [
        '--reclaim-leases' => true,
        '--dead-letter' => true,
        '--check-stale' => true,
    ])
        ->expectsOutput('Reclaiming expired leases...')
        ->expectsOutput('Processing failed orders for dead lettering...')
        ->expectsOutput('Checking for stale orders...')
        ->assertExitCode(0);
});
