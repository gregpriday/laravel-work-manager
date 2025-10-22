<?php

namespace GregPriday\WorkManager;

use GregPriday\WorkManager\Console\GenerateCommand;
use GregPriday\WorkManager\Console\MaintainCommand;
use GregPriday\WorkManager\Console\McpCommand;
use GregPriday\WorkManager\Contracts\LeaseBackend;
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
use Illuminate\Support\ServiceProvider;

class WorkManagerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/work-manager.php',
            'work-manager'
        );

        // Register the main facade accessor
        $this->app->singleton('work-manager', function ($app) {
            return new class
            {
                public function __call($method, $args)
                {
                    // Delegate to appropriate services
                    return match ($method) {
                        'registry' => app(OrderTypeRegistry::class),
                        'allocator' => app(WorkAllocator::class),
                        'executor' => app(WorkExecutor::class),
                        default => throw new \BadMethodCallException("Method {$method} does not exist"),
                    };
                }
            };
        });

        // Register core services
        $this->app->singleton(OrderTypeRegistry::class, function () {
            return new OrderTypeRegistry();
        });

        $this->app->singleton(StateMachine::class, function ($app) {
            return new StateMachine();
        });

        $this->app->singleton(IdempotencyService::class, function ($app) {
            return new IdempotencyService();
        });

        $this->app->singleton(LeaseService::class, function ($app) {
            return new LeaseService(
                $app->make(StateMachine::class)
            );
        });

        $this->app->singleton(WorkAllocator::class, function ($app) {
            return new WorkAllocator(
                $app->make(OrderTypeRegistry::class),
                $app->make(StateMachine::class)
            );
        });

        $this->app->singleton(WorkExecutor::class, function ($app) {
            return new WorkExecutor(
                $app->make(OrderTypeRegistry::class),
                $app->make(StateMachine::class)
            );
        });

        $this->app->singleton(RouteRegistrar::class, function ($app) {
            return new RouteRegistrar($app->make('router'));
        });

        // Register MCP tools service
        $this->app->singleton(WorkManagerTools::class, function ($app) {
            return new WorkManagerTools(
                $app->make(WorkAllocator::class),
                $app->make(WorkExecutor::class),
                $app->make(LeaseService::class),
                $app->make(IdempotencyService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/work-manager.php' => config_path('work-manager.php'),
            ], 'work-manager-config');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'work-manager-migrations');

            // Register commands
            $this->commands([
                GenerateCommand::class,
                MaintainCommand::class,
                McpCommand::class,
            ]);
        }

        // Register MCP tools if the MCP package is available
        if (class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
            $this->registerMcpTools();
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register policies
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);

        // Auto-register routes if enabled
        if (config('work-manager.routes.enabled', false)) {
            $this->registerRoutes();
        }
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        $basePath = config('work-manager.routes.base_path', 'agent/work');
        $middleware = config('work-manager.routes.middleware', ['api']);

        $this->app->make(RouteRegistrar::class)->register($basePath, $middleware);
    }

    /**
     * Register MCP tools with the MCP server.
     */
    protected function registerMcpTools(): void
    {
        $tools = $this->app->make(WorkManagerTools::class);

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'propose'])
            ->name('work.propose')
            ->description('Create a new work order to be processed by agents');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'list'])
            ->name('work.list')
            ->description('List work orders with optional filtering');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'get'])
            ->name('work.get')
            ->description('Get detailed information about a specific work order');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'checkout'])
            ->name('work.checkout')
            ->description('Checkout (lease) the next available work item from an order');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'heartbeat'])
            ->name('work.heartbeat')
            ->description('Extend the lease on a work item by sending a heartbeat');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'submit'])
            ->name('work.submit')
            ->description('Submit the results of a completed work item');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'approve'])
            ->name('work.approve')
            ->description('Approve a work order and apply the changes');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'reject'])
            ->name('work.reject')
            ->description('Reject a work order with error details');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'release'])
            ->name('work.release')
            ->description('Release the lease on a work item');

        \PhpMcp\Laravel\Facades\Mcp::tool([$tools, 'logs'])
            ->name('work.logs')
            ->description('Get event logs for a work item or order');
    }
}
