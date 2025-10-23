<?php

namespace GregPriday\WorkManager\Tests\Fixtures\OrderTypes;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;

/**
 * Test order type that creates multiple work items.
 * Used for testing cross-item approval and batch processing.
 */
class BatchOrderType extends AbstractOrderType
{
    public function type(): string
    {
        return 'test.batch';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['batches'],
            'properties' => [
                'batches' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'data'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'data' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function plan(WorkOrder $order): array
    {
        // Create one work item per batch
        $items = [];
        foreach ($order->payload['batches'] as $batch) {
            $items[] = [
                'type' => $this->type(),
                'input' => $batch,
                'max_attempts' => config('work-manager.retry.default_max_attempts', 3),
            ];
        }

        return $items;
    }

    public function submissionValidationRules(WorkItem $item): array
    {
        return [
            'batch_id' => 'required|string',
            'processed' => 'required|boolean',
            'count' => 'required|integer|min:0',
        ];
    }

    public function canApprove(WorkOrder $order): bool
    {
        // Custom approval logic: all batches must have count > 0
        foreach ($order->items as $item) {
            if (! isset($item->result['count']) || $item->result['count'] <= 0) {
                return false;
            }
        }

        return true;
    }

    public function apply(WorkOrder $order): Diff
    {
        $before = [
            'total_processed' => 0,
            'batch_count' => 0,
        ];

        $totalCount = 0;
        $batchIds = [];

        foreach ($order->items as $item) {
            if (isset($item->result['count'])) {
                $totalCount += $item->result['count'];
            }
            if (isset($item->result['batch_id'])) {
                $batchIds[] = $item->result['batch_id'];
            }
        }

        $after = [
            'total_processed' => $totalCount,
            'batch_count' => count($batchIds),
            'batch_ids' => $batchIds,
        ];

        return $this->makeDiff(
            $before,
            $after,
            "Applied batch order: processed {$totalCount} items across {$after['batch_count']} batches"
        );
    }
}
