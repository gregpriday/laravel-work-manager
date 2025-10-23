<?php

use GregPriday\WorkManager\Mcp\WorkManagerTools;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Policies\WorkOrderPolicy;
use GregPriday\WorkManager\Routing\RouteRegistrar;
use GregPriday\WorkManager\Services\IdempotencyService;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Services\StateMachine;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use Illuminate\Support\Facades\Gate;

test('OrderTypeRegistry is registered as singleton', function () {
    $registry1 = app(OrderTypeRegistry::class);
    $registry2 = app(OrderTypeRegistry::class);

    expect($registry1)->toBeInstanceOf(OrderTypeRegistry::class);
    expect($registry1)->toBe($registry2);
});

test('StateMachine is registered as singleton', function () {
    $stateMachine1 = app(StateMachine::class);
    $stateMachine2 = app(StateMachine::class);

    expect($stateMachine1)->toBeInstanceOf(StateMachine::class);
    expect($stateMachine1)->toBe($stateMachine2);
});

test('IdempotencyService is registered as singleton', function () {
    $service1 = app(IdempotencyService::class);
    $service2 = app(IdempotencyService::class);

    expect($service1)->toBeInstanceOf(IdempotencyService::class);
    expect($service1)->toBe($service2);
});

test('LeaseService is registered as singleton with dependencies', function () {
    $service1 = app(LeaseService::class);
    $service2 = app(LeaseService::class);

    expect($service1)->toBeInstanceOf(LeaseService::class);
    expect($service1)->toBe($service2);
});

test('WorkAllocator is registered as singleton with dependencies', function () {
    $allocator1 = app(WorkAllocator::class);
    $allocator2 = app(WorkAllocator::class);

    expect($allocator1)->toBeInstanceOf(WorkAllocator::class);
    expect($allocator1)->toBe($allocator2);
});

test('WorkExecutor is registered as singleton with dependencies', function () {
    $executor1 = app(WorkExecutor::class);
    $executor2 = app(WorkExecutor::class);

    expect($executor1)->toBeInstanceOf(WorkExecutor::class);
    expect($executor1)->toBe($executor2);
});

test('RouteRegistrar is registered as singleton', function () {
    $registrar1 = app(RouteRegistrar::class);
    $registrar2 = app(RouteRegistrar::class);

    expect($registrar1)->toBeInstanceOf(RouteRegistrar::class);
    expect($registrar1)->toBe($registrar2);
});

test('WorkManagerTools is registered as singleton with dependencies', function () {
    $tools1 = app(WorkManagerTools::class);
    $tools2 = app(WorkManagerTools::class);

    expect($tools1)->toBeInstanceOf(WorkManagerTools::class);
    expect($tools1)->toBe($tools2);
});

test('work-manager accessor is registered as singleton', function () {
    $accessor1 = app('work-manager');
    $accessor2 = app('work-manager');

    expect($accessor1)->toBe($accessor2);
});

test('work-manager accessor delegates registry method', function () {
    $accessor = app('work-manager');
    $result = $accessor->registry();

    expect($result)->toBeInstanceOf(OrderTypeRegistry::class);
});

test('work-manager accessor delegates allocator method', function () {
    $accessor = app('work-manager');
    $result = $accessor->allocator();

    expect($result)->toBeInstanceOf(WorkAllocator::class);
});

test('work-manager accessor delegates executor method', function () {
    $accessor = app('work-manager');
    $result = $accessor->executor();

    expect($result)->toBeInstanceOf(WorkExecutor::class);
});

test('work-manager accessor throws exception for unknown method', function () {
    $accessor = app('work-manager');
    $accessor->unknownMethod();
})->throws(BadMethodCallException::class, 'Method unknownMethod does not exist');

test('WorkOrderPolicy is registered via Gate', function () {
    $policy = Gate::getPolicyFor(WorkOrder::class);

    expect($policy)->toBeInstanceOf(WorkOrderPolicy::class);
});

test('commands are registered when running in console', function () {
    if (! $this->app->runningInConsole()) {
        $this->markTestSkipped('This test requires console mode');
    }

    $commands = \Illuminate\Support\Facades\Artisan::all();

    expect($commands)->toHaveKeys([
        'work-manager:generate',
        'work-manager:maintain',
        'work-manager:mcp',
    ]);
});

test('routes can be registered via registerRoutes method', function () {
    $provider = new \GregPriday\WorkManager\WorkManagerServiceProvider($this->app);

    config()->set('work-manager.routes.base_path', 'test/work');
    config()->set('work-manager.routes.middleware', ['api']);

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('registerRoutes');
    $method->setAccessible(true);

    // This should not throw an exception
    $method->invoke($provider);

    expect(true)->toBeTrue();
});

test('registerMcpTools can be called when MCP class exists', function () {
    // Skip if MCP is not available
    if (! class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('MCP package not available');
    }

    $provider = new \GregPriday\WorkManager\WorkManagerServiceProvider($this->app);
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('registerMcpTools');
    $method->setAccessible(true);

    // This should not throw an exception
    $method->invoke($provider);

    expect(true)->toBeTrue();
});

test('config is merged from package', function () {
    expect(config('work-manager'))->toBeArray();
    expect(config('work-manager.lease.ttl_seconds'))->toBeInt();
    expect(config('work-manager.routes'))->toBeArray();
});

test('all core services can be resolved without errors', function () {
    $services = [
        OrderTypeRegistry::class,
        StateMachine::class,
        IdempotencyService::class,
        LeaseService::class,
        WorkAllocator::class,
        WorkExecutor::class,
        RouteRegistrar::class,
        WorkManagerTools::class,
    ];

    foreach ($services as $service) {
        $instance = app($service);
        expect($instance)->toBeInstanceOf($service);
    }
});
