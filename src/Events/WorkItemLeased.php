<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when a work item lease is acquired by an agent.
 *
 * Payload: ['lease_expires_at' => string] ISO-8601 timestamp
 * Emitted by: LeaseService::acquire()
 *
 * @see docs/reference/events-reference.md
 */
class WorkItemLeased extends WorkItemEvent
{
    //
}
