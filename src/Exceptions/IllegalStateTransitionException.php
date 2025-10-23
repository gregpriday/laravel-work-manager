<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown when an invalid state transition is attempted.
 *
 * @see docs/concepts/state-management.md
 */
class IllegalStateTransitionException extends WorkManagerException
{
    public function __construct(string $from, string $to, string $entityType = 'order')
    {
        parent::__construct(
            "Illegal state transition for {$entityType}: cannot transition from '{$from}' to '{$to}'"
        );
    }
}
