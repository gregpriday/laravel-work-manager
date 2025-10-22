<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Routing\RouteRegistrar;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;

test('routes method delegates to RouteRegistrar with default parameters', function () {
    $registrar = Mockery::mock(RouteRegistrar::class);
    $registrar->shouldReceive('register')
        ->once()
        ->with('agent/work', ['api']);

    app()->instance(RouteRegistrar::class, $registrar);

    WorkManager::routes();
});

test('routes method delegates to RouteRegistrar with custom parameters', function () {
    $registrar = Mockery::mock(RouteRegistrar::class);
    $registrar->shouldReceive('register')
        ->once()
        ->with('custom/path', ['api', 'auth']);

    app()->instance(RouteRegistrar::class, $registrar);

    WorkManager::routes('custom/path', ['api', 'auth']);
});

test('registry method returns OrderTypeRegistry instance', function () {
    $registry = WorkManager::registry();

    expect($registry)->toBeInstanceOf(OrderTypeRegistry::class);
});

test('registry method returns same instance on multiple calls', function () {
    $registry1 = WorkManager::registry();
    $registry2 = WorkManager::registry();

    expect($registry1)->toBe($registry2);
});

test('allocator method returns WorkAllocator instance', function () {
    $allocator = WorkManager::allocator();

    expect($allocator)->toBeInstanceOf(WorkAllocator::class);
});

test('allocator method returns same instance on multiple calls', function () {
    $allocator1 = WorkManager::allocator();
    $allocator2 = WorkManager::allocator();

    expect($allocator1)->toBe($allocator2);
});

test('executor method returns WorkExecutor instance', function () {
    $executor = WorkManager::executor();

    expect($executor)->toBeInstanceOf(WorkExecutor::class);
});

test('executor method returns same instance on multiple calls', function () {
    $executor1 = WorkManager::executor();
    $executor2 = WorkManager::executor();

    expect($executor1)->toBe($executor2);
});

test('calling non-existent method on work-manager accessor throws BadMethodCallException', function () {
    $accessor = app('work-manager');
    $accessor->nonExistentMethod();
})->throws(BadMethodCallException::class, 'Method nonExistentMethod does not exist');
