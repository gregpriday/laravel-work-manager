<?php

namespace GregPriday\WorkManager\Contracts;

/**
 * Alternative to AllocatorStrategy for generating work orders.
 *
 * @api
 *
 * @see docs/concepts/architecture-overview.md
 */
interface PlannerPort
{
    /**
     * Generate work orders based on planning logic.
     * Returns an array of work order specifications.
     */
    public function generateOrders(): array;
}
