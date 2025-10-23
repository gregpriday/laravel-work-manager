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
    $this->actingAs(new TestUser);
});

it('returns 409 when no items available (all items already completed)', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);

    // Mark all items as completed so none are available
    foreach ($order->items as $item) {
        $item->update(['state' => ItemState::COMPLETED]);
    }

    // Try to checkout - should fail with no items available
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('no_items_available');
});

it('returns 409 when heartbeat from wrong agent', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Agent 1 checks out
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    $itemId = $response->json('item.id');

    // Agent 2 tries to heartbeat
    $heartbeatResponse = $this->postJson("/agent/work/items/{$itemId}/heartbeat", [], [
        'X-Agent-ID' => 'agent-2',
    ]);

    $heartbeatResponse->assertStatus(409);
    $heartbeatResponse->assertJson([
        'error' => [
            'code' => 'lease_error',
        ],
    ]);
});

it('returns 409 when release from wrong agent', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Agent 1 checks out
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    $itemId = $response->json('item.id');

    // Agent 2 tries to release
    $releaseResponse = $this->postJson("/agent/work/items/{$itemId}/release", [], [
        'X-Agent-ID' => 'agent-2',
    ]);

    $releaseResponse->assertStatus(409);
    $releaseResponse->assertJson([
        'error' => [
            'code' => 'lease_error',
        ],
    ]);
});

it('allows same agent to heartbeat successfully', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Agent 1 checks out
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(200);
    $itemId = $response->json('item.id');

    // Same agent heartbeats successfully
    $heartbeatResponse = $this->postJson("/agent/work/items/{$itemId}/heartbeat", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $heartbeatResponse->assertStatus(200);
    $heartbeatResponse->assertJsonStructure(['lease_expires_at']);
});

it('returns no_items_available when order has no planned items', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    // Don't plan the order - no items available

    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'no_items_available',
            'message' => 'No work items available for checkout',
        ],
    ]);
});

it('returns no_items_available when all items already leased', function () {
    $allocator = app(WorkAllocator::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    // Manually lease all items
    foreach ($order->items as $item) {
        $item->update([
            'state' => ItemState::IN_PROGRESS,
            'leased_by_agent_id' => 'agent-1',
            'lease_expires_at' => now()->addMinutes(10),
        ]);
    }

    // Try to checkout
    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-2',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'no_items_available',
        ],
    ]);
});
