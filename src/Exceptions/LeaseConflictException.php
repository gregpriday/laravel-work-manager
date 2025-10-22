<?php

namespace GregPriday\WorkManager\Exceptions;

class LeaseConflictException extends WorkManagerException
{
    public function __construct(string $message = 'Work item is already leased by another agent')
    {
        parent::__construct($message);
    }
}
