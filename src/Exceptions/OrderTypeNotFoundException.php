<?php

namespace GregPriday\WorkManager\Exceptions;

class OrderTypeNotFoundException extends WorkManagerException
{
    public function __construct(string $type)
    {
        parent::__construct("Order type '{$type}' is not registered");
    }
}
