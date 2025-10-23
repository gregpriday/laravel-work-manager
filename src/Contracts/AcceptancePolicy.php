<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

/**
 * Contract for submission validation and approval readiness checks.
 *
 * @api
 *
 * @see docs/guides/validation-and-acceptance-policies.md
 */
interface AcceptancePolicy
{
    /**
     * Validate an agent submission.
     * Throw ValidationException with structured error codes if invalid.
     */
    public function validateSubmission(WorkItem $item, array $result): void;

    /**
     * Check if the order is ready for approval.
     * Performs cross-item validation.
     */
    public function readyForApproval(WorkOrder $order): bool;
}
