<?php

namespace GregPriday\WorkManager\Support;

use GregPriday\WorkManager\Contracts\AcceptancePolicy;
use GregPriday\WorkManager\Contracts\OrderType;
use GregPriday\WorkManager\Models\WorkOrder;

/**
 * Base for domain order types: schema/plan/validation hooks + idempotent apply().
 *
 * @api Implementors MUST keep apply() idempotent; supports auto-approval via $autoApprove.
 *
 * @see docs/concepts/what-it-does.md
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
     * Laravel validation rules for item result; override for type-specific constraints.
     *
     * @return array<string,string|array<string>> Laravel validation rules
     */
    public function submissionValidationRules(\GregPriday\WorkManager\Models\WorkItem $item): array
    {
        return [];
    }

    /**
     * Custom validation after Laravel rules pass; throw ValidationException to reject.
     */
    public function afterValidateSubmission(\GregPriday\WorkManager\Models\WorkItem $item, array $result): void
    {
        // Override in subclass if needed
    }

    /**
     * Cross-item approval gate called after all items submitted; override for custom logic.
     */
    public function canApprove(WorkOrder $order): bool
    {
        return true;
    }

    /**
     * Pre-execution hook called before apply(); use for setup/preflight checks.
     */
    public function beforeApply(WorkOrder $order): void
    {
        // Override in subclass if needed
    }

    /**
     * Post-execution hook after apply() succeeds; use for cleanup (jobs, cache flush).
     */
    public function afterApply(WorkOrder $order, Diff $diff): void
    {
        // Override in subclass if needed
    }

    /**
     * Laravel validation rules per part_key; override for per-part constraints.
     *
     * @return array<string,string|array<string>> Laravel validation rules
     */
    public function partialRules(\GregPriday\WorkManager\Models\WorkItem $item, string $partKey, ?int $seq): array
    {
        return [];
    }

    /**
     * Custom per-part validation after Laravel rules pass; throw ValidationException to reject.
     */
    public function afterValidatePart(\GregPriday\WorkManager\Models\WorkItem $item, string $partKey, array $payload, ?int $seq): void
    {
        // Override in subclass if needed
    }

    /**
     * Part keys required for finalize("strict"); override to enforce multi-part structure.
     *
     * @return string[] Part keys
     */
    public function requiredParts(\GregPriday\WorkManager\Models\WorkItem $item): array
    {
        return [];
    }

    /**
     * Merge latest validated parts into final result; called by finalize(); default merges by key.
     *
     * @param  \Illuminate\Support\Collection<int,\GregPriday\WorkManager\Models\WorkItemPart>  $latestParts
     * @return array<string,mixed> Assembled result
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
     * Validate whole assembled result after merge; throw ValidationException if invalid.
     */
    public function validateAssembled(\GregPriday\WorkManager\Models\WorkItem $item, array $assembled): void
    {
        // Override in subclass if needed
    }

    /**
     * Break order into work items; default creates single item; override for batching/sharding.
     *
     * @return array<array{type:string,input:array<string,mixed>,max_attempts?:int}> Item configs
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
