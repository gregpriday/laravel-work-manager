<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
    $this->actingAs(new TestUser());
});

it('requires idempotency key for submit-part when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['submit-part']);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
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

it('requires idempotency key for finalize when enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', ['finalize']);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'strict',
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

it('allows submit-part with idempotency key', function () {
    config()->set('work-manager.idempotency.enforce_on', ['submit-part']);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
    ], [
        'X-Agent-ID' => 'agent-1',
        'X-Idempotency-Key' => 'test-submit-part-' . uniqid(),
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'part' => [
                'part_key' => 'identity',
                'status' => 'validated',
            ],
        ]);
});

it('allows finalize with idempotency key', function () {
    config()->set('work-manager.idempotency.enforce_on', ['finalize']);

    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    // Lease the item properly
    $leaseService->acquire($item->id, 'agent-1');

    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best-effort',
    ], [
        'X-Agent-ID' => 'agent-1',
        'X-Idempotency-Key' => 'test-finalize-' . uniqid(),
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'item' => [
                'id' => $item->id,
                'state' => 'submitted',
            ],
        ]);
});

it('allows submit-part without idempotency key when not enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', []);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'part' => [
                'part_key' => 'identity',
                'status' => 'validated',
            ],
        ]);
});

it('allows finalize without idempotency key when not enforced', function () {
    config()->set('work-manager.idempotency.enforce_on', []);

    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'parts_required' => [],
            'input' => [],
        ]);

    // Lease the item properly
    $leaseService->acquire($item->id, 'agent-1');

    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best-effort',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'item' => [
                'id' => $item->id,
                'state' => 'submitted',
            ],
        ]);
});
