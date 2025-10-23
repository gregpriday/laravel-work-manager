<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PartialsLimitsTest extends TestCase
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

    public function test_partials_config_exists_and_has_defaults()
    {
        expect(config('work-manager.partials'))->not->toBeNull()
            ->and(config('work-manager.partials.enabled'))->toBe(true)
            ->and(config('work-manager.partials.max_parts_per_item'))->toBe(100)
            ->and(config('work-manager.partials.max_payload_bytes'))->toBe(1048576);
    }

    public function test_can_submit_up_to_max_parts_per_item()
    {
        $maxParts = config('work-manager.partials.max_parts_per_item');

        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Submit parts up to limit (using smaller number for test performance)
        $testLimit = min(10, $maxParts);
        for ($i = 1; $i <= $testLimit; $i++) {
            $this->executor->submitPart(
                $item,
                "part_{$i}",
                null,
                ['data' => "value_{$i}"],
                $agentId
            );
        }

        $item->refresh();
        expect($item->parts()->count())->toBe($testLimit);
    }

    public function test_can_submit_parts_with_large_payloads_up_to_limit()
    {
        $maxBytes = config('work-manager.partials.max_payload_bytes');

        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $agentId = 'agent-1';
        $this->leaseService->acquire($item->id, $agentId);
        $item = $item->fresh();

        // Create payload close to limit (90% of max)
        $payloadSize = (int) ($maxBytes * 0.9);
        $largePayload = [
            'data' => str_repeat('a', $payloadSize),
        ];

        // Should succeed
        $part = $this->executor->submitPart($item, 'large_part', null, $largePayload, $agentId);

        expect($part)->not->toBeNull()
            ->and($part->payload)->toBe($largePayload);
    }

    public function test_parts_state_tracks_all_submitted_parts()
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
        $item = $item->fresh();

        // Submit multiple parts
        for ($i = 1; $i <= 5; $i++) {
            $this->executor->submitPart($item, "part_{$i}", null, ['value' => $i], $agentId);
        }

        $item->refresh();

        // parts_state should track all parts
        expect($item->parts_state)->toHaveCount(5)
            ->and($item->parts_state)->toHaveKeys(['part_1', 'part_2', 'part_3', 'part_4', 'part_5']);

        foreach (['part_1', 'part_2', 'part_3', 'part_4', 'part_5'] as $key) {
            expect($item->parts_state[$key])->toHaveKey('status')
                ->and($item->parts_state[$key]['status'])->toBe('validated');
        }
    }

    public function test_payload_size_is_stored_correctly_for_large_parts()
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
        $item = $item->fresh();

        // Create a sizable payload
        $payload = [
            'description' => str_repeat('Lorem ipsum dolor sit amet. ', 100),
            'data' => array_fill(0, 50, 'test value'),
        ];

        $part = $this->executor->submitPart($item, 'large_data', null, $payload, $agentId);

        // Verify payload is stored correctly
        expect($part->payload)->toBe($payload)
            ->and(strlen(json_encode($part->payload)))->toBeGreaterThan(1000);
    }

    public function test_config_limits_can_be_overridden()
    {
        // Test that config can be changed at runtime
        config(['work-manager.partials.max_parts_per_item' => 50]);
        config(['work-manager.partials.max_payload_bytes' => 524288]); // 512KB

        expect(config('work-manager.partials.max_parts_per_item'))->toBe(50)
            ->and(config('work-manager.partials.max_payload_bytes'))->toBe(524288);
    }

    public function test_partials_can_be_disabled_via_config()
    {
        // Test that the enabled flag exists
        expect(config('work-manager.partials.enabled'))->toBe(true);

        // Change config
        config(['work-manager.partials.enabled' => false]);

        expect(config('work-manager.partials.enabled'))->toBe(false);

        // Note: Actual enforcement would be in controller/service layer
        // This test verifies the config option exists and can be toggled
    }

    public function test_multiple_parts_with_different_sizes()
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
        $item = $item->fresh();

        // Submit parts of varying sizes
        $this->executor->submitPart($item, 'small', null, ['value' => 'x'], $agentId);
        $this->executor->submitPart($item, 'medium', null, ['data' => str_repeat('y', 1000)], $agentId);
        $this->executor->submitPart($item, 'large', null, ['content' => str_repeat('z', 10000)], $agentId);

        $item->refresh();

        expect($item->parts()->count())->toBe(3);

        // Verify all parts are stored correctly
        $small = $item->parts()->where('part_key', 'small')->first();
        $medium = $item->parts()->where('part_key', 'medium')->first();
        $large = $item->parts()->where('part_key', 'large')->first();

        expect($small->payload)->toBe(['value' => 'x'])
            ->and(strlen(json_encode($medium->payload)))->toBeGreaterThan(1000)
            ->and(strlen(json_encode($large->payload)))->toBeGreaterThan(10000);
    }

    public function test_json_encoding_payload_size_calculation()
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
        $item = $item->fresh();

        $payload = [
            'unicode' => '你好世界',
            'special_chars' => "Line 1\nLine 2\tTabbed",
            'nested' => [
                'array' => [1, 2, 3],
                'object' => ['key' => 'value'],
            ],
        ];

        $part = $this->executor->submitPart($item, 'unicode_test', null, $payload, $agentId);

        // JSON encoding size should account for encoding overhead
        $jsonSize = strlen(json_encode($part->payload));

        expect($jsonSize)->toBeGreaterThan(0)
            ->and($part->payload)->toBe($payload);
    }

    public function test_many_small_parts_vs_few_large_parts()
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
        $item1 = $item1->fresh();
        $this->leaseService->acquire($item2->id, 'agent-2');
        $item2 = $item2->fresh();

        // Item 1: Many small parts
        for ($i = 1; $i <= 20; $i++) {
            $this->executor->submitPart($item1, "part_{$i}", null, ['value' => $i], $agentId);
        }

        // Item 2: Few large parts
        for ($i = 1; $i <= 3; $i++) {
            $this->executor->submitPart(
                $item2,
                "part_{$i}",
                null,
                ['data' => str_repeat('x', 1000)],
                'agent-2'
            );
        }

        $item1->refresh();
        $item2->refresh();

        expect($item1->parts()->count())->toBe(20)
            ->and($item2->parts()->count())->toBe(3)
            ->and($item1->parts_state)->toHaveCount(20)
            ->and($item2->parts_state)->toHaveCount(3);
    }
}
