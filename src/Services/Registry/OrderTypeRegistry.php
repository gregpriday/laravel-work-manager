<?php

namespace GregPriday\WorkManager\Services\Registry;

use GregPriday\WorkManager\Contracts\OrderType;
use GregPriday\WorkManager\Exceptions\OrderTypeNotFoundException;

/**
 * Central registry for order type implementations (keyed by type string).
 *
 * @internal Service layer
 *
 * @see docs/guides/creating-order-types.md
 */
class OrderTypeRegistry
{
    /**
     * @var array<string, OrderType>
     */
    protected array $types = [];

    /**
     * Register an order type.
     */
    public function register(OrderType $type): void
    {
        $this->types[$type->type()] = $type;
    }

    /**
     * Get an order type by its identifier.
     */
    public function get(string $type): OrderType
    {
        if (! isset($this->types[$type])) {
            throw new OrderTypeNotFoundException($type);
        }

        return $this->types[$type];
    }

    /**
     * Check if a type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Get all registered types.
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Get all registered type names.
     */
    public function names(): array
    {
        return array_keys($this->types);
    }
}
