<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Contracts\OrderType;
use GregPriday\WorkManager\Events\WorkOrderPlanned;
use GregPriday\WorkManager\Events\WorkOrderProposed;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\Helpers;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates work order proposal and planning into work items.
 * Invariants: validates payload against schema; transitions are transactional.
 *
 * @internal Service layer
 *
 * @see docs/concepts/architecture-overview.md
 */
class WorkAllocator
{
    public function __construct(
        protected OrderTypeRegistry $registry,
        protected StateMachine $stateMachine
    ) {}

    /**
     * Validate payload against schema, create QUEUED order, record PROPOSED event, plan items.
     *
     * @param  array<string,mixed>  $payload  Must match type's schema
     * @return WorkOrder Fresh order with items preloaded
     *
     * @throws \Illuminate\Validation\ValidationException When payload invalid
     */
    public function propose(
        string $type,
        array $payload,
        ?ActorType $requestedByType = null,
        ?string $requestedById = null,
        ?array $meta = null,
        int $priority = 0
    ): WorkOrder {
        $orderType = $this->registry->get($type);

        // Validate payload against schema
        $errors = Helpers::validateJsonSchema($payload, $orderType->schema());

        if (! empty($errors)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['errors' => $errors], 422)
            );
        }

        return DB::transaction(function () use ($type, $payload, $requestedByType, $requestedById, $meta, $priority, $orderType) {
            // Create the order
            $order = WorkOrder::create([
                'id' => Helpers::uuid(),
                'type' => $type,
                'state' => OrderState::QUEUED,
                'priority' => $priority,
                'requested_by_type' => $requestedByType,
                'requested_by_id' => $requestedById,
                'payload' => $payload,
                'meta' => $meta,
                'acceptance_config' => $orderType->schema(),
                'last_transitioned_at' => now(),
            ]);

            // Record event
            $this->stateMachine->recordOrderEvent(
                $order,
                EventType::PROPOSED,
                $requestedByType,
                $requestedById,
                $payload
            );

            event(new WorkOrderProposed($order));

            // Plan the work (create items)
            $this->plan($order, $orderType);

            return $order->fresh();
        });
    }

    /**
     * Call type's plan() to create QUEUED items, record PLANNED event; atomic.
     */
    public function plan(WorkOrder $order, ?OrderType $orderType = null): void
    {
        if ($orderType === null) {
            $orderType = $this->registry->get($order->type);
        }

        $itemsConfig = $orderType->plan($order);

        DB::transaction(function () use ($order, $itemsConfig) {
            foreach ($itemsConfig as $itemConfig) {
                WorkItem::create([
                    'id' => Helpers::uuid(),
                    'order_id' => $order->id,
                    'type' => $itemConfig['type'] ?? $order->type,
                    'state' => ItemState::QUEUED,
                    'input' => $itemConfig['input'],
                    'max_attempts' => $itemConfig['max_attempts'] ?? config('work-manager.retry.default_max_attempts'),
                    'attempts' => 0,
                ]);
            }

            // Record event
            $this->stateMachine->recordOrderEvent(
                $order,
                EventType::PLANNED,
                ActorType::SYSTEM,
                null,
                ['item_count' => count($itemsConfig)]
            );

            event(new WorkOrderPlanned($order));
        });
    }
}
