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
     * Enable automatic approval when ready.
     *
     * When true, orders will be automatically approved and applied when
     * readyForApproval() returns true. Use this for deterministic, safe
     * operations that don't require human review.
     *
     * Default: false (requires manual approval)
     */
    protected bool $autoApprove = false;

    /**
     * Get the acceptance policy for this order type.
     * Override this method to provide a custom policy.
     */
    public function acceptancePolicy(): AcceptancePolicy
    {
        return $this->getDefaultAcceptancePolicy();
    }

    /**
     * Determine if this order type should auto-approve when ready.
     */
    public function shouldAutoApprove(): bool
    {
        return $this->autoApprove;
    }

    /**
     * Get the default acceptance policy.
     * This uses Laravel validation under the hood.
     */
    protected function getDefaultAcceptancePolicy(): AcceptancePolicy
    {
        return new class($this) implements AcceptancePolicy
        {
            public function __construct(
                protected AbstractOrderType $orderType
            ) {}

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

                if (! $allSubmitted) {
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
    public function submissionValidationRules(\GregPriday\WorkManager\Models\WorkItem $item): array
    {
        return [];
    }

    /**
     * Hook called after validation passes but before saving submission.
     * Use this for custom business logic validation.
     * Throw ValidationException if validation fails.
     */
    public function afterValidateSubmission(\GregPriday\WorkManager\Models\WorkItem $item, array $result): void
    {
        // Override in subclass if needed
    }

    /**
     * Hook to determine if an order can be approved.
     * Called after checking all items are submitted.
     * Override for custom approval logic.
     */
    public function canApprove(WorkOrder $order): bool
    {
        return true;
    }

    /**
     * Hook called before apply() is executed.
     * Use this to perform pre-execution checks or setup.
     */
    public function beforeApply(WorkOrder $order): void
    {
        // Override in subclass if needed
    }

    /**
     * Hook called after apply() is executed successfully.
     * Use this for cleanup or post-processing.
     */
    public function afterApply(WorkOrder $order, Diff $diff): void
    {
        // Override in subclass if needed
    }

    /**
     * Define validation rules for a partial submission.
     * Return Laravel validation rules array for the specific part.
     *
     * Example:
     * return match ($partKey) {
     *     'identity' => ['name' => 'required|string', 'domain' => 'nullable|url'],
     *     'contacts' => ['contacts' => 'array', 'contacts.*.email' => 'email'],
     *     default => ['payload' => 'array'],
     * };
     */
    public function partialRules(\GregPriday\WorkManager\Models\WorkItem $item, string $partKey, ?int $seq): array
    {
        return [];
    }

    /**
     * Hook called after per-part validation passes but before saving.
     * Use this for custom business logic validation on a single part.
     * Throw ValidationException if validation fails.
     */
    public function afterValidatePart(\GregPriday\WorkManager\Models\WorkItem $item, string $partKey, array $payload, ?int $seq): void
    {
        // Override in subclass if needed
    }

    /**
     * Define which parts are required for this work item to be finalized.
     * Return array of part_key strings.
     *
     * Example:
     * return ['identity', 'firmographics', 'contacts'];
     */
    public function requiredParts(\GregPriday\WorkManager\Models\WorkItem $item): array
    {
        return [];
    }

    /**
     * Assemble all latest parts into a single result array.
     * This is called during finalization to merge all parts into assembled_result.
     *
     * Example:
     * return [
     *     'identity' => $parts->firstWhere('part_key', 'identity')->payload ?? [],
     *     'firmographics' => $parts->firstWhere('part_key', 'firmographics')->payload ?? [],
     *     'contacts' => Arr::wrap($parts->firstWhere('part_key', 'contacts')->payload['contacts'] ?? []),
     * ];
     */
    public function assemble(\GregPriday\WorkManager\Models\WorkItem $item, \Illuminate\Support\Collection $latestParts): array
    {
        // Default: simple merge of all parts by key
        $result = [];
        foreach ($latestParts as $part) {
            $result[$part->part_key] = $part->payload;
        }

        return $result;
    }

    /**
     * Hook called after assembly, before finalizing the item.
     * Perform whole-dataset validation across all parts.
     * Throw ValidationException if validation fails.
     */
    public function validateAssembled(\GregPriday\WorkManager\Models\WorkItem $item, array $assembled): void
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
