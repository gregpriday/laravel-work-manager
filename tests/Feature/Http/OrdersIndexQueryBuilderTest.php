<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    WorkManager::routes('agent/work', ['api']);
});

it('preserves default behaviour with items included and default sort', function () {
    // Create orders with different priorities and timestamps
    $order1 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low'],
        'priority' => 5,
        'created_at' => now()->subMinutes(5),
    ]);

    $order2 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 10,
        'created_at' => now(),
    ]);

    $order3 = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'same-priority-old'],
        'priority' => 5,
        'created_at' => now()->subMinutes(10),
    ]);

    // Create items for orders
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);
    $allocator->plan($order1);
    $allocator->plan($order2);
    $allocator->plan($order3);

    $response = $this->getJson('/agent/work/orders');

    $response->assertOk();
    $data = $response->json('data');

    // Verify default sorting: priority desc, created_at asc
    expect($data[0]['id'])->toBe($order2->id); // highest priority (10)
    expect($data[1]['id'])->toBe($order3->id); // priority 5, older
    expect($data[2]['id'])->toBe($order1->id); // priority 5, newer

    // Verify items relation is included
    foreach ($data as $order) {
        expect($order['items'])->toBeArray();
    }
});

it('filters with exact state filter', function () {
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

    $response = $this->getJson('/agent/work/orders?filter[state]=queued');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['state'])->toBe('queued');
});

it('filters with exact type filter', function () {
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

    $response = $this->getJson('/agent/work/orders?filter[type]=test.echo');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['type'])->toBe('test.echo');
});

it('filters with exact requested_by_type filter', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'agent'],
        'priority' => 1,
        'requested_by_type' => \GregPriday\WorkManager\Support\ActorType::AGENT,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'user'],
        'priority' => 1,
        'requested_by_type' => \GregPriday\WorkManager\Support\ActorType::USER,
    ]);

    $response = $this->getJson('/agent/work/orders?filter[requested_by_type]=agent');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['requested_by_type'])->toBe('agent');
});

it('filters with relation filter items.state', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);

    // Order with queued items (propose already calls plan)
    $order1 = $allocator->propose('test.echo', ['message' => 'test1']);

    // Order with in-progress items
    $order2 = $allocator->propose('test.echo', ['message' => 'test2']);
    $order2->items()->update(['state' => ItemState::IN_PROGRESS]);

    $response = $this->getJson('/agent/work/orders?filter[items.state]=queued');

    $response->assertOk();
    $data = $response->json('data');

    // Should only return orders with at least one queued item
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($order1->id);
});

it('filters with operator filter priority greater than', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low'],
        'priority' => 10,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 60,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'higher'],
        'priority' => 90,
    ]);

    $response = $this->getJson('/agent/work/orders?filter[priority]=>50');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(2);
    foreach ($data as $order) {
        expect($order['priority'])->toBeGreaterThan(50);
    }
});

it('filters with operator filter created_at greater than or equal', function () {
    $uniqueType = 'test.echo.created_at.' . uniqid();
    $cutoffDate = now()->subDays(5);

    WorkOrder::create([
        'type' => $uniqueType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'old'],
        'priority' => 1,
        'created_at' => now()->subDays(10),
    ]);

    $recent = WorkOrder::create([
        'type' => $uniqueType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'recent'],
        'priority' => 1,
        'created_at' => now()->subDays(2),
    ]);

    $response = $this->getJson("/agent/work/orders?filter[type]={$uniqueType}&filter[created_at]=>=" . $cutoffDate->toIso8601String());

    $response->assertOk();
    $data = $response->json('data');

    // Should filter to only recent orders
    $ids = array_column($data, 'id');
    expect($ids)->toContain($recent->id);
    expect(count($data))->toBeGreaterThanOrEqual(1);

    // All returned orders should be recent
    foreach ($data as $order) {
        $createdAt = \Carbon\Carbon::parse($order['created_at']);
        expect($createdAt->isAfter($cutoffDate))->toBeTrue();
    }
});

