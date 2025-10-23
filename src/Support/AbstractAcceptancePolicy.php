<?php

namespace GregPriday\WorkManager\Support;

use GregPriday\WorkManager\Contracts\AcceptancePolicy;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

/**
 * Abstract base class for acceptance policies.
 * Extend this class to create custom validation logic.
 */
abstract class AbstractAcceptancePolicy implements AcceptancePolicy
{
    /**
     * Validate an agent submission.
     */
    public function validateSubmission(WorkItem $item, array $result): void
    {
        // Run Laravel validation
        $rules = $this->validationRules($item);

        if (! empty($rules)) {
            $validator = validator($result, $rules, $this->validationMessages());

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }

        // Run custom validation
        $this->customValidation($item, $result);
    }

    /**
     * Check if the order is ready for approval.
     */
    public function readyForApproval(WorkOrder $order): bool
    {
        // Check all items are in valid state
        $validStates = ['submitted', 'accepted'];
        $allValid = $order->items()
            ->whereIn('state', $validStates)
            ->count() === $order->items()->count();

        if (! $allValid) {
            return false;
        }

        // Run custom approval checks
        return $this->customApprovalCheck($order);
    }

    /**
     * Define Laravel validation rules for the submission.
     * Return an array of validation rules.
     *
     * Example:
     * return [
     *     'status' => 'required|in:success,failed',
     *     'data' => 'required|array',
     *     'data.*.id' => 'required|integer',
     * ];
     */
    abstract protected function validationRules(WorkItem $item): array;

    /**
     * Custom validation messages.
     * Override to provide custom error messages.
     */
    protected function validationMessages(): array
    {
        return [];
    }

    /**
     * Perform custom validation logic beyond Laravel rules.
     * Throw ValidationException with error details if validation fails.
     */
    protected function customValidation(WorkItem $item, array $result): void
    {
        // Override in subclass if needed
    }

    /**
     * Custom approval check logic.
     * Return true if order can be approved, false otherwise.
     */
    protected function customApprovalCheck(WorkOrder $order): bool
    {
        return true;
    }

    /**
     * Helper to throw a validation exception with structured errors.
     */
    protected function fail(string $field, string $code, string $message): void
    {
        throw ValidationException::withMessages([
            $field => ["{$code}: {$message}"],
        ]);
    }
}
