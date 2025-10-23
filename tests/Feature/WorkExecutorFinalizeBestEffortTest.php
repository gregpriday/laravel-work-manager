<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\PartStatus;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WorkExecutorFinalizeBestEffortTest extends TestCase
{
    use RefreshDatabase;

    protected WorkExecutor $executor;

    protected LeaseService $leaseService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = app(WorkExecutor::class);
        $this->leaseService = app(LeaseService::class);
    }

    public function test_best_effort_mode_allows_partial_finalization()
    {
        // Create a work item that requires multiple parts
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'parts_required' => ['identity', 'contact', 'preferences'],
            'input' => [],
        ]);

        // Lease the item
        $agentId = 'test-agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Submit only 2 of 3 required parts
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);
        $this->executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);

        // Best-effort mode should succeed even with missing 'preferences'
        $finalized = $this->executor->finalizeItem($item->fresh(), 'best_effort');

        expect($finalized->state)->toBe(ItemState::SUBMITTED)
            ->and($finalized->result)->toHaveKeys(['identity', 'contact'])
            ->and($finalized->result)->not->toHaveKey('preferences')
            ->and($finalized->assembled_result)->toHaveKeys(['identity', 'contact']);
    }

    public function test_strict_mode_rejects_incomplete_parts()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'parts_required' => ['identity', 'contact', 'preferences'],
            'input' => [],
        ]);

        $agentId = 'test-agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Submit only 2 of 3 required parts
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);
        $this->executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);

        // Strict mode should throw exception
        try {
            $this->executor->finalizeItem($item->fresh(), 'strict');
            $this->fail('Expected ValidationException was not thrown');
        } catch (\Illuminate\Validation\ValidationException $e) {
            expect($e->errors())->toHaveKey('parts')
                ->and($e->errors()['parts'][0])->toContain('Missing required parts')
                ->and($e->errors()['parts'][0])->toContain('preferences');
        }
    }

    public function test_best_effort_mode_with_all_parts_present()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'parts_required' => ['identity', 'contact'],
            'input' => [],
        ]);

        $agentId = 'test-agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Submit all required parts
        $this->executor->submitPart($item, 'identity', null, ['name' => 'Jane Smith'], $agentId);
        $this->executor->submitPart($item, 'contact', null, ['email' => 'jane@example.com'], $agentId);

        // Best-effort mode should work fine with all parts present
        $finalized = $this->executor->finalizeItem($item->fresh(), 'best_effort');

        expect($finalized->state)->toBe(ItemState::SUBMITTED)
            ->and($finalized->result)->toHaveKeys(['identity', 'contact'])
            ->and($finalized->assembled_result)->toHaveKeys(['identity', 'contact']);
    }

    public function test_best_effort_mode_ignores_rejected_parts()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'parts_required' => ['identity', 'contact'],
            'input' => [],
        ]);

        $agentId = 'test-agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Submit one valid part and one that will be rejected
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

        // Create a rejected part directly (simulating a previous failed validation)
        $item->parts()->create([
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::REJECTED,
            'payload' => ['invalid' => 'data'],
            'errors' => ['validation' => ['email' => ['required']]],
            'submitted_by_agent_id' => $agentId,
        ]);

        // Best-effort mode should only use validated parts
        $finalized = $this->executor->finalizeItem($item->fresh(), 'best_effort');

        expect($finalized->state)->toBe(ItemState::SUBMITTED)
            ->and($finalized->result)->toHaveKey('identity')
            ->and($finalized->result)->not->toHaveKey('contact')
            ->and($finalized->assembled_result)->toHaveKey('identity');
    }

    public function test_best_effort_mode_with_no_parts_submitted()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'parts_required' => ['identity', 'contact'],
            'input' => [],
        ]);

        $agentId = 'test-agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Don't submit any parts, just try to finalize
        $finalized = $this->executor->finalizeItem($item->fresh(), 'best_effort');

        expect($finalized->state)->toBe(ItemState::SUBMITTED)
            ->and($finalized->result)->toBeArray()
            ->and($finalized->result)->toBeEmpty()
            ->and($finalized->assembled_result)->toBeArray();
    }
}
