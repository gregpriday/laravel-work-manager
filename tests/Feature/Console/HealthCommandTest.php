<?php

use GregPriday\WorkManager\Services\WorkAllocator;

test('ops:check command shows health status', function () {
    $this->artisan('work-manager:ops:check')
        ->assertExitCode(0);
});

test('ops:check command can output json', function () {
    $this->artisan('work-manager:ops:check', ['--json' => true])
        ->assertExitCode(0);
});

test('ops:check command shows database status', function () {
    $allocator = app(WorkAllocator::class);
    $allocator->propose('test.echo', ['message' => 'test']);

    $this->artisan('work-manager:ops:check')
        ->expectsOutputToContain('Database')
        ->assertExitCode(0);
});
