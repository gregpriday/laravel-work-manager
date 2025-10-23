<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown by EnforceWorkOrderOnly middleware when direct mutation blocked.
 *
 * @see docs/concepts/security-and-permissions.md
 */
class ForbiddenDirectMutationException extends WorkManagerException
{
    public function __construct(string $message = 'Direct mutations must go through the work order system')
    {
        parent::__construct($message);
    }
}
