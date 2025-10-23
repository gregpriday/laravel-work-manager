<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\Diff;

/**
 * Contract for domain order types: schema, plan, validation, idempotent apply.
 *
 * @api
 *
 * @see docs/guides/creating-order-types.md
 */
interface OrderType
{
    /**
     * Get the unique type identifier.
     */
    public function type(): string;

    /**
     * Get the JSON schema for this order type's payload.
     */
    public function schema(): array;

    /**
     * Plan the work order into discrete work items.
     * Returns an array of item configurations.
     */
    public function plan(WorkOrder $order): array;

    /**
     * Get the acceptance policy for validating submissions.
     */
    public function acceptancePolicy(): AcceptancePolicy;

    /**
     * Apply the approved work order (idempotent).
     * Returns a diff describing the changes.
     */
    public function apply(WorkOrder $order): Diff;
}
