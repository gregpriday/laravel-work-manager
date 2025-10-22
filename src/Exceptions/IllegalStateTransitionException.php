<?php

namespace GregPriday\WorkManager\Exceptions;

class IllegalStateTransitionException extends WorkManagerException
{
    public function __construct(string $from, string $to, string $entityType = 'order')
    {
        parent::__construct(
            "Illegal state transition for {$entityType}: cannot transition from '{$from}' to '{$to}'"
        );
    }
}
