<?php

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;

test('dev:reset truncates tables', function () {
    $allocator = app(WorkAllocator::class);
    $allocator->propose('test.echo', ['message' => 'test']);

    expect(WorkOrder::count())->toBeGreaterThan(0);

    $this->artisan('work-manager:dev:reset')
        ->expectsConfirmation('This will delete ALL work manager data. Continue?', 'yes')
        ->assertExitCode(0);

    expect(WorkOrder::count())->toBe(0);
});

test('dev:seed creates sample orders', function () {
    $this->artisan('work-manager:dev:seed', [
        '--type' => 'test.echo',
        '--count' => 3,
    ])->assertExitCode(0);

    expect(WorkOrder::where('type', 'test.echo')->count())->toBe(3);
});

test('dev:seed with auto-plan plans orders', function () {
    $this->artisan('work-manager:dev:seed', [
        '--type' => 'test.echo',
        '--count' => 2,
        '--auto-plan' => true,
    ])->assertExitCode(0);

    $order = WorkOrder::where('type', 'test.echo')->first();
    expect($order->items)->not->toBeEmpty();
});

test('dev:seed requires type', function () {
    $this->artisan('work-manager:dev:seed')
        ->assertExitCode(1);
});
