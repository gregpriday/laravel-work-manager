<?php

namespace GregPriday\WorkManager\Exceptions;

class ForbiddenDirectMutationException extends WorkManagerException
{
    public function __construct(string $message = 'Direct mutations must go through the work order system')
    {
        parent::__construct($message);
    }
}
