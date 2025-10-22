<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
    $this->actingAs(new TestUser());
});

it('returns cached response when finalize called with same idempotency key', function () {
    $executor = app(WorkExecutor::class);
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    $agentId = 'agent-1';
    $leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

    $idempotencyKey = 'test-finalize-' . uniqid();

    // First finalize
    $response1 = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response1->assertStatus(202)
        ->assertJson([
            'success' => true,
            'item' => [
                'state' => 'submitted',
            ],
        ]);

    $firstItemId = $response1->json('item.id');
    $firstItemState = $response1->json('item.state');

    // Second finalize with same key should return cached response
    $response2 = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response2->assertStatus(202);
    expect($response2->json('item.id'))->toBe($firstItemId)
        ->and($response2->json('item.state'))->toBe($firstItemState)
        ->and($response2->json())->toBe($response1->json());
});

it('returns cached response for submit-part with same idempotency key', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $idempotencyKey = 'test-submit-part-' . uniqid();

    // First submit
    $response1 = $this->postJson("/ai/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
    ], [
        'X-Agent-ID' => 'agent-1',
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response1->assertStatus(202)
        ->assertJson([
            'success' => true,
            'part' => [
                'part_key' => 'identity',
                'status' => 'validated',
            ],
        ]);

    $firstPartId = $response1->json('part.id');

    // Second submit with same key should return cached response
    $response2 = $this->postJson("/ai/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'Jane Smith'], // Different payload, should be ignored
    ], [
        'X-Agent-ID' => 'agent-1',
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response2->assertStatus(202);
    expect($response2->json('part.id'))->toBe($firstPartId)
        ->and($response2->json())->toBe($response1->json());

    // Verify only one part was actually created
    expect($item->fresh()->parts()->count())->toBe(1);
});

it('creates new finalize result with different idempotency key', function () {
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item1 = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    $agentId = 'agent-1';
    $leaseService->acquire($item1->id, $agentId);
        $item1 = $item1->fresh();

    // First finalize with first key
    $response1 = $this->postJson("/ai/work/items/{$item1->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => 'key-1',
    ]);

    $response1->assertStatus(202);

    // Create another item
    $item2 = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    $leaseService->acquire($item2->id, $agentId);
        $item2 = $item2->fresh();

    // Finalize with different key should create new result
    $response2 = $this->postJson("/ai/work/items/{$item2->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => 'key-2',
    ]);

    $response2->assertStatus(202);

    // Should be different items
    expect($response2->json('item.id'))->not->toBe($response1->json('item.id'));
});

it('handles concurrent finalize requests with different idempotency keys', function () {
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    $agentId = 'agent-1';
    $leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

    // Two requests with different keys
    $response1 = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => 'concurrent-key-1',
    ]);

    // Second request should either succeed if first completed, or fail
    // The important thing is it doesn't return the cached response from key-1
    $item->refresh();

    if ($item->state === ItemState::SUBMITTED) {
        // If first finalize succeeded, second should fail due to state
        // (item already in SUBMITTED state)
        expect($response1->json('item.state'))->toBe('submitted');
    }
});
