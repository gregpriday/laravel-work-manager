<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work item result is submitted and validated.
 *
 * Payload: ['result' => array, 'evidence' => ?array, 'notes' => ?string]
 * Emitted by: WorkExecutor::submit()
 *
 * @see docs/reference/events-reference.md
 */
class WorkItemSubmitted extends WorkItemEvent
{
    //
}
