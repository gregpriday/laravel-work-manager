<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown when idempotency key collision detected (includes cached response).
 *
 * @property-read array|null $previousResponse
 *
 * @see docs/concepts/architecture-overview.md
 */
class IdempotencyConflictException extends WorkManagerException
{
    public function __construct(
        string $message = 'Idempotency key conflict detected',
        public readonly ?array $previousResponse = null
    ) {
        parent::__construct($message);
    }
}
