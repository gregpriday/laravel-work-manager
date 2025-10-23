<?php

namespace GregPriday\WorkManager\Events;

/**
 * Emitted when an agent sends a heartbeat to extend lease TTL.
 *
 * Payload: ['lease_expires_at' => string] ISO-8601 timestamp
 * Emitted by: LeaseService::extend()
 *
 * @see docs/reference/events-reference.md
 */
class WorkItemHeartbeat extends WorkItemEvent
{
    //
}
