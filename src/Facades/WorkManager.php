<?php

namespace GregPriday\WorkManager\Facades;

use GregPriday\WorkManager\Routing\RouteRegistrar;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void routes(string $basePath = 'agent/work', array $middleware = ['api'])
 * @method static OrderTypeRegistry registry()
 * @method static WorkAllocator allocator()
 * @method static WorkExecutor executor()
 *
 * @see \GregPriday\WorkManager\WorkManagerServiceProvider
 */
class WorkManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'work-manager';
    }

    /**
     * Register routes using the facade.
     */
    public static function routes(string $basePath = 'agent/work', array $middleware = ['api']): void
    {
        app(RouteRegistrar::class)->register($basePath, $middleware);
    }

    /**
     * Get the order type registry.
     */
    public static function registry(): OrderTypeRegistry
    {
        return app(OrderTypeRegistry::class);
    }

    /**
     * Get the work allocator.
     */
    public static function allocator(): WorkAllocator
    {
        return app(WorkAllocator::class);
    }

    /**
     * Get the work executor.
     */
    public static function executor(): WorkExecutor
    {
        return app(WorkExecutor::class);
    }
}
