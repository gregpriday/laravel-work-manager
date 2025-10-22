<?php

namespace GregPriday\WorkManager\Contracts;

/**
 * Alternative interface for planner/generator implementations.
 * Similar to AllocatorStrategy but with a different semantic focus.
 */
interface PlannerPort
{
    /**
     * Generate work orders based on planning logic.
     * Returns an array of work order specifications.
     */
    public function generateOrders(): array;
}
