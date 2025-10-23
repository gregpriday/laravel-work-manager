<?php

use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Create a test order with items
    $allocator = app(WorkAllocator::class);
    $this->order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($this->order);
});

test('lease:release can release leased items', function () {
    $leases = app(LeaseService::class);
    $item = $this->order->items->first();

    // Acquire lease
    $leases->acquire($item->id, 'test-agent');

    // Release via command
    $this->artisan('work-manager:lease:release', ['--agent' => 'test-agent'])
        ->assertExitCode(0);

    expect($item->fresh()->leased_by_agent_id)->toBeNull();
    expect($item->fresh()->state)->toBe(ItemState::QUEUED);
});

test('lease:release requires selector', function () {
    $this->artisan('work-manager:lease:release')
        ->assertExitCode(1);
});

test('lease:release dry-run shows what would happen', function () {
    $leases = app(LeaseService::class);
    $item = $this->order->items->first();

    $leases->acquire($item->id, 'test-agent');

    $this->artisan('work-manager:lease:release', ['--all' => true, '--dry-run' => true])
        ->assertExitCode(0);

    // Item should still be leased
    expect($item->fresh()->leased_by_agent_id)->toBe('test-agent');
});

test('lease:reclaim can reclaim expired leases', function () {
    $item = $this->order->items->first();
    $leases = app(LeaseService::class);

    // Acquire and expire lease
    $leases->acquire($item->id, 'test-agent');
    $item->lease_expires_at = now()->subHour();
    $item->save();

    $this->artisan('work-manager:lease:reclaim')
        ->assertExitCode(0);

    expect($item->fresh()->leased_by_agent_id)->toBeNull();
});

test('lease:extend can extend lease TTL', function () {
    $item = $this->order->items->first();
    $leases = app(LeaseService::class);

    Carbon::setTestNow(now());
    $leases->acquire($item->id, 'test-agent');
    $originalExpiry = $item->fresh()->lease_expires_at;

    Carbon::setTestNow(now()->addSecond());

    $this->artisan('work-manager:lease:extend', ['--agent' => 'test-agent'])
        ->assertExitCode(0);

    expect($item->fresh()->lease_expires_at)->toBeGreaterThan($originalExpiry);

    Carbon::setTestNow(); // Reset
});
