<?php

namespace GregPriday\WorkManager\Exceptions;

class LeaseExpiredException extends WorkManagerException
{
    public function __construct(string $message = 'The lease on this work item has expired')
    {
        parent::__construct($message);
    }
}
