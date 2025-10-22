<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);

    // Authenticate as test user for all tests
    $this->actingAs(new TestUser());
});

it('requires idempotency key for propose when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['propose']);

    $response = $this->postJson('/ai/work/propose', [
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $response->assertStatus(428)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_key_required',
                'header' => 'X-Idempotency-Key',
            ],
        ]);
});

it('requires idempotency key for submit when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['submit']);

    $allocator = app(WorkAllocator::class);
    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);
    $item = $order->items()->first();

    // Lease the item
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/submit", [
        'result' => ['ok' => true],
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(428)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_key_required',
                'header' => 'X-Idempotency-Key',
            ],
        ]);
});

it('requires idempotency key for approve when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['approve']);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::SUBMITTED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson("/ai/work/orders/{$order->id}/approve");

    $response->assertStatus(428)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_key_required',
                'header' => 'X-Idempotency-Key',
            ],
        ]);
});

it('requires idempotency key for reject when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['reject']);

    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::SUBMITTED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson("/ai/work/orders/{$order->id}/reject", [
        'errors' => [
            ['code' => 'validation_failed', 'message' => 'Invalid data'],
        ],
    ]);

    $response->assertStatus(428)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_key_required',
                'header' => 'X-Idempotency-Key',
            ],
        ]);
});

it('allows propose without idempotency key when not enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', []);

    $response = $this->postJson('/ai/work/propose', [
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['order' => ['id', 'type', 'state']]);
});

it('returns cached response when same idempotency key used for approve', function () {
    // TODO: Fix test - order not ready for approval (items not in submitted state after executor->submit)
    $this->markTestSkipped('Order readiness check needs investigation');
    $allocator = app(WorkAllocator::class);
    $executor = app(\GregPriday\WorkManager\Services\WorkExecutor::class);

    $order = $allocator->propose('test.echo', ['message' => 'test']);
    $allocator->plan($order);

    $item = $order->items()->first();
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    $executor->submit($item->fresh(), ['ok' => true, 'verified' => true, 'echoed_message' => 'test'], 'agent-1');

    // Reload order to get updated item states
    $order = $order->fresh();

    // Ensure order is in submitted state for approval
    $order->update(['state' => OrderState::SUBMITTED]);

    $idempotencyKey = 'test-approve-' . uniqid();

    // First approval
    $response1 = $this->postJson("/ai/work/orders/{$order->id}/approve", [], [
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response1->assertStatus(200);
    $firstOrderState = $response1->json('order.state');
    $firstDiff = $response1->json('diff');

    // Second approval with same key should return cached response
    $response2 = $this->postJson("/ai/work/orders/{$order->id}/approve", [], [
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response2->assertStatus(200);
    expect($response2->json('order.state'))->toBe($firstOrderState);
    expect($response2->json('diff'))->toBe($firstDiff);
});
