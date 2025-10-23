<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work order is proposed and validated.
 *
 * Payload: array<string,mixed> The validated payload
 * Emitted by: WorkAllocator::propose()
 *
 * @see docs/reference/events-reference.md
 */
class WorkOrderProposed extends WorkOrderEvent
{
    //
}
