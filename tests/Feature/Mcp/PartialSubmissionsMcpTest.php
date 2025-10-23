<?php

namespace GregPriday\WorkManager\Tests\Feature\Mcp;

use GregPriday\WorkManager\Mcp\WorkManagerTools;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\Diff;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PartialSubmissionsMcpTest extends TestCase
{
    use RefreshDatabase;

    protected WorkManagerTools $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = app(WorkManagerTools::class);

        // Register test order type
        app('work-manager')->registry()->register(new TestPartialMcpOrderType());
    }

    public function test_submit_part_returns_success_structure()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $result = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'identity',
            payload: ['name' => 'Acme Corporation'],
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('identity', $result['part']['part_key']);
        $this->assertEquals('validated', $result['part']['status']);
        $this->assertArrayHasKey('item_parts_state', $result);
        $this->assertArrayHasKey('identity', $result['item_parts_state']);
    }

    public function test_submit_part_returns_validation_error_structure()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $result = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'identity',
            payload: [], // Missing required 'name'
            agentId: 'mcp-agent-1'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('validation_failed', $result['code']);
        $this->assertArrayHasKey('details', $result);
    }

    public function test_submit_part_uses_idempotency_caching()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $idempotencyKey = 'mcp-part-key-' . uniqid();

        // First submission
        $result1 = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'identity',
            payload: ['name' => 'Acme Corporation'],
            agentId: 'mcp-agent-1',
            idempotencyKey: $idempotencyKey
        );

        $partId1 = $result1['part']['id'];

        // Second submission with same idempotency key but different payload
        $result2 = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'identity',
            payload: ['name' => 'Different Name'],
            agentId: 'mcp-agent-1',
            idempotencyKey: $idempotencyKey
        );

        $partId2 = $result2['part']['id'];

        // Should return cached result (same part ID)
        $this->assertEquals($partId1, $partId2);
    }

    public function test_list_parts_returns_all_parts_with_state()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        // Submit multiple parts
        $this->tools->submitPart($itemId, 'identity', ['name' => 'Acme'], agentId: 'mcp-agent-1');
        $this->tools->submitPart($itemId, 'contacts', ['email' => 'test@acme.com'], agentId: 'mcp-agent-1');

        $result = $this->tools->listParts($itemId);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['parts']);
        $this->assertArrayHasKey('parts_state', $result);
    }

    public function test_list_parts_filters_by_part_key()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $this->tools->submitPart($itemId, 'identity', ['name' => 'Acme'], agentId: 'mcp-agent-1');
        $this->tools->submitPart($itemId, 'contacts', ['email' => 'test@acme.com'], agentId: 'mcp-agent-1');

        $result = $this->tools->listParts($itemId, partKey: 'identity');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('identity', $result['parts'][0]['part_key']);
    }

    public function test_finalize_returns_success_with_assembled_result()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        // Submit all required parts
        $this->tools->submitPart($itemId, 'identity', ['name' => 'Acme Corp'], agentId: 'mcp-agent-1');
        $this->tools->submitPart($itemId, 'contacts', ['email' => 'test@acme.com'], agentId: 'mcp-agent-1');

        $result = $this->tools->finalize($itemId, mode: 'strict');

        $this->assertTrue($result['success']);
        $this->assertEquals('submitted', $result['item']['state']);
        $this->assertNotEmpty($result['item']['assembled_result']);
        $this->assertArrayHasKey('identity', $result['item']['assembled_result']);
        $this->assertArrayHasKey('contacts', $result['item']['assembled_result']);
    }

    public function test_finalize_returns_validation_error_when_missing_parts()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        // Submit only one part
        $this->tools->submitPart($itemId, 'identity', ['name' => 'Acme Corp'], agentId: 'mcp-agent-1');

        $result = $this->tools->finalize($itemId, mode: 'strict');

        $this->assertFalse($result['success']);
        $this->assertEquals('validation_failed', $result['code']);
        $this->assertArrayHasKey('details', $result);
    }

    public function test_finalize_uses_idempotency_caching()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $this->tools->submitPart($itemId, 'identity', ['name' => 'Acme'], agentId: 'mcp-agent-1');
        $this->tools->submitPart($itemId, 'contacts', ['email' => 'test@acme.com'], agentId: 'mcp-agent-1');

        $idempotencyKey = 'mcp-finalize-key-' . uniqid();

        // First finalization
        $result1 = $this->tools->finalize($itemId, mode: 'strict', idempotencyKey: $idempotencyKey);

        // Second finalization with same key
        $result2 = $this->tools->finalize($itemId, mode: 'strict', idempotencyKey: $idempotencyKey);

        // Should return cached result
        $this->assertEquals($result1['item']['id'], $result2['item']['id']);
        $this->assertEquals($result1['item']['state'], $result2['item']['state']);
    }

    public function test_submit_part_with_sequence_number()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        // Submit with sequence number
        $result = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'contacts',
            payload: ['email' => 'contact1@acme.com'],
            seq: 1,
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['part']['seq']);
    }

    public function test_submit_part_with_evidence_and_notes()
    {
        $orderResult = $this->tools->propose('test.partial.mcp', ['company' => 'Acme']);
        $orderId = $orderResult['order']['id'];

        $checkoutResult = $this->tools->checkout(orderId: $orderId, agentId: 'mcp-agent-1');
        $itemId = $checkoutResult['item']['id'];

        $result = $this->tools->submitPart(
            itemId: $itemId,
            partKey: 'identity',
            payload: ['name' => 'Acme Corp'],
            evidence: [['url' => 'https://acme.com', 'title' => 'Company Website']],
            notes: 'Found on company homepage',
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);

        // Verify evidence and notes were stored
        $item = WorkItem::find($itemId);
        $part = $item->getLatestPart('identity');
        $this->assertNotEmpty($part->evidence);
        $this->assertEquals('Found on company homepage', $part->notes);
    }
}

class TestPartialMcpOrderType extends AbstractOrderType
{
    public function type(): string
    {
        return 'test.partial.mcp';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['company'],
            'properties' => ['company' => ['type' => 'string']],
        ];
    }

    public function requiredParts(WorkItem $item): array
    {
        return ['identity', 'contacts'];
    }

    public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
    {
        return match ($partKey) {
            'identity' => ['name' => 'required|string'],
            'contacts' => ['email' => 'required|email'],
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
        return $this->emptyDiff();
    }
}
