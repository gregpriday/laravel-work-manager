<?php

namespace GregPriday\WorkManager\Tests;

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Tests\Fixtures\OrderTypes\BatchOrderType;
use GregPriday\WorkManager\Tests\Fixtures\OrderTypes\EchoOrderType;
use GregPriday\WorkManager\Tests\Fixtures\OrderTypes\TestPartialOrderType;
use GregPriday\WorkManager\WorkManagerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register test fixtures
        $this->registerTestOrderTypes();

        // Bypass all authorization checks in tests
        \Illuminate\Support\Facades\Gate::before(function () {
            return true;
        });
    }

    protected function defineEnvironment($app)
    {
        // Define authorization abilities that always return true for tests
        // This runs during application bootstrap before policies are registered
        $app['events']->listen('Illuminate\Auth\Events\Registered', function () {
            // Gate callbacks
        });
    }

    protected function resolveApplicationHttpKernel($app)
    {
        return parent::resolveApplicationHttpKernel($app);
    }

    protected function getPackageProviders($app)
    {
        return [
            WorkManagerServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true, // Enable foreign key constraints
        ]);

        // Configure work-manager for testing
        config()->set('work-manager.lease.ttl_seconds', 120);
        config()->set('work-manager.lease.heartbeat_every_seconds', 30);
        config()->set('work-manager.retry.default_max_attempts', 2);
    }

    protected function registerTestOrderTypes(): void
    {
        WorkManager::registry()->register(new EchoOrderType);
        WorkManager::registry()->register(new BatchOrderType);
        WorkManager::registry()->register(new TestPartialOrderType);
    }
}
