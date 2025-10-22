<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('agent/work', ['api']);
    config()->set('work-manager.idempotency.enforce_on', []);
    $this->actingAs(new TestUser());
});

it('returns 422 when part_key is missing', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'payload' => ['name' => 'John Doe'],
        // Missing part_key
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['part_key']);
});

it('returns 422 when payload is missing', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        // Missing payload
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('returns 422 when part_key is not a string', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 123,
        'payload' => ['name' => 'John Doe'],
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['part_key']);
});

it('returns 422 when payload is not an array', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => 'not an array',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('returns 422 when seq is not an integer', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'seq' => 'not-a-number',
        'payload' => ['name' => 'John Doe'],
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['seq']);
});

it('returns 422 when evidence is not an array', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
        'evidence' => 'not an array',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['evidence']);
});

it('returns 422 when notes is not a string', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
        'notes' => 123,
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['notes']);
});

it('accepts valid submit-part request', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'seq' => 1,
        'payload' => ['name' => 'John Doe'],
        'evidence' => ['source' => 'database'],
        'notes' => 'Initial submission',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'part' => [
                'part_key' => 'identity',
                'seq' => 1,
                'status' => 'validated',
            ],
        ]);
});

it('accepts submit-part with optional fields omitted', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/parts", [
        'part_key' => 'identity',
        'payload' => ['name' => 'John Doe'],
        // seq, evidence, notes omitted
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
