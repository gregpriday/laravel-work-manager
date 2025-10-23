<?php

use GregPriday\WorkManager\Services\WorkAllocator;

beforeEach(function () {
    $allocator = app(WorkAllocator::class);
    $this->order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($this->order);
});

test('orders:list shows orders', function () {
    $this->artisan('work-manager:orders:list')
        ->assertExitCode(0);
});

test('orders:list can filter by state', function () {
    $this->artisan('work-manager:orders:list', ['--state' => 'queued'])
        ->assertExitCode(0);
});

test('orders:list can output json', function () {
    $this->artisan('work-manager:orders:list', ['--json' => true])
        ->assertExitCode(0);
});

test('items:list shows items', function () {
    $this->artisan('work-manager:items:list')
        ->assertExitCode(0);
});

test('items:list can filter by order', function () {
    $this->artisan('work-manager:items:list', ['--order' => $this->order->id])
        ->assertExitCode(0);
});

test('ops:tail shows events', function () {
    $this->artisan('work-manager:ops:tail', ['--limit' => 10])
        ->assertExitCode(0);
});

test('ops:tail can filter by order', function () {
    $this->artisan('work-manager:ops:tail', [
        '--order' => $this->order->id,
        '--limit' => 10,
    ])->assertExitCode(0);
});
