<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
    config()->set('work-manager.idempotency.enforce_on', []);

    // Clear the permissive gate from TestCase for these authorization tests
    Gate::before(fn () => null);

    // Set up default deny policy for WorkOrder actions
    Gate::policy(\GregPriday\WorkManager\Models\WorkOrder::class, \GregPriday\WorkManager\Policies\WorkOrderPolicy::class);
});

it('blocks submit-part without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

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

    $response->assertStatus(403);
});

it('blocks list-parts without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
            'input' => [],
        ]);

    $response = $this->getJson("/ai/work/items/{$item->id}/parts");

    $response->assertStatus(403);
});

it('blocks finalize without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

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

    $response->assertStatus(403);
});

it('allows submit-part with proper authorization', function () {
    // Authenticate as test user (TestUser has permissive policy in TestCase)
    $this->actingAs(new \GregPriday\WorkManager\Tests\Fixtures\TestUser());

    // Define permissive policy just for this test
    Gate::define('submit', fn ($user, $order) => true);

    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

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
        ]);
});

it('allows list-parts with proper authorization', function () {
    $this->actingAs(new \GregPriday\WorkManager\Tests\Fixtures\TestUser());

    // Define permissive policy
    Gate::define('view', fn ($user, $order) => true);

    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

    $response = $this->getJson("/ai/work/items/{$item->id}/parts");

    $response->assertStatus(200)
        ->assertJsonStructure(['parts']);
});

it('allows finalize with proper authorization', function () {
    $this->actingAs(new \GregPriday\WorkManager\Tests\Fixtures\TestUser());

    // Define permissive policy
    Gate::define('submit', fn ($user, $order) => true);

    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

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

it('respects authorization on submit-part even with valid lease', function () {
    // No authorization despite valid lease
    $order = WorkOrder::create([
        'type' => 'test.partial',
        'state' => OrderState::QUEUED,
        'payload' => ['data' => 'test'],
    ]);

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

    // Should still be blocked despite valid lease
    $response->assertStatus(403);
});
