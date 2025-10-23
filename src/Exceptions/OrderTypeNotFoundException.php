<?php

namespace GregPriday\WorkManager\Exceptions;

/**
 * Thrown when referencing an unregistered order type.
 *
 * @see docs/guides/creating-order-types.md
 */
class OrderTypeNotFoundException extends WorkManagerException
{
    public function __construct(string $type)
    {
        parent::__construct("Order type '{$type}' is not registered");
    }
}
