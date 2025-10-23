<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work order is planned into work items.
 *
 * Payload: ['item_count' => int] Number of items created
 * Emitted by: WorkAllocator::plan()
 *
 * @see docs/reference/events-reference.md
 */
class WorkOrderPlanned extends WorkOrderEvent
{
    //
}
