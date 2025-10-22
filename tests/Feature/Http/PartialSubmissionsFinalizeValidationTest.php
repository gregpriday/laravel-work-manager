<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
    $this->actingAs(new TestUser());
});

it('returns 422 for invalid finalize mode parameter', function () {
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
        'mode' => 'invalid_mode',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);
});

it('returns 422 for non-string finalize mode parameter', function () {
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
        'mode' => 123,
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);
});

it('accepts strict mode for finalize', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
        'parts_required' => [],
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'strict',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
        ]);
});

it('accepts best_effort mode for finalize', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
        'parts_required' => [],
            'input' => [],
        ]);

    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [
        'mode' => 'best_effort',
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
        ]);
});

it('defaults to strict mode when mode parameter is omitted', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
        'parts_required' => ['identity'],
            'input' => [],
        ]);

    // Should fail in strict mode because required part is missing
    $response = $this->postJson("/ai/work/items/{$item->id}/finalize", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['parts']);
});
