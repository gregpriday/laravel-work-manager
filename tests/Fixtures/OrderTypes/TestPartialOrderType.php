<?php

namespace GregPriday\WorkManager\Tests\Fixtures\OrderTypes;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Collection;

/**
 * Test order type that supports partial submissions.
 * Used for testing partial submission workflows.
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
            'properties' => [
                'data' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    public function submissionValidationRules(WorkItem $item): array
    {
        return [];
    }

    /**
     * Validation rules for individual parts.
     */
    public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
    {
        // Basic validation - can be customized per part key
        return [];
    }

    /**
     * Custom validation after part validation.
     */
    public function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
    {
        // No custom validation for test type
    }

    /**
     * Define required parts for strict mode.
     */
    public function requiredParts(WorkItem $item): array
    {
        // Return parts_required from item if set, otherwise empty
        return $item->parts_required ?? [];
    }

    /**
     * Assemble parts into final result.
     */
    public function assemble(WorkItem $item, Collection $latestParts): array
    {
        $assembled = [];

        foreach ($latestParts as $part) {
            $assembled[$part->part_key] = $part->payload;
        }

        return $assembled;
    }

    /**
     * Validate the assembled result.
     */
    public function validateAssembled(WorkItem $item, array $assembled): void
    {
        // No validation for test type
    }

    public function apply(WorkOrder $order): Diff
    {
        $before = ['applied' => false, 'item_count' => 0];

        $after = [
            'applied' => true,
            'item_count' => $order->items->count(),
            'results' => $order->items->pluck('result')->toArray(),
        ];

        return $this->makeDiff(
            $before,
            $after,
            "Applied partial test order with {$order->items->count()} items"
        );
    }
}
