<?php

use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\PartStatus;
use GregPriday\WorkManager\Tests\Fixtures\TestUser;

beforeEach(function () {
    WorkManager::routes('ai/work', ['api']);
    $this->actingAs(new TestUser());
});

it('filters parts by status', function () {
    $executor = app(WorkExecutor::class);
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

    // Lease the item
    $agentId = 'agent-1';
    $leaseService->acquire($item->id, $agentId);

    // Submit validated parts
    $executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);
    $executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);

    // Create a rejected part directly
    $item->parts()->create([
        'part_key' => 'preferences',
        'seq' => null,
        'status' => PartStatus::REJECTED,
        'payload' => ['invalid' => 'data'],
        'errors' => ['validation' => ['theme' => ['required']]],
        'submitted_by_agent_id' => $agentId,
    ]);

    // List all parts (no filter)
    $response = $this->getJson("/ai/work/items/{$item->id}/parts");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(3);

    // Filter by validated status
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?status=validated");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(2)
        ->and($response->json('parts.0.status'))->toBe('validated')
        ->and($response->json('parts.1.status'))->toBe('validated');

    // Filter by rejected status
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?status=rejected");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(1)
        ->and($response->json('parts.0.status'))->toBe('rejected')
        ->and($response->json('parts.0.part_key'))->toBe('preferences');
});

it('filters parts by part_key', function () {
    $executor = app(WorkExecutor::class);
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

    $agentId = 'agent-1';
    $leaseService->acquire($item->id, $agentId);

    // Submit multiple parts
    $executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);
    $executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);
    $executor->submitPart($item, 'contact', 2, ['phone' => '555-1234'], $agentId);

    // Filter by specific part_key
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?part_key=contact");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(2)
        ->and($response->json('parts.0.part_key'))->toBe('contact')
        ->and($response->json('parts.1.part_key'))->toBe('contact');

    // Filter by another part_key
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?part_key=identity");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(1)
        ->and($response->json('parts.0.part_key'))->toBe('identity');
});

it('combines status and part_key filters', function () {
    $executor = app(WorkExecutor::class);
    $leaseService = app(LeaseService::class);

    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

    $agentId = 'agent-1';
    $leaseService->acquire($item->id, $agentId);

    // Submit validated contact part
    $executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);

    // Create rejected contact part
    $item->parts()->create([
        'part_key' => 'contact',
        'seq' => 2,
        'status' => PartStatus::REJECTED,
        'payload' => ['invalid' => 'data'],
        'errors' => ['validation' => ['phone' => ['required']]],
        'submitted_by_agent_id' => $agentId,
    ]);

    // Submit validated identity part
    $executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

    // Filter by both status and part_key
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?part_key=contact&status=validated");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(1)
        ->and($response->json('parts.0.part_key'))->toBe('contact')
        ->and($response->json('parts.0.status'))->toBe('validated');

    // Filter for rejected contact parts
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?part_key=contact&status=rejected");
    $response->assertStatus(200);
    expect($response->json('parts'))->toHaveCount(1)
        ->and($response->json('parts.0.part_key'))->toBe('contact')
        ->and($response->json('parts.0.status'))->toBe('rejected');
});

it('returns empty array when no parts match filter', function () {
    $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.partial',
        'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

    // No parts submitted, filter should return empty
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?status=validated");
    $response->assertStatus(200);
    expect($response->json('parts'))->toBeArray()->toBeEmpty();

    // Non-existent part_key
    $response = $this->getJson("/ai/work/items/{$item->id}/parts?part_key=nonexistent");
    $response->assertStatus(200);
    expect($response->json('parts'))->toBeArray()->toBeEmpty();
});
