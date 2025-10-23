<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkItemPart;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\PartStatus;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class WorkItemPartScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_validated_scope_filters_by_validated_status()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Create parts with different statuses
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'identity',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['name' => 'John Doe'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::REJECTED,
            'payload' => ['email' => 'invalid'],
            'errors' => ['validation' => ['email' => ['required']]],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'preferences',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['theme' => 'dark'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'settings',
            'seq' => null,
            'status' => PartStatus::DRAFT,
            'payload' => ['draft' => 'data'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Use validated scope
        $validatedParts = WorkItemPart::validated()->get();

        expect($validatedParts)->toHaveCount(2);
        foreach ($validatedParts as $part) {
            expect($part->status)->toBe(PartStatus::VALIDATED);
        }
    }

    public function test_for_key_scope_filters_by_part_key()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Create multiple parts with same key
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => 1,
            'status' => PartStatus::VALIDATED,
            'payload' => ['email' => 'john@example.com'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => 2,
            'status' => PartStatus::VALIDATED,
            'payload' => ['phone' => '555-1234'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'identity',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['name' => 'John Doe'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Filter by 'contact' key
        $contactParts = WorkItemPart::forKey('contact')->get();

        expect($contactParts)->toHaveCount(2);
        foreach ($contactParts as $part) {
            expect($part->part_key)->toBe('contact');
        }

        // Filter by 'identity' key
        $identityParts = WorkItemPart::forKey('identity')->get();

        expect($identityParts)->toHaveCount(1)
            ->and($identityParts->first()->part_key)->toBe('identity');
    }

    public function test_latest_per_key_scope_returns_latest_part_for_each_key()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Create multiple versions of the same part key
        Carbon::setTestNow(now());
        $contact1 = WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => 1,
            'status' => PartStatus::VALIDATED,
            'payload' => ['email' => 'john@example.com'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        Carbon::setTestNow(now()->addSecond()); // Ensure different timestamps

        $contact2 = WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => 2,
            'status' => PartStatus::VALIDATED,
            'payload' => ['phone' => '555-1234'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Create parts for different key
        $identity = WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'identity',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['name' => 'John Doe'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Use latestPerKey scope
        $latestParts = WorkItemPart::latestPerKey($item->id)->get();

        expect($latestParts)->toHaveCount(2);

        // Should have latest contact part (contact2) and identity part
        $latestPartIds = $latestParts->pluck('id')->toArray();
        expect($latestPartIds)->toContain($contact2->id)
            ->and($latestPartIds)->toContain($identity->id)
            ->and($latestPartIds)->not->toContain($contact1->id);

        Carbon::setTestNow(); // Reset
    }

    public function test_scopes_can_be_chained()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Create validated contact part
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['email' => 'john@example.com'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Create rejected contact part
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => 2,
            'status' => PartStatus::REJECTED,
            'payload' => ['invalid' => 'data'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Create validated identity part
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'identity',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['name' => 'John Doe'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Chain validated and forKey scopes
        $validatedContactParts = WorkItemPart::validated()->forKey('contact')->get();

        expect($validatedContactParts)->toHaveCount(1)
            ->and($validatedContactParts->first()->part_key)->toBe('contact')
            ->and($validatedContactParts->first()->status)->toBe(PartStatus::VALIDATED);
    }

    public function test_latest_per_key_scope_with_multiple_items()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);

        // Create first item with parts
        $item1 = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        $item1Part1 = WorkItemPart::create([
            'work_item_id' => $item1->id,
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['email' => 'item1@example.com'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        $item1Part2 = WorkItemPart::create([
            'work_item_id' => $item1->id,
            'part_key' => 'contact',
            'seq' => 2,
            'status' => PartStatus::VALIDATED,
            'payload' => ['phone' => '555-1111'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // Create second item with parts
        $item2 = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        WorkItemPart::create([
            'work_item_id' => $item2->id,
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::VALIDATED,
            'payload' => ['email' => 'item2@example.com'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        // latestPerKey should only return parts for specified item
        $item1LatestParts = WorkItemPart::latestPerKey($item1->id)->get();

        expect($item1LatestParts)->toHaveCount(1)
            ->and($item1LatestParts->first()->id)->toBe($item1Part2->id)
            ->and($item1LatestParts->first()->work_item_id)->toBe($item1->id);
    }

    public function test_validated_scope_returns_empty_when_no_validated_parts()
    {
        $order = WorkOrder::create(['type' => 'test.partial', 'state' => \GregPriday\WorkManager\Support\OrderState::QUEUED, 'payload' => []]);
        $item = WorkItem::create([
            'order_id' => $order->id,
            'type' => 'test.partial',
            'state' => ItemState::IN_PROGRESS,
            'input' => [],
        ]);

        // Create only rejected parts
        WorkItemPart::create([
            'work_item_id' => $item->id,
            'part_key' => 'contact',
            'seq' => null,
            'status' => PartStatus::REJECTED,
            'payload' => ['invalid' => 'data'],
            'submitted_by_agent_id' => 'agent-1',
        ]);

        $validatedParts = WorkItemPart::validated()->get();

        expect($validatedParts)->toBeEmpty();
    }
}
