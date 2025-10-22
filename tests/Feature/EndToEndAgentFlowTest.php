<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;
use Illuminate\Support\Str;

beforeEach(function () {
    // Mount API routes for testing
    WorkManager::routes('ai/work', ['api']);
});

it('completes full agent workflow from propose to approve', function () {
    // Authenticate as test user to bypass authorization
    $this->actingAs(new TestUser());

    $idempotencyKey = 'test-key-'.Str::random(8);
    $agentId = 'agent-1';

    // Step 1: Propose work order
    $response = $this->postJson('/ai/work/propose', [
        'type' => 'test.echo',
        'payload' => ['message' => 'test workflow'],
    ], [
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'order' => ['id', 'type', 'state', 'payload'],
    ]);

    $orderId = $response->json('order.id');
    expect($response->json('order.state'))->toBe('queued');

    // Verify events were created
    $events = WorkEvent::where('order_id', $orderId)
        ->whereIn('event', ['proposed', 'planned'])
        ->get();
    expect($events->count())->toBeGreaterThanOrEqual(2);

    // Step 2: Checkout work order
    $checkoutResponse = $this->postJson("/ai/work/orders/{$orderId}/checkout", [], [
        'X-Agent-ID' => $agentId,
    ]);

    $checkoutResponse->assertStatus(200);
    $checkoutResponse->assertJsonStructure([
        'item' => ['id', 'type', 'input', 'lease_expires_at'],
    ]);

    $itemId = $checkoutResponse->json('item.id');
    expect($checkoutResponse->json('item.lease_expires_at'))->not->toBeNull();

    // Step 3: Send heartbeat
    $heartbeatResponse = $this->postJson("/ai/work/items/{$itemId}/heartbeat", [], [
        'X-Agent-ID' => $agentId,
    ]);

    $heartbeatResponse->assertStatus(200);
    expect($heartbeatResponse->json('lease_expires_at'))->not->toBeNull();

    // Step 4: Submit work item
    $submitKey = 'submit-key-'.Str::random(8);
    $submitResponse = $this->postJson("/ai/work/items/{$itemId}/submit", [
        'result' => [
            'ok' => true,
            'verified' => true,
            'echoed_message' => 'test workflow',
        ],
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => $submitKey,
    ]);

    $submitResponse->assertStatus(202);
    expect($submitResponse->json('state'))->toBe('submitted');
    expect($submitResponse->json('item.result'))->toHaveKeys(['ok', 'verified', 'echoed_message']);

    // Verify idempotency - resubmitting returns same result
    $resubmitResponse = $this->postJson("/ai/work/items/{$itemId}/submit", [
        'result' => [
            'ok' => false, // Different data
            'verified' => false,
        ],
    ], [
        'X-Agent-ID' => $agentId,
        'X-Idempotency-Key' => $submitKey, // Same key
    ]);

    $resubmitResponse->assertStatus(202);
    expect($resubmitResponse->json('item.result.ok'))->toBeTrue(); // Original result returned

    // Step 5: Approve work order
    $approveKey = 'approve-key-'.Str::random(8);
    $approveResponse = $this->postJson("/ai/work/orders/{$orderId}/approve", [], [
        'X-Idempotency-Key' => $approveKey,
    ]);

    $approveResponse->assertStatus(200);
    expect($approveResponse->json('order.state'))->toBeIn(['applied', 'completed']);
    expect($approveResponse->json('diff'))->not->toBeNull();
    expect($approveResponse->json('order.applied_at'))->not->toBeNull();

    // Verify complete audit trail
    $allEvents = WorkEvent::where('order_id', $orderId)
        ->orderBy('created_at')
        ->get();

    expect($allEvents->count())->toBeGreaterThan(4); // proposed, planned, leased, submitted, approved/applied

    // Verify chronological order of events - these events may be across both order and items
    $eventTypes = $allEvents->pluck('event')->map(fn ($event) => $event->value)->toArray();

    // At minimum, we should have these key events
    expect($eventTypes)->toContain('proposed');
    expect($eventTypes)->toContain('planned');
    expect($eventTypes)->toContain('leased');
    expect($eventTypes)->toContain('submitted');
    expect($eventTypes)->toContain('approved');
});

it('handles checkout when no items available', function () {
    // Authenticate as test user
    $this->actingAs(new TestUser());

    // Create order but don't plan it (no items)
    $order = \GregPriday\WorkManager\Models\WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'payload' => ['message' => 'test'],
    ]);

    $response = $this->postJson("/ai/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'no_items_available',
        ],
    ]);
});

it('prevents heartbeat from wrong agent', function () {
    // Authenticate as test user
    $this->actingAs(new TestUser());

    // Create and lease an item
    $order = \GregPriday\WorkManager\Models\WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
    ]);

    app(\GregPriday\WorkManager\Services\WorkAllocator::class)->plan($order);

    $checkoutResponse = $this->postJson("/ai/work/orders/{$order->id}/checkout", [], [
        'X-Agent-ID' => 'agent-1',
    ]);

    $itemId = $checkoutResponse->json('item.id');

    // Try heartbeat with different agent
    $response = $this->postJson("/ai/work/items/{$itemId}/heartbeat", [], [
        'X-Agent-ID' => 'agent-2', // Different agent
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => [
            'code' => 'lease_error',
        ],
    ]);
});
