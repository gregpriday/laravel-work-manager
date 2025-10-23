<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when partial submissions are assembled into final result.
 *
 * Payload: ['assembled' => true, 'parts_count' => int]
 * Emitted by: WorkExecutor::finalizeItem()
 *
 * @see docs/guides/partial-submissions.md
 */
class WorkItemFinalized extends WorkItemEvent
{
    //
}
