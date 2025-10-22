<?php

namespace App\WorkTypes;

use App\Models\User;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;

/**
 * Example: Sync user data from external source.
 *
 * A more realistic example showing:
 * - External data synchronization
 * - Data verification
 * - Incremental updates
 */
class UserDataSyncType extends AbstractOrderType
{
    public function type(): string
    {
        return 'user.data.sync';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source', 'user_ids'],
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'enum' => ['crm', 'analytics', 'billing'],
                ],
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Plan creates one work item per batch of users.
     */
    public function plan(WorkOrder $order): array
    {
        $userIds = $order->payload['user_ids'];
        $batchSize = 50;
        $batches = array_chunk($userIds, $batchSize);

        return array_map(function ($batch) use ($order) {
            return [
                'type' => $this->type(),
                'input' => [
                    'source' => $order->payload['source'],
                    'user_ids' => $batch,
                    'fields' => $order->payload['fields'] ?? null,
                ],
                'max_attempts' => 3,
            ];
        }, $batches);
    }

    /**
     * Validate agent submission.
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'synced_users' => 'required|array',
            'synced_users.*.user_id' => 'required|integer',
            'synced_users.*.data' => 'required|array',
            'synced_users.*.verified' => 'required|boolean',
            'errors' => 'nullable|array',
        ];
    }

    /**
     * Custom verification.
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify all users in the batch were processed
        $expectedIds = $item->input['user_ids'];
        $syncedIds = array_column($result['synced_users'], 'user_id');

        if (count(array_diff($expectedIds, $syncedIds)) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'synced_users' => ['Not all users in batch were synced'],
            ]);
        }

        // Verify all synced users were verified
        foreach ($result['synced_users'] as $syncedUser) {
            if (!$syncedUser['verified']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'synced_users' => ['All synced user data must be verified'],
                ]);
            }
        }
    }

    /**
     * Apply the sync - update user records in database.
     */
    public function apply(WorkOrder $order): Diff
    {
        $updatedCount = 0;
        $before = [];
        $after = [];

        DB::transaction(function () use ($order, &$updatedCount, &$before, &$after) {
            foreach ($order->items as $item) {
                foreach ($item->result['synced_users'] as $syncedUser) {
                    $user = User::find($syncedUser['user_id']);

                    if ($user) {
                        $before[$user->id] = $user->toArray();

                        // Update user with synced data
                        $user->update($syncedUser['data']);

                        $after[$user->id] = $user->fresh()->toArray();
                        $updatedCount++;
                    }
                }
            }
        });

        return $this->makeDiff(
            ['updated_count' => 0],
            ['updated_count' => $updatedCount],
            "Synced data for {$updatedCount} users from {$order->payload['source']}"
        );
    }

    /**
     * Post-apply actions.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        // Invalidate caches, trigger webhooks, etc.
        \Illuminate\Support\Facades\Cache::tags(['users'])->flush();
    }
}
