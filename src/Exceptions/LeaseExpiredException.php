<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown when operating on a work item with expired lease.
 *
 * @see docs/concepts/architecture-overview.md
 */
class LeaseExpiredException extends WorkManagerException
{
    public function __construct(string $message = 'The lease on this work item has expired')
    {
        parent::__construct($message);
    }
}