it('filters with JSON meta filter using string notation', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 1,
        'meta' => ['batch_id' => 42],
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'no-match'],
        'priority' => 1,
        'meta' => ['batch_id' => 99],
    ]);

    $response = $this->getJson('/agent/work/orders?filter[meta]=batch_id:42');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['meta']['batch_id'])->toBe(42);
});

it('filters with has_available_items callback', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);
    $leaseService = app(\GregPriday\WorkManager\Services\LeaseService::class);
    $uniqueType = 'test.echo.available.' . uniqid();

    // Register a test type for this specific test
    WorkManager::registry()->register(new class($uniqueType) extends \GregPriday\WorkManager\Tests\Fixtures\OrderTypes\EchoOrderType
    {
        public function __construct(private string $typeOverride)
        {
        }

        public function type(): string
        {
            return $this->typeOverride;
        }
    });

    // Order with available items (propose already calls plan)
    $order1 = $allocator->propose($uniqueType, ['message' => 'available']);

    // Order with leased items
    $order2 = $allocator->propose($uniqueType, ['message' => 'leased']);
    $item = $order2->items()->first();
    $leaseService->acquire($item->id, 'agent-123');

    $response = $this->getJson("/agent/work/orders?filter[type]={$uniqueType}&filter[has_available_items]=true");

    $response->assertOk();
    $data = $response->json('data');

    // Should return exactly 1 order (order1 with available items)
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($order1->id);
});

it('supports include with itemsCount', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);
    $uniqueType = 'test.batch.count.' . uniqid();

    // Register a test type for this specific test
    WorkManager::registry()->register(new class($uniqueType) extends \GregPriday\WorkManager\Tests\Fixtures\OrderTypes\BatchOrderType
    {
        public function __construct(private string $typeOverride)
        {
        }

        public function type(): string
        {
            return $this->typeOverride;
        }
    });

    $order = $allocator->propose($uniqueType, ['batches' => [
        ['id' => 'a', 'data' => []],
        ['id' => 'b', 'data' => []],
        ['id' => 'c', 'data' => []],
    ]]);
    // Note: propose() already calls plan() internally, so no need to call it again

    $response = $this->getJson("/agent/work/orders?filter[id]={$order->id}&include=itemsCount");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($order->id);
    // Verify items_count attribute exists and matches the actual count
    expect($data[0])->toHaveKey('items_count');
    expect($data[0]['items_count'])->toBe(3);
});

it('supports include with events', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);

    $response = $this->getJson('/agent/work/orders?include=events');

    $response->assertOk();
    $data = $response->json('data');

    expect($data[0]['events'])->toBeArray();
    expect($data[0]['events'])->not->toBeEmpty();
});

it('supports field selection for work_orders', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/agent/work/orders?fields[work_orders]=id,type,state,priority');

    $response->assertOk();
    $data = $response->json('data');

    $order = $data[0];
    expect($order)->toHaveKeys(['id', 'type', 'state', 'priority']);
    // Should not have full payload or other fields
    expect($order)->not->toHaveKey('payload');
    expect($order)->not->toHaveKey('created_at');
});

it('supports custom sorting by priority ascending', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low'],
        'priority' => 10,
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'high'],
        'priority' => 50,
    ]);

    $response = $this->getJson('/agent/work/orders?sort=priority');

    $response->assertOk();
    $data = $response->json('data');

    expect($data[0]['priority'])->toBe(10);
    expect($data[1]['priority'])->toBe(50);
});

it('supports custom sorting by created_at descending', function () {
    $uniqueType = 'test.echo.sort.' . uniqid();

    $old = WorkOrder::create([
        'type' => $uniqueType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'old'],
        'priority' => 1,
        'created_at' => now()->subDays(5),
    ]);

    $new = WorkOrder::create([
        'type' => $uniqueType,
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'new'],
        'priority' => 1,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/agent/work/orders?filter[id]={$old->id},{$new->id}&sort=-created_at");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(2);
    // Verify descending order by created_at
    expect($data[0]['id'])->toBe($new->id);
    expect($data[1]['id'])->toBe($old->id);
});

