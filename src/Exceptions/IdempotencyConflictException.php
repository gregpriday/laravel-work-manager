<?php

namespace GregPriday\WorkManager\Exceptions;

class IdempotencyConflictException extends WorkManagerException
{
    public function __construct(
        string $message = 'Idempotency key conflict detected',
        public readonly ?array $previousResponse = null
    ) {
        parent::__construct($message);
    }
}
