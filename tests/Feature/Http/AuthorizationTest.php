<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    WorkManager::routes('agent/work', ['api']);

    // Clear the permissive gate from TestCase for these authorization tests
    Gate::before(fn () => null);

    // Set up default deny policy for WorkOrder actions
    Gate::policy(\GregPriday\WorkManager\Models\WorkOrder::class, \GregPriday\WorkManager\Policies\WorkOrderPolicy::class);
});

it('blocks propose without authorization', function () {
    // No authenticated user, policy should deny
    $response = $this->postJson('/agent/work/propose', [
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    $response->assertStatus(403);
});

it('blocks approve without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::SUBMITTED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson("/agent/work/orders/{$order->id}/approve");

    $response->assertStatus(403);
});

it('blocks reject without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::SUBMITTED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson("/agent/work/orders/{$order->id}/reject", [
        'errors' => [
            ['code' => 'validation_failed', 'message' => 'Invalid data'],
        ],
    ]);

    $response->assertStatus(403);
});

it('blocks checkout without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    app(\GregPriday\WorkManager\Services\WorkAllocator::class)->plan($order);

    $response = $this->postJson("/agent/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(403);
});

it('blocks submit without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    app(\GregPriday\WorkManager\Services\WorkAllocator::class)->plan($order);
    $item = $order->items()->first();

    $item->update([
        'state' => \GregPriday\WorkManager\Support\ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson("/agent/work/items/{$item->id}/submit", [
        'result' => ['ok' => true],
    ], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(403);
});

it('allows authorized actions when user has permission', function () {
    // Override gate to allow all for this specific test
    Gate::before(fn () => true);

    // Authenticate as test user
    $this->actingAs(new TestUser());

    $response = $this->postJson('/agent/work/propose', [
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ], [
        'X-Idempotency-Key' => 'test-key-'.uniqid(),
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['order' => ['id', 'type', 'state']]);
});

it('blocks view without authorization', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->getJson("/agent/work/orders/{$order->id}");

    $response->assertStatus(403);
});
