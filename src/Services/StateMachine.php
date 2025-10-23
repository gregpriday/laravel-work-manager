<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Exceptions\IllegalStateTransitionException;
use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Support\Facades\DB;

/**
 * Enforces state transitions and records lifecycle events atomically.
 * Invariants: validates transitions via config; state+event writes are transactional.
 *
 * @internal Service layer
 *
 * @see docs/concepts/state-management.md
 */
class StateMachine
{
    /**
     * Validate transition via config, update state+timestamps, record event; atomic.
     *
     * @throws IllegalStateTransitionException When transition not allowed
     */
    public function transitionOrder(
        WorkOrder $order,
        OrderState $newState,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null,
        ?array $diff = null
    ): WorkOrder {
        if (! $order->state->canTransitionTo($newState)) {
            throw new IllegalStateTransitionException(
                $order->state->value,
                $newState->value,
                'order'
            );
        }

        return DB::transaction(function () use ($order, $newState, $actorType, $actorId, $payload, $message, $diff) {
            $oldState = $order->state;

            $order->state = $newState;
            $order->last_transitioned_at = now();

            // Set special timestamps
            if ($newState === OrderState::APPLIED) {
                $order->applied_at = now();
            } elseif ($newState === OrderState::COMPLETED) {
                $order->completed_at = now();
            }

            $order->save();

            // Record the event
            $this->recordOrderEvent(
                $order,
                $this->mapStateToEvent($newState),
                $actorType,
                $actorId,
                $payload,
                $message,
                $diff
            );

            return $order;
        });
    }

    /**
     * Validate transition via config, update state+timestamps, record event, check order completion; atomic.
     *
     * @throws IllegalStateTransitionException When transition not allowed
     */
    public function transitionItem(
        WorkItem $item,
        ItemState $newState,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null
    ): WorkItem {
        if (! $item->state->canTransitionTo($newState)) {
            throw new IllegalStateTransitionException(
                $item->state->value,
                $newState->value,
                'item'
            );
        }

        return DB::transaction(function () use ($item, $newState, $actorType, $actorId, $payload, $message) {
            $item->state = $newState;

            // Set special timestamps
            if ($newState === ItemState::ACCEPTED) {
                $item->accepted_at = now();
            }

            $item->save();

            // Record the event
            $this->recordItemEvent(
                $item,
                $this->mapStateToEvent($newState),
                $actorType,
                $actorId,
                $payload,
                $message
            );

            // Check if order should be completed
            $this->checkOrderCompletion($item->order);

            return $item;
        });
    }

    /**
     * Create WorkEvent with order context (actor, payload, diff); append-only audit trail.
     */
    public function recordOrderEvent(
        WorkOrder $order,
        EventType $event,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null,
        ?array $diff = null
    ): WorkEvent {
        return WorkEvent::create([
            'order_id' => $order->id,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'payload' => $payload,
            'diff' => $diff,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * Create WorkEvent with item context (actor, payload); append-only audit trail.
     */
    public function recordItemEvent(
        WorkItem $item,
        EventType $event,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null
    ): WorkEvent {
        return WorkEvent::create([
            'order_id' => $item->order_id,
            'item_id' => $item->id,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'payload' => $payload,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * Check if an order should be marked as completed.
     */
    protected function checkOrderCompletion(WorkOrder $order): void
    {
        if ($order->state === OrderState::COMPLETED) {
            return;
        }

        if ($order->allItemsComplete()) {
            $this->transitionOrder(
                $order,
                OrderState::COMPLETED,
                ActorType::SYSTEM,
                null,
                null,
                'All items completed'
            );
        }
    }

    /**
     * Map state to event type.
     */
    protected function mapStateToEvent(OrderState|ItemState $state): EventType
    {
        return match ($state->value) {
            'queued' => EventType::PROPOSED,
            'checked_out' => EventType::CHECKED_OUT,
            'leased' => EventType::LEASED,
            'in_progress' => EventType::IN_PROGRESS,
            'submitted' => EventType::SUBMITTED,
            'approved' => EventType::APPROVED,
            'applied' => EventType::APPLIED,
            'accepted' => EventType::ACCEPTED,
            'rejected' => EventType::REJECTED,
            'completed' => EventType::COMPLETED,
            'failed' => EventType::FAILED,
            'dead_lettered' => EventType::DEAD_LETTERED,
            default => EventType::PROPOSED,
        };
    }
}
