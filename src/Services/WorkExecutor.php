<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Contracts\OrderType;
use GregPriday\WorkManager\Events\WorkItemFailed;
use GregPriday\WorkManager\Events\WorkItemFinalized;
use GregPriday\WorkManager\Events\WorkItemPartRejected;
use GregPriday\WorkManager\Events\WorkItemPartSubmitted;
use GregPriday\WorkManager\Events\WorkItemPartValidated;
use GregPriday\WorkManager\Events\WorkItemSubmitted;
use GregPriday\WorkManager\Events\WorkOrderApplied;
use GregPriday\WorkManager\Events\WorkOrderApproved;
use GregPriday\WorkManager\Events\WorkOrderRejected;
use GregPriday\WorkManager\Exceptions\LeaseExpiredException;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkItemPart;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\Diff;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Support\PartStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkExecutor
{
    public function __construct(
        protected OrderTypeRegistry $registry,
        protected StateMachine $stateMachine
    ) {
    }

    /**
     * Submit a work item result.
     */
    public function submit(
        WorkItem $item,
        array $result,
        string $agentId,
        ?array $evidence = null,
        ?string $notes = null
    ): WorkItem {
        // Verify lease ownership
        if ($item->leased_by_agent_id !== $agentId) {
            throw new \Exception('Item is not leased by this agent');
        }

        if ($item->isLeaseExpired()) {
            throw new LeaseExpiredException();
        }

        $orderType = $this->registry->get($item->type);
        $policy = $orderType->acceptancePolicy();

        return DB::transaction(function () use ($item, $result, $agentId, $evidence, $notes, $policy) {
            try {
                // Validate the submission
                $policy->validateSubmission($item, $result);

                // Store the result
                $item->result = $result;
                $item->state = ItemState::SUBMITTED;
                $item->save();

                // Record event
                $this->stateMachine->recordItemEvent(
                    $item,
                    EventType::SUBMITTED,
                    ActorType::AGENT,
                    $agentId,
                    [
                        'result' => $result,
                        'evidence' => $evidence,
                        'notes' => $notes,
                    ]
                );

                event(new WorkItemSubmitted($item));

                // Check if order is ready for approval
                $this->checkAutoApproval($item->order);

                return $item->fresh();
            } catch (ValidationException $e) {
                // Store validation errors
                $item->error = [
                    'code' => 'validation_failed',
                    'message' => 'Submission validation failed',
                    'details' => $e->errors(),
                ];
                $item->save();

                throw $e;
            }
        });
    }

    /**
     * Approve a work order and apply it.
     */
    public function approve(
        WorkOrder $order,
        ?ActorType $actorType = null,
        ?string $actorId = null
    ): array {
        $orderType = $this->registry->get($order->type);
        $policy = $orderType->acceptancePolicy();

        // Check if ready for approval
        if (!$policy->readyForApproval($order)) {
            throw new \Exception('Order is not ready for approval');
        }

        return DB::transaction(function () use ($order, $orderType, $actorType, $actorId) {
            // Transition to approved
            $this->stateMachine->transitionOrder(
                $order,
                OrderState::APPROVED,
                $actorType ?? ActorType::SYSTEM,
                $actorId
            );

            event(new WorkOrderApproved($order));

            // Apply the changes
            $diff = $this->apply($order, $orderType);

            return [
                'order' => $order->fresh(),
                'diff' => $diff->toArray(),
            ];
        });
    }

    /**
     * Apply an approved work order (idempotent).
     */
    public function apply(WorkOrder $order, ?OrderType $orderType = null): Diff
    {
        if ($orderType === null) {
            $orderType = $this->registry->get($order->type);
        }

        return DB::transaction(function () use ($order, $orderType) {
            // Call beforeApply hook if using AbstractOrderType
            if (method_exists($orderType, 'beforeApply')) {
                $orderType->beforeApply($order);
            }

            // Execute the apply method
            $diff = $orderType->apply($order);

            // Transition to applied (this records the event with diff)
            $this->stateMachine->transitionOrder(
                $order,
                OrderState::APPLIED,
                ActorType::SYSTEM,
                null,
                null,
                'Changes applied successfully',
                $diff->toArray()
            );

            event(new WorkOrderApplied($order, $diff));

            // Transition all submitted items to accepted, then to completed
            $submittedItems = $order->items()->where('state', ItemState::SUBMITTED->value)->get();
            foreach ($submittedItems as $item) {
                // Transition to accepted
                $this->stateMachine->transitionItem(
                    $item,
                    ItemState::ACCEPTED,
                    ActorType::SYSTEM,
                    null,
                    null,
                    'Work order applied'
                );

                // Then transition to completed
                $this->stateMachine->transitionItem(
                    $item->fresh(),
                    ItemState::COMPLETED,
                    ActorType::SYSTEM,
                    null,
                    null,
                    'Work item completed'
                );
            }

            // Call afterApply hook if using AbstractOrderType
            if (method_exists($orderType, 'afterApply')) {
                $orderType->afterApply($order, $diff);
            }

            return $diff;
        });
    }

    /**
     * Reject a work order.
     */
    public function reject(
        WorkOrder $order,
        array $errors,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        bool $allowRework = false
    ): WorkOrder {
        return DB::transaction(function () use ($order, $errors, $actorType, $actorId, $allowRework) {
            $newState = $allowRework ? OrderState::QUEUED : OrderState::REJECTED;

            $this->stateMachine->transitionOrder(
                $order,
                $newState,
                $actorType ?? ActorType::SYSTEM,
                $actorId,
                ['errors' => $errors],
                'Order rejected'
            );

            event(new WorkOrderRejected($order));

            return $order->fresh();
        });
    }

    /**
     * Mark a work item as failed.
     */
    public function fail(WorkItem $item, array $error): WorkItem
    {
        return DB::transaction(function () use ($item, $error) {
            $item->state = ItemState::FAILED;
            $item->error = $error;
            $item->save();

            $this->stateMachine->recordItemEvent(
                $item,
                EventType::FAILED,
                ActorType::SYSTEM,
                null,
                ['error' => $error]
            );

            event(new WorkItemFailed($item));

            return $item;
        });
    }

    /**
     * Submit a work item part.
     */
    public function submitPart(
        WorkItem $item,
        string $partKey,
        ?int $seq,
        array $payload,
        string $agentId,
        ?array $evidence = null,
        ?string $notes = null
    ): WorkItemPart {
        // Verify lease ownership
        if ($item->leased_by_agent_id !== $agentId) {
            throw new \Exception('Item is not leased by this agent');
        }

        if ($item->isLeaseExpired()) {
            throw new LeaseExpiredException();
        }

        $orderType = $this->registry->get($item->type);

        try {
            return DB::transaction(function () use ($item, $partKey, $seq, $payload, $agentId, $evidence, $notes, $orderType) {
                // Run validation rules
                $rules = $orderType->partialRules($item, $partKey, $seq);

                if (!empty($rules)) {
                    $validator = validator($payload, $rules);

                    if ($validator->fails()) {
                        throw new ValidationException($validator);
                    }
                }

                // Run custom validation hook
                $orderType->afterValidatePart($item, $partKey, $payload, $seq);

                // Create or update the part
                $part = WorkItemPart::updateOrCreate(
                    [
                        'work_item_id' => $item->id,
                        'part_key' => $partKey,
                        'seq' => $seq,
                    ],
                    [
                        'status' => PartStatus::VALIDATED,
                        'payload' => $payload,
                        'evidence' => $evidence,
                        'notes' => $notes,
                        'errors' => null,
                        'checksum' => hash('sha256', json_encode($payload)),
                        'submitted_by_agent_id' => $agentId,
                    ]
                );

                // Update parts_state on the item
                $this->updatePartsState($item);

                // Record events
                event(new WorkItemPartSubmitted($part));
                event(new WorkItemPartValidated($part));

                $this->stateMachine->recordItemEvent(
                    $item,
                    EventType::SUBMITTED,
                    ActorType::AGENT,
                    $agentId,
                    [
                        'part_key' => $partKey,
                        'seq' => $seq,
                        'evidence' => $evidence,
                        'notes' => $notes,
                    ]
                );

                return $part->fresh();
            });
        } catch (ValidationException $e) {
            // Store validation errors in a rejected part (outside transaction)
            $part = WorkItemPart::updateOrCreate(
                [
                    'work_item_id' => $item->id,
                    'part_key' => $partKey,
                    'seq' => $seq,
                ],
                [
                    'status' => PartStatus::REJECTED,
                    'payload' => $payload,
                    'evidence' => $evidence,
                    'notes' => $notes,
                    'errors' => ['validation' => $e->errors()],
                    'submitted_by_agent_id' => $agentId,
                ]
            );

            // Update parts_state for rejected parts too
            $this->updatePartsState($item->fresh());

            event(new WorkItemPartRejected($part));

            throw $e;
        }
    }

    /**
     * Finalize a work item by assembling all parts.
     */
    public function finalizeItem(
        WorkItem $item,
        string $mode = 'strict'
    ): WorkItem {
        $orderType = $this->registry->get($item->type);

        return DB::transaction(function () use ($item, $mode, $orderType) {
            // Check required parts in strict mode
            if ($mode === 'strict') {
                $requiredParts = $orderType->requiredParts($item);
                $submittedKeys = $item->parts()
                    ->where('status', PartStatus::VALIDATED->value)
                    ->distinct()
                    ->pluck('part_key')
                    ->toArray();

                $missingParts = array_diff($requiredParts, $submittedKeys);

                if (!empty($missingParts)) {
                    throw ValidationException::withMessages([
                        'parts' => ['Missing required parts: ' . implode(', ', $missingParts)],
                    ]);
                }
            }

            // Get latest validated parts per key
            $latestParts = $item->parts()
                ->where('status', PartStatus::VALIDATED->value)
                ->whereIn('id', function ($query) use ($item) {
                    $query->selectRaw('MAX(id)')
                        ->from('work_item_parts')
                        ->where('work_item_id', $item->id)
                        ->where('status', PartStatus::VALIDATED->value)
                        ->groupBy('part_key');
                })
                ->get();

            // Assemble parts
            $assembled = $orderType->assemble($item, $latestParts);

            // Validate assembled result
            $orderType->validateAssembled($item, $assembled);

            // Store assembled result and set as item result
            $item->assembled_result = $assembled;
            $item->result = $assembled;
            $item->state = ItemState::SUBMITTED;
            $item->save();

            // Record event
            $this->stateMachine->recordItemEvent(
                $item,
                EventType::SUBMITTED,
                ActorType::AGENT,
                $item->leased_by_agent_id,
                [
                    'assembled' => true,
                    'parts_count' => $latestParts->count(),
                ]
            );

            event(new WorkItemFinalized($item));

            // Check if order should transition to submitted
            $this->checkAutoApproval($item->order);

            return $item->fresh();
        });
    }

    /**
     * Update the parts_state materialized view on a work item.
     */
    protected function updatePartsState(WorkItem $item): void
    {
        $partsState = [];

        $latestParts = $item->parts()
            ->whereIn('id', function ($query) use ($item) {
                $query->selectRaw('MAX(id)')
                    ->from('work_item_parts')
                    ->where('work_item_id', $item->id)
                    ->groupBy('part_key');
            })
            ->get();

        foreach ($latestParts as $part) {
            $partsState[$part->part_key] = [
                'status' => $part->status->value,
                'seq' => $part->seq,
                'checksum' => $part->checksum,
                'submitted_at' => $part->created_at->toIso8601String(),
            ];
        }

        $item->parts_state = $partsState;
        $item->save();
    }

    /**
     * Check if order should be transitioned to submitted state, and if ready for auto-approval.
     */
    protected function checkAutoApproval(WorkOrder $order): void
    {
        $order = $order->fresh();

        // Check if all items are submitted and order should transition to SUBMITTED state
        if ($order->state === OrderState::QUEUED) {
            $allItemsSubmitted = $order->items()
                ->whereNotIn('state', [ItemState::SUBMITTED->value, ItemState::ACCEPTED->value, ItemState::COMPLETED->value])
                ->doesntExist();

            if ($allItemsSubmitted) {
                $this->stateMachine->transitionOrder(
                    $order,
                    OrderState::SUBMITTED,
                    ActorType::SYSTEM,
                    null,
                    null,
                    'All items submitted'
                );
            }
        }

        $orderType = $this->registry->get($order->type);
        $policy = $orderType->acceptancePolicy();

        // Check if order is ready and type allows auto-approval
        if ($policy->readyForApproval($order) && $orderType->shouldAutoApprove()) {
            try {
                $this->approve($order, ActorType::SYSTEM, null);
            } catch (\Exception $e) {
                // Log auto-approval failure but don't throw
                // Order will remain in submitted state for manual review
                \Log::warning('Auto-approval failed for order', [
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
