<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work item fails validation or execution.
 *
 * Payload: ['error' => array{code:string,message:string}]
 * Emitted by: WorkExecutor::fail()
 *
 * @see docs/reference/events-reference.md
 */
class WorkItemFailed extends WorkItemEvent
{
    //
}
