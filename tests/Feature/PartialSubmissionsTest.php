<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Events\WorkItemFinalized;
use GregPriday\WorkManager\Events\WorkItemPartSubmitted;
use GregPriday\WorkManager\Events\WorkItemPartValidated;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkItemPart;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\Diff;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\PartStatus;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class PartialSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected WorkAllocator $allocator;
    protected WorkExecutor $executor;
    protected LeaseService $leaseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(WorkAllocator::class);
        $this->executor = app(WorkExecutor::class);
        $this->leaseService = app(LeaseService::class);

        // Register test order type
        app('work-manager')->registry()->register(new TestPartialOrderType());
    }

    public function test_can_submit_part_for_work_item()
    {
        Event::fake([WorkItemPartSubmitted::class, WorkItemPartValidated::class]);

        // Create and lease a work item
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit a part
        $part = $this->executor->submitPart(
            item: $item,
            partKey: 'identity',
            seq: null,
            payload: [
                'name' => 'Acme Corporation',
                'domain' => 'acme.com',
            ],
            agentId: 'agent-1'
        );

        $this->assertInstanceOf(WorkItemPart::class, $part);
        $this->assertEquals('identity', $part->part_key);
        $this->assertEquals(PartStatus::VALIDATED, $part->status);
        $this->assertEquals('Acme Corporation', $part->payload['name']);

        Event::assertDispatched(WorkItemPartSubmitted::class);
        Event::assertDispatched(WorkItemPartValidated::class);

        // Check parts_state was updated
        $item->refresh();
        $this->assertNotEmpty($item->parts_state);
        $this->assertEquals('validated', $item->parts_state['identity']['status']);
    }

    public function test_part_validation_failure_stores_errors()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Try to submit invalid data
        try {
            $this->executor->submitPart(
                item: $item,
                partKey: 'identity',
                seq: null,
                payload: ['domain' => 'acme.com'], // missing required 'name'
                agentId: 'agent-1'
            );

            $this->fail('Should have thrown ValidationException');
        } catch (ValidationException $e) {
            // Check that part was created with rejected status
            $part = $item->parts()->where('part_key', 'identity')->first();
            $this->assertNotNull($part);
            $this->assertEquals(PartStatus::REJECTED, $part->status);
            $this->assertNotEmpty($part->errors);
        }
    }

    public function test_can_finalize_work_item_with_all_required_parts()
    {
        Event::fake([WorkItemFinalized::class]);

        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit all required parts
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        $this->executor->submitPart($item, 'contacts', null, [
            'contacts' => [
                ['name' => 'John Doe', 'email' => 'john@acme.com'],
            ],
        ], 'agent-1');

        // Finalize the item
        $item = $this->executor->finalizeItem($item, 'strict');

        $this->assertEquals(ItemState::SUBMITTED, $item->state);
        $this->assertNotEmpty($item->assembled_result);
        $this->assertEquals('Acme Corporation', $item->assembled_result['identity']['name']);
        $this->assertCount(1, $item->assembled_result['contacts']['contacts']);

        Event::assertDispatched(WorkItemFinalized::class);
    }

    public function test_finalize_fails_when_required_parts_missing_in_strict_mode()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit only one of two required parts
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        // Try to finalize without all required parts
        try {
            $this->executor->finalizeItem($item, 'strict');
            $this->fail('Should have thrown ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Missing required parts', $e->getMessage());
        }
    }

    public function test_can_update_part_by_resubmitting_same_key()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit initial version
        $part1 = $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        // Update with new version
        $part2 = $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation Inc.',
            'domain' => 'acme.com',
        ], 'agent-1');

        // Should be the same part ID (updateOrCreate)
        $this->assertEquals($part1->id, $part2->id);
        $this->assertEquals('Acme Corporation Inc.', $part2->payload['name']);

        // Latest part should have the new data
        $latestPart = $item->getLatestPart('identity');
        $this->assertEquals('Acme Corporation Inc.', $latestPart->payload['name']);
    }

    public function test_get_latest_parts_returns_one_per_key()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit multiple parts
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        $this->executor->submitPart($item, 'contacts', null, [
            'contacts' => [['name' => 'John Doe']],
        ], 'agent-1');

        // Update identity
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corp Updated',
            'domain' => 'acme.com',
        ], 'agent-1');

        // Get latest parts
        $latestParts = $item->getLatestParts();

        $this->assertCount(2, $latestParts);
        $identityPart = $latestParts->firstWhere('part_key', 'identity');
        $this->assertEquals('Acme Corp Updated', $identityPart->payload['name']);
    }

    public function test_only_leaseholder_can_submit_parts()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Try to submit as different agent
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not leased by this agent');

        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-2');
    }

    public function test_assembled_result_is_used_for_approval()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit required parts
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        $this->executor->submitPart($item, 'contacts', null, [
            'contacts' => [['name' => 'John Doe']],
        ], 'agent-1');

        // Finalize
        $item = $this->executor->finalizeItem($item);

        // Order should now be in submitted state
        $order->refresh();
        $this->assertTrue(in_array($order->state->value, ['queued', 'submitted']));

        // Assembled result should equal item result
        $this->assertEquals($item->assembled_result, $item->result);
    }

    public function test_parts_state_tracks_latest_status()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit a part
        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        $item->refresh();

        $this->assertArrayHasKey('identity', $item->parts_state);
        $this->assertEquals('validated', $item->parts_state['identity']['status']);
        $this->assertNotEmpty($item->parts_state['identity']['checksum']);
        $this->assertNotEmpty($item->parts_state['identity']['submitted_at']);
    }

    public function test_expired_lease_prevents_part_submission()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Expire the lease
        $item->lease_expires_at = now()->subMinutes(10);
        $item->save();

        $this->expectException(\GregPriday\WorkManager\Exceptions\LeaseExpiredException::class);

        $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
        ], 'agent-1');
    }

    public function test_checksum_is_deterministic()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        $payload = ['name' => 'Acme Corporation', 'domain' => 'acme.com'];

        // Submit twice with same payload
        $part1 = $this->executor->submitPart($item, 'identity', null, $payload, 'agent-1');

        // Delete and resubmit
        $part1->delete();

        $part2 = $this->executor->submitPart($item, 'identity', null, $payload, 'agent-1');

        // Checksums should match
        $this->assertEquals($part1->checksum, $part2->checksum);
    }

    public function test_seq_boundary_values()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Test with seq = 0
        $part1 = $this->executor->submitPart($item, 'contacts', 0, [
            'contacts' => [['name' => 'John Doe']],
        ], 'agent-1');

        $this->assertEquals(0, $part1->seq);

        // Test with large seq
        $part2 = $this->executor->submitPart($item, 'contacts', 99999, [
            'contacts' => [['name' => 'Jane Doe']],
        ], 'agent-1');

        $this->assertEquals(99999, $part2->seq);
    }

    public function test_rejected_part_can_be_fixed_and_resubmitted()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit invalid data
        try {
            $this->executor->submitPart($item, 'identity', null, ['domain' => 'acme.com'], 'agent-1');
            $this->fail('Should have thrown ValidationException');
        } catch (ValidationException $e) {
            // Expected
        }

        // Verify part is rejected
        $item->refresh();
        $this->assertEquals('rejected', $item->parts_state['identity']['status']);

        // Fix and resubmit
        $part = $this->executor->submitPart($item, 'identity', null, [
            'name' => 'Acme Corporation',
            'domain' => 'acme.com',
        ], 'agent-1');

        $this->assertEquals(PartStatus::VALIDATED, $part->status);

        // Verify parts_state reflects validated status
        $item->refresh();
        $this->assertEquals('validated', $item->parts_state['identity']['status']);
    }

    public function test_finalize_sets_both_assembled_result_and_result()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme Corp'], 'agent-1');
        $this->executor->submitPart($item, 'contacts', null, [
            'contacts' => [['name' => 'John Doe']],
        ], 'agent-1');

        $item = $this->executor->finalizeItem($item);

        $this->assertNotNull($item->assembled_result);
        $this->assertNotNull($item->result);
        $this->assertEquals($item->assembled_result, $item->result);
    }

    public function test_multiple_seq_for_same_key_creates_multiple_records()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit multiple sequences for same key
        $this->executor->submitPart($item, 'contacts', 1, [
            'contacts' => [['name' => 'John Doe']],
        ], 'agent-1');

        $this->executor->submitPart($item, 'contacts', 2, [
            'contacts' => [['name' => 'Jane Doe']],
        ], 'agent-1');

        // Both records should exist
        $parts = $item->parts()->where('part_key', 'contacts')->get();
        $this->assertCount(2, $parts);

        // Latest part should be seq=2
        $latestPart = $item->getLatestPart('contacts');
        $this->assertEquals(2, $latestPart->seq);
    }

    public function test_finalize_uses_latest_seq_per_key()
    {
        $order = $this->allocator->propose(
            type: 'test.partial',
            payload: ['company_name' => 'Acme Corp'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');

        // Submit identity (no seq)
        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme Corp V1'], 'agent-1');

        // Submit contacts with multiple sequences
        $this->executor->submitPart($item, 'contacts', 1, [
            'contacts' => [['name' => 'Old Contact']],
        ], 'agent-1');

        $this->executor->submitPart($item, 'contacts', 2, [
            'contacts' => [['name' => 'Latest Contact']],
        ], 'agent-1');

        // Update identity
        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme Corp V2'], 'agent-1');

        $item = $this->executor->finalizeItem($item);

        // Should use latest identity and latest contacts (seq=2)
        $this->assertEquals('Acme Corp V2', $item->assembled_result['identity']['name']);
        $this->assertEquals('Latest Contact', $item->assembled_result['contacts']['contacts'][0]['name']);
    }
}

/**
 * Test order type for partial submissions.
 */
class TestPartialOrderType extends AbstractOrderType
{
    public function type(): string
    {
        return 'test.partial';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['company_name'],
            'properties' => [
                'company_name' => ['type' => 'string'],
            ],
        ];
    }

    public function requiredParts(WorkItem $item): array
    {
        return ['identity', 'contacts'];
    }

    public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
    {
        return match ($partKey) {
            'identity' => [
                'name' => 'required|string',
                'domain' => 'nullable|string',
            ],
            'contacts' => [
                'contacts' => 'required|array',
                'contacts.*.name' => 'required|string',
                'contacts.*.email' => 'nullable|email',
            ],
            default => [],
        };
    }

    public function plan(WorkOrder $order): array
    {
        return [[
            'type' => $this->type(),
            'input' => $order->payload,
            'parts_required' => ['identity', 'contacts'],
            'max_attempts' => 3,
        ]];
    }

    public function apply(WorkOrder $order): Diff
    {
        return $this->makeDiff([], ['applied' => true], 'Test apply');
    }
}
