<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown when attempting to acquire a lease already held by another agent.
 *
 * @see docs/concepts/architecture-overview.md
 */
class LeaseConflictException extends WorkManagerException
{
    public function __construct(string $message = 'Work item is already leased by another agent')
    {
        parent::__construct($message);
    }
}
