<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work order is rejected.
 *
 * Payload: ['errors' => array] Structured rejection errors
 * Emitted by: WorkExecutor::reject()
 *
 * @see docs/reference/events-reference.md
 */
class WorkOrderRejected extends WorkOrderEvent
{
    //
}