it('supports sorting by items_count', function () {
    $allocator = app(\GregPriday\WorkManager\Services\WorkAllocator::class);
    $uniqueType = 'test.batch.sort.' . uniqid();

    // Register a test type for this specific test
    WorkManager::registry()->register(new class($uniqueType) extends \GregPriday\WorkManager\Tests\Fixtures\OrderTypes\BatchOrderType
    {
        public function __construct(private string $typeOverride)
        {
        }

        public function type(): string
        {
            return $this->typeOverride;
        }
    });

    $order1 = $allocator->propose($uniqueType, ['batches' => [
        ['id' => 'a', 'data' => []],
    ]]);

    $order2 = $allocator->propose($uniqueType, ['batches' => [
        ['id' => 'a', 'data' => []],
        ['id' => 'b', 'data' => []],
        ['id' => 'c', 'data' => []],
    ]]);

    $response = $this->getJson("/agent/work/orders?filter[id]={$order1->id},{$order2->id}&sort=-items_count&include=itemsCount");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(2);
    // Verify descending order by items_count
    expect($data[0]['id'])->toBe($order2->id);
    expect($data[0]['items_count'])->toBe(3);
    expect($data[1]['id'])->toBe($order1->id);
    expect($data[1]['items_count'])->toBe(1);
});

it('supports pagination with page[size] and page[number]', function () {
    for ($i = 0; $i < 15; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $response = $this->getJson('/agent/work/orders?page[size]=5&page[number]=2');

    $response->assertOk();
    expect($response->json('per_page'))->toBe(5);
    expect($response->json('current_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(5);
});

it('uses default page size when not specified', function () {
    for ($i = 0; $i < 60; $i++) {
        WorkOrder::create([
            'type' => 'test.echo',
            'state' => OrderState::QUEUED,
            'payload' => ['message' => "test-{$i}"],
            'priority' => 1,
        ]);
    }

    $response = $this->getJson('/agent/work/orders');

    $response->assertOk();
    expect($response->json('per_page'))->toBe(50); // default page size
    expect($response->json('data'))->toHaveCount(50);
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

    $response = $this->getJson('/agent/work/orders?page[size]=200');

    $response->assertOk();
    // Should be capped at 100
    expect($response->json('per_page'))->toBe(100);
});

it('returns 400 for invalid filter field', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/agent/work/orders?filter[invalid_field]=value');

    $response->assertStatus(400);
});

it('returns 400 for invalid sort field', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/agent/work/orders?sort=invalid_field');

    $response->assertStatus(400);
});

it('returns 400 for invalid include', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
        'priority' => 1,
    ]);

    $response = $this->getJson('/agent/work/orders?include=invalid_relation');

    $response->assertStatus(400);
});

it('combines multiple filters and sorts', function () {
    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'match'],
        'priority' => 60,
        'created_at' => now()->subDays(2),
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::IN_PROGRESS,
        'payload' => ['message' => 'no-match-state'],
        'priority' => 70,
        'created_at' => now()->subDays(1),
    ]);

    WorkOrder::create([
        'type' => 'test.batch',
        'state' => OrderState::QUEUED,
        'payload' => ['batches' => [['id' => 'a', 'data' => []]]],
        'priority' => 80,
        'created_at' => now(),
    ]);

    WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'low-priority'],
        'priority' => 40,
        'created_at' => now()->subDays(3),
    ]);

    $response = $this->getJson('/agent/work/orders?filter[state]=queued&filter[type]=test.echo&filter[priority]=>50&sort=-created_at');

    $response->assertOk();
    $data = $response->json('data');

    // Should only return queued, test.echo orders with priority > 50, sorted by created_at desc
    expect($data)->toHaveCount(1);
    expect($data[0]['payload']['message'])->toBe('match');
    expect($data[0]['priority'])->toBe(60);
});
