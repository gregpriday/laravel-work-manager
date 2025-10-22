<?php

namespace App\WorkTypes;

use App\Models\Product;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Example: Work order type for inserting database records.
 *
 * This example shows:
 * - Using AbstractOrderType for reduced boilerplate
 * - Laravel validation integration
 * - Lifecycle hooks (beforeApply, afterApply)
 * - Custom verification logic
 * - Database transactions
 * - Event handling
 */
class DatabaseRecordInsertType extends AbstractOrderType
{
    /**
     * The unique identifier for this work order type.
     */
    public function type(): string
    {
        return 'database.record.insert';
    }

    /**
     * JSON schema for validating the initial payload.
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['table', 'records'],
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'enum' => ['products', 'categories', 'tags'], // Allowed tables
                ],
                'records' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'validate' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Define validation rules for agent submissions.
     * These are standard Laravel validation rules.
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'inserted' => 'required|boolean',
            'record_ids' => 'required_if:inserted,true|array',
            'record_ids.*' => 'integer|min:1',
            'verification' => 'required|array',
            'verification.checked' => 'required|boolean',
            'verification.valid' => 'required_if:verification.checked,true|boolean',
        ];
    }

    /**
     * Custom validation after Laravel rules pass.
     * Use this to implement business logic validation.
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify that the records were actually inserted
        if ($result['inserted']) {
            $recordIds = $result['record_ids'];
            $table = $item->order->payload['table'];

            // Check records exist in database
            $model = $this->getModelForTable($table);
            $existingCount = $model::whereIn('id', $recordIds)->count();

            if ($existingCount !== count($recordIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'record_ids' => ['Not all record IDs exist in the database'],
                ]);
            }
        }

        // Verify the verification step was done
        if (!$result['verification']['checked'] || !$result['verification']['valid']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'verification' => ['Records must be verified as valid before submission'],
            ]);
        }
    }

    /**
     * Custom approval check.
     * Only approve if all items are verified as valid.
     */
    protected function canApprove(WorkOrder $order): bool
    {
        // Check all submitted items have valid verification
        foreach ($order->items as $item) {
            if (!isset($item->result['verification']['valid']) ||
                !$item->result['verification']['valid']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook called before applying the work order.
     * Use for setup or pre-checks.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        Log::info('Starting to apply database record insertions', [
            'order_id' => $order->id,
            'table' => $order->payload['table'],
            'record_count' => count($order->payload['records']),
        ]);

        // Could do things like:
        // - Check system capacity
        // - Acquire locks
        // - Backup existing data
    }

    /**
     * Apply the work order - actually perform the database operations.
     * This should be idempotent!
     */
    public function apply(WorkOrder $order): Diff
    {
        $table = $order->payload['table'];
        $insertedIds = [];
        $before = ['table' => $table, 'record_count' => 0];

        // Collect all inserted IDs from work items
        foreach ($order->items as $item) {
            if (isset($item->result['record_ids'])) {
                $insertedIds = array_merge($insertedIds, $item->result['record_ids']);
            }
        }

        // Get current state
        $model = $this->getModelForTable($table);
        $records = $model::whereIn('id', $insertedIds)->get();

        $after = [
            'table' => $table,
            'record_count' => $records->count(),
            'record_ids' => $insertedIds,
        ];

        // Mark records as "applied" (example: update a status field)
        if (!empty($insertedIds) && $model::where('id', $insertedIds[0])->value('id')) {
            // Records exist, mark them as processed
            DB::table($table)
                ->whereIn('id', $insertedIds)
                ->update(['processed' => true, 'processed_at' => now()]);
        }

        return $this->makeDiff(
            $before,
            $after,
            "Inserted and processed {$records->count()} records into {$table}"
        );
    }

    /**
     * Hook called after successful apply.
     * Use for cleanup or triggering downstream processes.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        Log::info('Successfully applied database record insertions', [
            'order_id' => $order->id,
            'changes' => $diff->toArray(),
        ]);

        // Could do things like:
        // - Trigger cache invalidation
        // - Send notifications
        // - Queue follow-up work
        // - Update analytics

        // Example: Dispatch an event
        event(new \App\Events\RecordsProcessed(
            $order->payload['table'],
            $diff->after['record_ids'] ?? []
        ));
    }

    /**
     * Helper to get the Eloquent model for a table name.
     */
    protected function getModelForTable(string $table): string
    {
        return match ($table) {
            'products' => Product::class,
            'categories' => \App\Models\Category::class,
            'tags' => \App\Models\Tag::class,
            default => throw new \Exception("Unknown table: {$table}"),
        };
    }
}
