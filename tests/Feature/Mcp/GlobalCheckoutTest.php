<?php

namespace GregPriday\WorkManager\Tests\Feature\Mcp;

use GregPriday\WorkManager\Mcp\WorkManagerTools;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GlobalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected WorkManagerTools $tools;
    protected WorkAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = app(WorkManagerTools::class);
        $this->allocator = app(WorkAllocator::class);
    }

    public function test_global_checkout_returns_highest_priority_item()
    {
        // Create orders with different priorities
        $this->tools->propose('test.echo', ['message' => 'low'], priority: 10);
        $this->tools->propose('test.echo', ['message' => 'high'], priority: 100);
        $this->tools->propose('test.echo', ['message' => 'medium'], priority: 50);

        // Global checkout (no orderId) should return highest priority
        $result = $this->tools->checkout(
            orderId: null,
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('high', $result['item']['input']['message']);
    }

    public function test_global_checkout_uses_fifo_within_same_priority()
    {
        // Create three orders with same priority
        $first = $this->allocator->propose('test.echo', ['message' => 'first'], priority: 50);
        $this->allocator->plan($first);
        sleep(1);

        $second = $this->allocator->propose('test.echo', ['message' => 'second'], priority: 50);
        $this->allocator->plan($second);
        sleep(1);

        $third = $this->allocator->propose('test.echo', ['message' => 'third'], priority: 50);
        $this->allocator->plan($third);

        // Should get oldest item first (FIFO)
        $result = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');

        $this->assertTrue($result['success']);
        $this->assertEquals('first', $result['item']['input']['message']);
    }

    public function test_global_checkout_filters_by_type()
    {
        $this->tools->propose('test.echo', ['message' => 'echo'], priority: 100);
        $this->tools->propose('test.batch', ['batches' => [['id' => 'batch-1', 'data' => []]]], priority: 50);

        // Filter by type
        $result = $this->tools->checkout(
            orderId: null,
            type: 'test.batch',
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('batch-1', $result['item']['input']['id']);
        $this->assertEquals('test.batch', $result['item']['type']);
    }

    public function test_global_checkout_filters_by_min_priority()
    {
        $this->tools->propose('test.echo', ['message' => 'low'], priority: 10);
        $this->tools->propose('test.echo', ['message' => 'medium'], priority: 50);
        $this->tools->propose('test.echo', ['message' => 'high'], priority: 100);

        // Filter by min_priority >= 50
        $result = $this->tools->checkout(
            orderId: null,
            minPriority: 50,
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('high', $result['item']['input']['message']);
    }

    public function test_global_checkout_filters_by_tenant_id()
    {
        $this->tools->propose('test.echo', ['tenant_id' => 'tenant-1', 'message' => 'one'], priority: 100);
        $this->tools->propose('test.echo', ['tenant_id' => 'tenant-2', 'message' => 'two'], priority: 50);

        // Filter by tenant_id
        $result = $this->tools->checkout(
            orderId: null,
            tenantId: 'tenant-2',
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('two', $result['item']['input']['message']);
    }

    public function test_global_checkout_combines_multiple_filters()
    {
        $this->tools->propose('test.batch', ['tenant_id' => 'acme', 'batches' => [['id' => 'match', 'data' => []]]], priority: 80);
        $this->tools->propose('test.echo', ['tenant_id' => 'acme', 'message' => 'wrong-type'], priority: 90);
        $this->tools->propose('test.batch', ['tenant_id' => 'other', 'batches' => [['id' => 'wrong-tenant', 'data' => []]]], priority: 85);
        $this->tools->propose('test.batch', ['tenant_id' => 'acme', 'batches' => [['id' => 'low', 'data' => []]]], priority: 30);

        // Combine filters: type, min_priority, tenant_id
        $result = $this->tools->checkout(
            orderId: null,
            type: 'test.batch',
            minPriority: 50,
            tenantId: 'acme',
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('match', $result['item']['input']['id']);
    }

    public function test_global_checkout_returns_error_when_no_items_available()
    {
        // No orders created

        $result = $this->tools->checkout(
            orderId: null,
            agentId: 'mcp-agent-1'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('no_items_available', $result['code']);
    }

    public function test_global_checkout_returns_error_when_no_items_match_filters()
    {
        $this->tools->propose('test.echo', ['message' => 'test'], priority: 10);

        $result = $this->tools->checkout(
            orderId: null,
            type: 'nonexistent.type',
            agentId: 'mcp-agent-1'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('no_items_available', $result['code']);
        $this->assertStringContainsString('matching filters', $result['error']);
    }

    public function test_global_checkout_respects_per_agent_concurrency_limit()
    {
        config(['work-manager.lease.max_leases_per_agent' => 2]);

        // Create 3 orders
        for ($i = 1; $i <= 3; $i++) {
            $this->tools->propose('test.echo', ['message' => "item-$i"]);
        }

        // Agent 1 checks out twice (reaches limit)
        $result1 = $this->tools->checkout(orderId: null, agentId: 'agent-1');
        $this->assertTrue($result1['success']);

        $result2 = $this->tools->checkout(orderId: null, agentId: 'agent-1');
        $this->assertTrue($result2['success']);

        // Third checkout should fail due to limit
        $result3 = $this->tools->checkout(orderId: null, agentId: 'agent-1');
        $this->assertFalse($result3['success']);
        $this->assertEquals('no_items_available', $result3['code']);

        // Different agent should still be able to checkout
        $result4 = $this->tools->checkout(orderId: null, agentId: 'agent-2');
        $this->assertTrue($result4['success']);
    }

    public function test_global_checkout_respects_per_type_concurrency_limit()
    {
        config(['work-manager.lease.max_leases_per_type' => 1]);

        // Create 2 orders of same type
        $this->tools->propose('test.echo', ['message' => 'one']);
        $this->tools->propose('test.echo', ['message' => 'two']);

        // First checkout succeeds
        $result1 = $this->tools->checkout(orderId: null, type: 'test.echo', agentId: 'agent-1');
        $this->assertTrue($result1['success']);

        // Second checkout for same type should fail
        $result2 = $this->tools->checkout(orderId: null, type: 'test.echo', agentId: 'agent-2');
        $this->assertFalse($result2['success']);
        $this->assertEquals('no_items_available', $result2['code']);
    }

    public function test_scoped_checkout_still_works_backward_compatibility()
    {
        $orderResult = $this->tools->propose('test.echo', ['message' => 'test']);
        $orderId = $orderResult['order']['id'];

        // Old scoped checkout with orderId should still work
        $result = $this->tools->checkout(
            orderId: $orderId,
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('test', $result['item']['input']['message']);
    }

    public function test_global_checkout_skips_already_leased_items()
    {
        $order1 = $this->allocator->propose('test.echo', ['message' => 'leased'], priority: 100);

        $order2 = $this->allocator->propose('test.echo', ['message' => 'available'], priority: 50);

        // Manually lease the high priority item
        $order1->items()->first()->update([
            'state' => ItemState::LEASED,
            'leased_by_agent_id' => 'other-agent',
            'lease_expires_at' => now()->addMinutes(10),
        ]);

        // Should get the next available item (lower priority)
        $result = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');

        $this->assertTrue($result['success']);
        $this->assertEquals('available', $result['item']['input']['message']);
    }

    public function test_global_checkout_includes_lease_information()
    {
        $this->tools->propose('test.echo', ['message' => 'test']);

        $result = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('item', $result);
        $this->assertArrayHasKey('id', $result['item']);
        $this->assertArrayHasKey('order_id', $result['item']);
        $this->assertArrayHasKey('type', $result['item']);
        $this->assertArrayHasKey('input', $result['item']);
        $this->assertArrayHasKey('lease_expires_at', $result['item']);
        $this->assertArrayHasKey('heartbeat_every_seconds', $result['item']);
        $this->assertArrayHasKey('max_attempts', $result['item']);
        $this->assertArrayHasKey('current_attempt', $result['item']);
    }

    public function test_global_checkout_increments_current_attempt()
    {
        $order = $this->allocator->propose('test.echo', ['message' => 'test']);
        $this->allocator->plan($order);

        // Manually set attempts
        $item = $order->items()->first();
        $item->update(['attempts' => 2]);

        $result = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['item']['current_attempt']); // attempts + 1
    }

    public function test_can_heartbeat_after_global_checkout()
    {
        $this->tools->propose('test.echo', ['message' => 'test']);

        $checkoutResult = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');
        $this->assertTrue($checkoutResult['success']);

        $itemId = $checkoutResult['item']['id'];

        // Should be able to heartbeat the item
        $heartbeatResult = $this->tools->heartbeat($itemId, 'mcp-agent-1');

        $this->assertTrue($heartbeatResult['success']);
        $this->assertArrayHasKey('lease_expires_at', $heartbeatResult);
    }

    public function test_can_submit_after_global_checkout()
    {
        $this->tools->propose('test.echo', ['message' => 'test']);

        $checkoutResult = $this->tools->checkout(orderId: null, agentId: 'mcp-agent-1');
        $this->assertTrue($checkoutResult['success']);

        $itemId = $checkoutResult['item']['id'];

        // Should be able to submit the item (test.echo requires 'ok' and 'verified' fields)
        $submitResult = $this->tools->submit(
            itemId: $itemId,
            result: ['ok' => true, 'verified' => true, 'echoed_message' => 'test'],
            agentId: 'mcp-agent-1'
        );

        $this->assertTrue($submitResult['success']);
        $this->assertEquals('submitted', $submitResult['item']['state']);
    }
}
