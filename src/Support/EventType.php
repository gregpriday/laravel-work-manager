<?php

namespace GregPriday\WorkManager\Support;

/**
 * Event types for audit trail (lifecycle transitions and actions).
 *
 * @see docs/reference/events-reference.md
 */
enum EventType: string
{
    case PROPOSED = 'proposed';
    case PLANNED = 'planned';
    case CHECKED_OUT = 'checked_out';
    case LEASED = 'leased';
    case IN_PROGRESS = 'in_progress';
    case HEARTBEAT = 'heartbeat';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case APPROVED = 'approved';
    case APPLIED = 'applied';
    case REJECTED = 'rejected';
    case LEASE_EXPIRED = 'lease_expired';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case DEAD_LETTERED = 'dead_lettered';
    case RELEASED = 'released';
}
