<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('agent/work', ['api']);

    // Authenticate as test user for all tests
    $this->actingAs(new TestUser());
});

it('checks out highest priority item across all orders', function () {
    $allocator = app(WorkAllocator::class);

    // Create orders with different priorities
    $lowPriority = $allocator->propose('test.echo', ['message' => 'low'], priority: 10);
    $highPriority = $allocator->propose('test.echo', ['message' => 'high'], priority: 100);
    $mediumPriority = $allocator->propose('test.echo', ['message' => 'medium'], priority: 50);

    $allocator->plan($lowPriority);
    $allocator->plan($highPriority);
    $allocator->plan($mediumPriority);

    // Global checkout should return highest priority item
    $response = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.message'))->toBe('high');
});

it('uses FIFO ordering within same priority', function () {
    $allocator = app(WorkAllocator::class);

    // Create three orders with same priority but different timestamps
    $first = $allocator->propose('test.echo', ['message' => 'first'], priority: 50);
    sleep(1);
    $second = $allocator->propose('test.echo', ['message' => 'second'], priority: 50);
    sleep(1);
    $third = $allocator->propose('test.echo', ['message' => 'third'], priority: 50);

    $allocator->plan($first);
    $allocator->plan($second);
    $allocator->plan($third);

    // Should get oldest item (FIFO) within same priority
    $response = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.message'))->toBe('first');
});

it('filters by order type', function () {
    $allocator = app(WorkAllocator::class);

    // Create orders with different types
    $echoOrder = $allocator->propose('test.echo', ['message' => 'echo'], priority: 100);
    $batchOrder = $allocator->propose('test.batch', ['batches' => [['id' => 'batch-1', 'data' => ['message' => 'batch']]]], priority: 50);

    $allocator->plan($echoOrder);
    $allocator->plan($batchOrder);

    // Filter by type - should only get batch order
    $response = $this->postJson('/agent/work/checkout?type=test.batch', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.data.message'))->toBe('batch');
    expect($response->json('item.type'))->toBe('test.batch');
});

it('filters by minimum priority', function () {
    $allocator = app(WorkAllocator::class);

    // Create orders with different priorities
    $low = $allocator->propose('test.echo', ['message' => 'low'], priority: 10);
    $medium = $allocator->propose('test.echo', ['message' => 'medium'], priority: 50);
    $high = $allocator->propose('test.echo', ['message' => 'high'], priority: 100);

    $allocator->plan($low);
    $allocator->plan($medium);
    $allocator->plan($high);

    // Filter by min_priority >= 50 - should skip low priority order
    $response = $this->postJson('/agent/work/checkout?min_priority=50', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.message'))->toBe('high'); // Highest among filtered
});

it('filters by tenant_id in payload', function () {
    $allocator = app(WorkAllocator::class);

    // Create orders with different tenant IDs in payload
    $tenant1Order = $allocator->propose('test.echo', ['tenant_id' => 'tenant-1', 'message' => 'one'], priority: 100);
    $tenant2Order = $allocator->propose('test.echo', ['tenant_id' => 'tenant-2', 'message' => 'two'], priority: 50);

    $allocator->plan($tenant1Order);
    $allocator->plan($tenant2Order);

    // Filter by tenant_id
    $response = $this->postJson('/agent/work/checkout?tenant_id=tenant-2', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.message'))->toBe('two');
});

it('combines multiple filters', function () {
    $allocator = app(WorkAllocator::class);

    // Create various orders
    $match = $allocator->propose('test.batch', [
        'tenant_id' => 'acme',
        'batches' => [['id' => 'match-1', 'data' => ['message' => 'match']]],
    ], priority: 80);
    $wrongType = $allocator->propose('test.echo', ['tenant_id' => 'acme', 'message' => 'wrong-type'], priority: 90);
    $wrongTenant = $allocator->propose('test.batch', [
        'tenant_id' => 'other',
        'batches' => [['id' => 'wrong-1', 'data' => ['message' => 'wrong-tenant']]],
    ], priority: 85);
    $lowPriority = $allocator->propose('test.batch', [
        'tenant_id' => 'acme',
        'batches' => [['id' => 'low-1', 'data' => ['message' => 'low']]],
    ], priority: 30);

    $allocator->plan($match);
    $allocator->plan($wrongType);
    $allocator->plan($wrongTenant);
    $allocator->plan($lowPriority);

    // Filter: type=test.batch, min_priority=50, tenant_id=acme
    $response = $this->postJson('/agent/work/checkout?type=test.batch&min_priority=50&tenant_id=acme', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.data.message'))->toBe('match');
});

it('returns 409 when no items match filters', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test'], priority: 10);
    $allocator->plan($order);

    // Filter that doesn't match anything
    $response = $this->postJson('/agent/work/checkout?type=nonexistent.type', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'no_items_available',
            'message' => 'No work items available matching filters',
        ],
    ]);
});

it('returns 409 when no items available at all', function () {
    // No orders created

    $response = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'no_items_available',
        ],
    ]);
});

