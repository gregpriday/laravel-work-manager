<?php

namespace GregPriday\WorkManager\Tests\Fixtures\OrderTypes;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;

/**
 * Simple test order type that echoes data back.
 * Used for testing basic flows without complex business logic.
 */
class EchoOrderType extends AbstractOrderType
{
    public function type(): string
    {
        return 'test.echo';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['message'],
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'metadata' => [
                    'type' => 'object',
                ],
            ],
        ];
    }

    public function submissionValidationRules(WorkItem $item): array
    {
        return [
            'ok' => 'required|boolean',
            'verified' => 'required|boolean',
            'echoed_message' => 'nullable|string',
        ];
    }

    public function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Custom business logic: must be both ok and verified
        if (!$result['verified']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'verified' => ['Result must be verified'],
            ]);
        }
    }

    public function apply(WorkOrder $order): Diff
    {
        $before = ['applied' => false, 'item_count' => 0];

        $processedMessages = [];
        foreach ($order->items as $item) {
            if (isset($item->result['echoed_message'])) {
                $processedMessages[] = $item->result['echoed_message'];
            }
        }

        $after = [
            'applied' => true,
            'item_count' => $order->items->count(),
            'messages' => $processedMessages,
        ];

        return $this->makeDiff(
            $before,
            $after,
            "Applied echo order with {$order->items->count()} items"
        );
    }
}
