<?php

use GregPriday\WorkManager\Contracts\AllocatorStrategy;
use GregPriday\WorkManager\Contracts\PlannerPort;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generate command warns when no strategies are registered', function () {
    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutput('No allocator strategies registered. Register strategies in your AppServiceProvider.')
        ->assertExitCode(0);
});

test('generate command processes AllocatorStrategy successfully', function () {
    // Create a test strategy that implements AllocatorStrategy
    $strategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [
                [
                    'type' => 'test.echo',
                    'payload' => ['message' => 'hello'],
                    'priority' => 5,
                ],
                [
                    'type' => 'test.echo',
                    'payload' => ['message' => 'world'],
                    'meta' => ['source' => 'test'],
                ],
            ];
        }
    };

    $strategyClass = get_class($strategy);

    // Bind and tag the strategy in the container
    app()->bind($strategyClass, fn () => $strategy);
    app()->tag($strategyClass, 'work-manager.strategies');

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Running strategy:')
        ->expectsOutputToContain('Discovered 2 work order(s)')
        ->expectsOutputToContain('Created order:')
        ->expectsOutput('Generated 2 work orders.')
        ->assertExitCode(0);

    // Verify orders were created
    expect(WorkOrder::count())->toBe(2);
    expect(WorkOrder::first()->type)->toBe('test.echo');
    expect(WorkOrder::first()->payload)->toEqual(['message' => 'hello']);
    expect(WorkOrder::first()->priority)->toBe(5);
    expect(WorkOrder::first()->requested_by_type)->toBe(ActorType::SYSTEM);
    expect(WorkOrder::first()->requested_by_id)->toBe('scheduler');
});

test('generate command processes PlannerPort successfully', function () {
    // Create a test strategy that implements PlannerPort
    $strategy = new class implements PlannerPort
    {
        public function generateOrders(): array
        {
            return [
                [
                    'type' => 'test.batch',
                    'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
                    'priority' => 10,
                ],
            ];
        }
    };

    // Tag the strategy in the container
    app()->tag([get_class($strategy)], 'work-manager.strategies');
    app()->instance(get_class($strategy), $strategy);

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Running strategy:')
        ->expectsOutputToContain('Discovered 1 work order(s)')
        ->expectsOutput('Generated 1 work orders.')
        ->assertExitCode(0);

    // Verify order was created
    expect(WorkOrder::count())->toBe(1);
    expect(WorkOrder::first()->type)->toBe('test.batch');
});

test('generate command handles strategy with no work discovered', function () {
    // Create a strategy that returns empty work
    $strategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [];
        }
    };

    app()->tag([get_class($strategy)], 'work-manager.strategies');
    app()->instance(get_class($strategy), $strategy);

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Running strategy:')
        ->expectsOutputToContain('No work discovered')
        ->expectsOutput('Generated 0 work orders.')
        ->assertExitCode(0);

    expect(WorkOrder::count())->toBe(0);
});

test('generate command handles strategy errors gracefully', function () {
    // Create a strategy that throws an exception
    $strategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            throw new \RuntimeException('Strategy failed to discover work');
        }
    };

    app()->tag([get_class($strategy)], 'work-manager.strategies');
    app()->instance(get_class($strategy), $strategy);

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Running strategy:')
        ->expectsOutputToContain('Error: Strategy failed to discover work')
        ->expectsOutput('Generated 0 work orders.')
        ->assertExitCode(0);

    expect(WorkOrder::count())->toBe(0);
});

test('generate command dry-run shows what would be created without creating orders', function () {
    $strategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [
                [
                    'type' => 'test.echo',
                    'payload' => ['message' => 'test1'],
                ],
                [
                    'type' => 'test.echo',
                    'payload' => ['message' => 'test2'],
                ],
            ];
        }
    };

    app()->tag([get_class($strategy)], 'work-manager.strategies');
    app()->instance(get_class($strategy), $strategy);

    $this->artisan('work-manager:generate', ['--dry-run' => true])
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Running strategy:')
        ->expectsOutputToContain('Discovered 2 work order(s)')
        ->expectsOutputToContain('[DRY RUN] Would create: test.echo')
        ->expectsOutput('Dry run complete. Would have created 0 orders.')
        ->assertExitCode(0);

    // Verify NO orders were created in dry-run mode
    expect(WorkOrder::count())->toBe(0);
});

test('generate command processes multiple strategies', function () {
    // Create two different strategies
    $strategy1 = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [
                ['type' => 'test.echo', 'payload' => ['message' => 'from-strategy-1']],
            ];
        }
    };

    $strategy2 = new class implements PlannerPort
    {
        public function generateOrders(): array
        {
            return [
                ['type' => 'test.batch', 'payload' => ['batches' => [['id' => 'b', 'data' => []]]]],
            ];
        }
    };

    // Tag both strategies
    app()->tag([get_class($strategy1), get_class($strategy2)], 'work-manager.strategies');
    app()->instance(get_class($strategy1), $strategy1);
    app()->instance(get_class($strategy2), $strategy2);

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutput('Generated 2 work orders.')
        ->assertExitCode(0);

    expect(WorkOrder::count())->toBe(2);
    expect(WorkOrder::where('type', 'test.echo')->count())->toBe(1);
    expect(WorkOrder::where('type', 'test.batch')->count())->toBe(1);
});

test('generate command continues processing after one strategy fails', function () {
    // Strategy that fails
    $failingStrategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            throw new \Exception('First strategy failed');
        }
    };

    // Strategy that succeeds
    $successStrategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [
                ['type' => 'test.echo', 'payload' => ['message' => 'success']],
            ];
        }
    };

    app()->tag([get_class($failingStrategy), get_class($successStrategy)], 'work-manager.strategies');
    app()->instance(get_class($failingStrategy), $failingStrategy);
    app()->instance(get_class($successStrategy), $successStrategy);

    $this->artisan('work-manager:generate')
        ->expectsOutput('Discovering work to be done...')
        ->expectsOutputToContain('Error: First strategy failed')
        ->expectsOutput('Generated 1 work orders.')
        ->assertExitCode(0);

    expect(WorkOrder::count())->toBe(1);
    expect(WorkOrder::first()->payload)->toEqual(['message' => 'success']);
});

test('generate command stores meta data when provided', function () {
    $strategy = new class implements AllocatorStrategy
    {
        public function discoverWork(): array
        {
            return [
                [
                    'type' => 'test.echo',
                    'payload' => ['message' => 'test'],
                    'meta' => ['source' => 'automated', 'batch_id' => '123'],
                ],
            ];
        }
    };

    app()->tag([get_class($strategy)], 'work-manager.strategies');
    app()->instance(get_class($strategy), $strategy);

    $this->artisan('work-manager:generate')
        ->assertExitCode(0);

    $order = WorkOrder::first();
    expect($order->meta)->toEqual(['source' => 'automated', 'batch_id' => '123']);
});
