<?php

use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ItemState;

it('validates submission matches batch item input', function () {
    $allocator = app(WorkAllocator::class);
    $executor = app(WorkExecutor::class);

    $order = $allocator->propose('test.batch', [
        'batches' => [
            ['id' => 'batch-a', 'data' => []],
        ],
    ]);

    $allocator->plan($order);

    $item = $order->items->first();
    $item->update([
        'state' => ItemState::IN_PROGRESS,
        'leased_by_agent_id' => 'agent-1',
        'lease_expires_at' => now()->addMinutes(10),
    ]);

    // Submit with missing required fields - should fail validation
    expect(fn () => $executor->submit($item->fresh(), [
        'batch_id' => 'batch-a',
        // Missing 'processed' and 'count' required by submissionValidationRules
    ], 'agent-1'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
