<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\OrderState;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
});

it('filters orders by state', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'queued'],
        'priority' => 1,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'in_progress'],
        'priority' => 1,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'completed'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/ai/work/orders?state=queued');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['state'])->toBe('queued');
});

it('filters orders by type', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'echo'],
        'priority' => 1,
    ]);

    WorkOrder::create([
        'type' => 'test.batch',
        'state' => OrderState::QUEUED,
        'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
        'priority' => 1,
    ]);

    $response = $this->getJson('/ai/work/orders?type=test.echo');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['type'])->toBe('test.echo');
});

it('orders by priority desc then created_at asc', function () {
    // Use a unique type to isolate these test orders
    $testType = 'test.echo.ordering.' . uniqid();

    // Register a test type for this specific test
    WorkManager::registry()->register(new class($testType) extends \GregPriday\WorkManager\Tests\Fixtures\OrderTypes\EchoOrderType
    {
        public function __construct(private string $typeOverride)
        {
        }

        public function type(): string
        {
            return $this->typeOverride;
        }
    });

    // Lower priority, older
    $order1 = WorkOrder::create([
        'type' => $testType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-old'],
        'priority' => 1,
        'created_at' => now()->subMinutes(10),
    ]);

    // Lower priority, newer
    $order2 = WorkOrder::create([
        'type' => $testType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-new'],
        'priority' => 1,
        'created_at' => now(),
    ]);

    // Highest priority, should come first
    $order3 = WorkOrder::create([
        'type' => $testType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 10,
        'created_at' => now()->subMinutes(5),
    ]);

    $response = $this->getJson("/ai/work/orders?type={$testType}");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(3);
    // Highest priority first
    expect($data[0]['id'])->toBe($order3->id);
    expect($data[0]['priority'])->toBe(10);

    // Among same priority, older comes first
    expect($data[1]['id'])->toBe($order1->id);
    expect($data[2]['id'])->toBe($order2->id);
});

it('respects pagination limit', function () {
    for ($i = 0; $i < 10; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $response = $this->getJson('/ai/work/orders?limit=5');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(5);
    expect($response->json('per_page'))->toBe(5);
});

it('enforces maximum limit of 100', function () {
    for ($i = 0; $i < 150; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $response = $this->getJson('/ai/work/orders?limit=200');

    $response->assertOk();
    // Should be capped at 100
    expect($response->json('per_page'))->toBe(100);
});

it('filters by multiple criteria', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 1,
    ]);

    WorkOrder::create([
        'type' => 'test.batch',
        'state' => OrderState::QUEUED,
        'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
        'priority' => 1,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::COMPLETED,
        'payload' => ['message' => 'no-match'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/ai/work/orders?state=queued&type=test.echo');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['state'])->toBe('queued');
    expect($data[0]['type'])->toBe('test.echo');
});

it('includes items relationship in response', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $response = $this->getJson('/ai/work/orders');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['items'])->toBeArray();
    expect($data[0]['items'])->not->toBeEmpty();
});