it('respects per-agent concurrency limit', function () {
    config(['work-manager.lease.max_leases_per_agent' => 2]);

    $allocator = app(WorkAllocator::class);

    // Create 3 orders
    for ($i = 1; $i <= 3; $i++) {
        $order = $allocator->propose('test.echo', ['message' => "item-$i"]);
        $allocator->plan($order);
    }

    // Agent 1 checks out twice (reaches limit)
    $response1 = $this->postJson('/agent/work/checkout', [], ['X-Agent-ID' => 'agent-1']);
    $response1->assertStatus(200);

    $response2 = $this->postJson('/agent/work/checkout', [], ['X-Agent-ID' => 'agent-1']);
    $response2->assertStatus(200);

    // Third checkout should fail due to limit
    $response3 = $this->postJson('/agent/work/checkout', [], ['X-Agent-ID' => 'agent-1']);
    $response3->assertStatus(409);
    expect($response3->json('error.code'))->toBe('no_items_available');

    // Different agent should still be able to checkout
    $response4 = $this->postJson('/agent/work/checkout', [], ['X-Agent-ID' => 'agent-2']);
    $response4->assertStatus(200);
});

it('respects per-type concurrency limit', function () {
    config(['work-manager.lease.max_leases_per_type' => 1]);

    $allocator = app(WorkAllocator::class);

    // Create 2 orders of same type
    $order1 = $allocator->propose('test.echo', ['message' => 'one']);
    $order2 = $allocator->propose('test.echo', ['message' => 'two']);
    $allocator->plan($order1);
    $allocator->plan($order2);

    // First checkout succeeds
    $response1 = $this->postJson('/agent/work/checkout?type=test.echo', [], ['X-Agent-ID' => 'agent-1']);
    $response1->assertStatus(200);

    // Second checkout for same type should fail
    $response2 = $this->postJson('/agent/work/checkout?type=test.echo', [], ['X-Agent-ID' => 'agent-2']);
    $response2->assertStatus(409);
    expect($response2->json('error.code'))->toBe('no_items_available');
});

it('maintains backward compatibility with scoped checkout', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Old scoped checkout endpoint should still work
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    expect($response->json('item.input.message'))->toBe('test');
});

it('prioritizes highest priority items first', function () {
    $allocator = app(WorkAllocator::class);

    // Create three orders with different priorities
    $order1 = $allocator->propose('test.echo', ['message' => 'high'], priority: 100);
    $allocator->plan($order1);

    $order2 = $allocator->propose('test.echo', ['message' => 'medium'], priority: 75);
    $allocator->plan($order2);

    $order3 = $allocator->propose('test.echo', ['message' => 'low'], priority: 50);
    $allocator->plan($order3);

    // First checkout should get the highest priority item
    $firstCheckout = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $firstCheckout->assertStatus(200);
    expect($firstCheckout->json('item.input.message'))->toBe('high');

    // Verify the item was leased
    $item = $order1->items()->first()->fresh();
    expect($item->state->value)->toBe(ItemState::LEASED->value);
    expect($item->leased_by_agent_id)->toBe('agent-1');
});

it('returns 409 when no items available globally', function () {
    // No orders exist

    $response = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
});

it('includes lease and heartbeat information in response', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $response = $this->postJson('/agent/work/checkout', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'item' => [
            'id',
            'type',
            'input',
            'lease_expires_at',
            'heartbeat_every_seconds',
        ],
    ]);

    expect($response->json('item.heartbeat_every_seconds'))
        ->toBe(config('work-manager.lease.heartbeat_every_seconds'));
});

it('validates query parameters', function () {
    // Min priority should be integer
    $response = $this->postJson('/agent/work/checkout?min_priority=invalid', [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422);
});
