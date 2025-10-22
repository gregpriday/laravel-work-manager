<?php

namespace GregPriday\WorkManager\Support;

use GregPriday\WorkManager\Contracts\AcceptancePolicy;
use GregPriday\WorkManager\Contracts\OrderType;
use GregPriday\WorkManager\Models\WorkOrder;

/**
 * Abstract base class for order types.
 * Extend this class and implement the required methods.
 */
abstract class AbstractOrderType implements OrderType
{
    /**
     * Get the acceptance policy for this order type.
     * Override this method to provide a custom policy.
     */
    public function acceptancePolicy(): AcceptancePolicy
    {
        return $this->getDefaultAcceptancePolicy();
    }

    /**
     * Get the default acceptance policy.
     * This uses Laravel validation under the hood.
     */
    protected function getDefaultAcceptancePolicy(): AcceptancePolicy
    {
        return new class($this) implements AcceptancePolicy {
            public function __construct(
                protected AbstractOrderType $orderType
            ) {
            }

            public function validateSubmission(\GregPriday\WorkManager\Models\WorkItem $item, array $result): void
            {
                $rules = $this->orderType->submissionValidationRules($item);

                if (empty($rules)) {
                    return;
                }

                $validator = validator($result, $rules);

                if ($validator->fails()) {
                    throw new \Illuminate\Validation\ValidationException($validator);
                }

                // Call custom validation hook
                $this->orderType->afterValidateSubmission($item, $result);
            }

            public function readyForApproval(\GregPriday\WorkManager\Models\WorkOrder $order): bool
            {
                // Check all items are submitted
                $allSubmitted = $order->items()
                    ->whereIn('state', ['submitted', 'accepted'])
                    ->count() === $order->items()->count();

                if (!$allSubmitted) {
                    return false;
                }

                // Call custom hook
                return $this->orderType->canApprove($order);
            }
        };
    }

    /**
     * Define validation rules for submission.
     * Return Laravel validation rules array.
     *
     * Example:
     * return [
     *     'success' => 'required|boolean',
     *     'data' => 'required|array',
     *     'data.field' => 'required|string',
     * ];
     */
    protected function submissionValidationRules(\GregPriday\WorkManager\Models\WorkItem $item): array
    {
        return [];
    }

    /**
     * Hook called after validation passes but before saving submission.
     * Use this for custom business logic validation.
     * Throw ValidationException if validation fails.
     */
    protected function afterValidateSubmission(\GregPriday\WorkManager\Models\WorkItem $item, array $result): void
    {
        // Override in subclass if needed
    }

    /**
     * Hook to determine if an order can be approved.
     * Called after checking all items are submitted.
     * Override for custom approval logic.
     */
    protected function canApprove(WorkOrder $order): bool
    {
        return true;
    }

    /**
     * Hook called before apply() is executed.
     * Use this to perform pre-execution checks or setup.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        // Override in subclass if needed
    }

    /**
     * Hook called after apply() is executed successfully.
     * Use this for cleanup or post-processing.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        // Override in subclass if needed
    }

    /**
     * Default plan implementation - creates a single item.
     * Override this to create multiple items or custom planning logic.
     */
    public function plan(WorkOrder $order): array
    {
        return [[
            'type' => $this->type(),
            'input' => $order->payload,
            'max_attempts' => config('work-manager.retry.default_max_attempts', 3),
        ]];
    }

    /**
     * Helper to create a diff with a simple before/after.
     */
    protected function makeDiff(array $before, array $after, ?string $summary = null): Diff
    {
        return Diff::fromArrays($before, $after, $summary);
    }

    /**
     * Helper to create an empty diff (when no changes were made).
     */
    protected function emptyDiff(): Diff
    {
        return Diff::empty();
    }
}
