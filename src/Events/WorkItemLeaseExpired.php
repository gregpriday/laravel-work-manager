<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work item lease expires (TTL exceeded without heartbeat).
 *
 * Payload: ['attempts' => int] Current attempt count after increment
 * Emitted by: LeaseService::reclaimExpired()
 *
 * @see docs/reference/events-reference.md
 */
class WorkItemLeaseExpired extends WorkItemEvent
{
    //
}
