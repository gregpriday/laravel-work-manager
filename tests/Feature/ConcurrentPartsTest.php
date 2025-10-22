<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkItemPart;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\PartStatus;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ConcurrentPartsTest extends TestCase
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

    public function test_duplicate_part_submission_updates_existing_record()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // First submission
        $part1 = $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

        // Second submission with same part_key and seq should update, not create new
        $part2 = $this->executor->submitPart($item, 'identity', null, ['name' => 'Jane Smith'], $agentId);

        // Should be same part ID (updated)
        expect($part2->id)->toBe($part1->id)
            ->and($part2->payload)->toBe(['name' => 'Jane Smith'])
            ->and($part2->payload)->not->toBe($part1->payload);

        // Only one part should exist
        expect($item->fresh()->parts()->count())->toBe(1);
    }

    public function test_different_seq_creates_separate_parts()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // Submit with seq=1
        $part1 = $this->executor->submitPart($item, 'contact', 1, ['email' => 'john@example.com'], $agentId);

        // Submit with seq=2
        $part2 = $this->executor->submitPart($item, 'contact', 2, ['phone' => '555-1234'], $agentId);

        // Should be different parts
        expect($part2->id)->not->toBe($part1->id)
            ->and($item->fresh()->parts()->count())->toBe(2);
    }

    public function test_null_seq_and_explicit_seq_are_different()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // Submit with seq=null
        $part1 = $this->executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);

        // Submit with explicit seq=1
        $part2 = $this->executor->submitPart($item, 'contact', 1, ['phone' => '555-1234'], $agentId);

        // Should be different parts
        expect($part2->id)->not->toBe($part1->id)
            ->and($item->fresh()->parts()->count())->toBe(2);
    }

    public function test_concurrent_submissions_with_updateOrCreate_handles_race_condition()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // Simulate concurrent submissions by directly using updateOrCreate
        // This tests the database constraint handling
        $part1 = WorkItemPart::updateOrCreate(
            [
                'work_item_id' => $item->id,
                'part_key' => 'identity',
                'seq' => null,
            ],
            [
                'status' => PartStatus::VALIDATED,
                'payload' => ['name' => 'Concurrent 1'],
                'submitted_by_agent_id' => $agentId,
            ]
        );

        $part2 = WorkItemPart::updateOrCreate(
            [
                'work_item_id' => $item->id,
                'part_key' => 'identity',
                'seq' => null,
            ],
            [
                'status' => PartStatus::VALIDATED,
                'payload' => ['name' => 'Concurrent 2'],
                'submitted_by_agent_id' => $agentId,
            ]
        );

        // Should be same part (updated)
        expect($part2->id)->toBe($part1->id)
            ->and($item->fresh()->parts()->count())->toBe(1);
    }

    public function test_rejected_part_can_be_resubmitted_and_becomes_validated()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // Create a rejected part
        $rejectedPart = WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'identity',
            'seq' => null,
            'status' => PartStatus::REJECTED,
            'payload' => ['invalid' => 'data'],
            'errors' => ['validation' => ['name' => ['required']]],
            'submitted_by_agent_id' => $agentId,
        ]);

        // Resubmit with valid data
        $validPart = $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

        // Should update the same record to VALIDATED status
        expect($validPart->id)->toBe($rejectedPart->id)
            ->and($validPart->status)->toBe(PartStatus::VALIDATED)
            ->and($validPart->errors)->toBeNull()
            ->and($item->fresh()->parts()->count())->toBe(1);
    }

    public function test_parts_for_different_items_dont_conflict()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);

        $item1 = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $item2 = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item1->id, $agentId);

        $agentId2 = 'agent-2';
        $this->leaseService->acquire($item2->id, $agentId2);

        // Submit same part_key and seq for different items
        $part1 = $this->executor->submitPart($item1, 'identity', null, ['name' => 'John Doe'], $agentId);
        $part2 = $this->executor->submitPart($item2, 'identity', null, ['name' => 'Jane Smith'], $agentId2);

        // Should be different parts
        expect($part2->id)->not->toBe($part1->id)
            ->and($part1->work_item_id)->toBe($item1->id)
            ->and($part2->work_item_id)->toBe($item2->id);
    }

    public function test_parts_state_tracks_latest_submission_status()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // First submission
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

        $item->refresh();
        expect($item->parts_state)->toHaveKey('identity')
            ->and($item->parts_state['identity']['status'])->toBe('validated');

        // Update submission
        $this->executor->submitPart($item, 'identity', null, ['name' => 'Jane Smith'], $agentId);

        $item->refresh();
        expect($item->parts_state['identity']['status'])->toBe('validated');
    }

    public function test_database_constraint_prevents_exact_duplicates()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Try to create exact duplicate parts directly (bypassing updateOrCreate)
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'test',
            'seq' => 1,
            'status' => PartStatus::VALIDATED,
            'payload' => ['data' => 'first'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Second create should fail due to unique constraint
        try {
            WorkItemPart::create([
                'work_item_id' => $item->id,
                'part_key' => 'test',
                'seq' => 1,
                'status' => PartStatus::VALIDATED,
                'payload' => ['data' => 'second'],
                'submitted_by_agent_id' => 'agent-1',
            ]);

            $this->fail('Expected unique constraint violation');
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected - unique constraint violation
            expect($e->getCode())->toBeIn(['23000', '23505']); // SQLite: 23000, PostgreSQL: 23505
        }
    }

    public function test_concurrent_part_submissions_maintain_data_integrity()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);

        // Submit multiple parts with different keys
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John'], $agentId);
        $this->executor->submitPart($item, 'contact', null, ['email' => 'john@example.com'], $agentId);
        $this->executor->submitPart($item, 'preferences', null, ['theme' => 'dark'], $agentId);

        // Update one part
        $this->executor->submitPart($item, 'identity', null, ['name' => 'John Doe'], $agentId);

        $item->refresh();

        // Should have exactly 3 parts
        expect($item->parts()->count())->toBe(3);

        // parts_state should have all 3 keys
        expect($item->parts_state)->toHaveKeys(['identity', 'contact', 'preferences']);

        // Identity should have updated value
        $identityPart = $item->parts()->where('part_key', 'identity')->first();
        expect($identityPart->payload)->toBe(['name' => 'John Doe']);
    }
}
