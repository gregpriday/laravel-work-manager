<?php

namespace GregPriday\WorkManager\Mcp;

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\IdempotencyService;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Support\Facades\Auth;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

/**
 * MCP Tools for Laravel Work Manager.
 * Provides AI agents with access to the work order control plane.
 */
class WorkManagerTools
{
    public function __construct(
        protected WorkAllocator $allocator,
        protected WorkExecutor $executor,
        protected LeaseService $leaseService,
        protected IdempotencyService $idempotency
    ) {
    }

    /**
     * Propose a new work order.
     *
     * Creates a new work order of the specified type with the given payload.
     * The order will be automatically planned into work items based on the type's plan() method.
     */
    #[McpTool(
        name: 'work.propose',
        description: 'Create a new work order to be processed by agents'
    )]
    public function propose(
        #[Schema(description: 'The type of work order (e.g., "user.data.sync", "database.record.insert")')]
        string $type,

        #[Schema(description: 'The payload data for this work order (must match the type schema)')]
        array $payload,

        #[Schema(description: 'Optional metadata for the work order')]
        ?array $meta = null,

        #[Schema(description: 'Priority level (higher = more important)', minimum: 0, maximum: 100)]
        int $priority = 0,

        #[Schema(description: 'Idempotency key to prevent duplicate proposals')]
        ?string $idempotencyKey = null
    ): array {
        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check('propose:' . $type, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $order = $this->allocator->propose(
            type: $type,
            payload: $payload,
            requestedByType: ActorType::AGENT,
            requestedById: $this->getAgentId(),
            meta: $meta,
            priority: $priority
        );

        $result = [
            'success' => true,
            'order' => [
                'id' => $order->id,
                'type' => $order->type,
                'state' => $order->state->value,
                'priority' => $order->priority,
                'payload' => $order->payload,
                'meta' => $order->meta,
                'created_at' => $order->created_at->toIso8601String(),
            ],
            'items_count' => $order->items()->count(),
        ];

        // Store in idempotency cache if key provided
        if ($idempotencyKey) {
            $this->idempotency->store('propose:' . $type, $idempotencyKey, $result);
        }

        return $result;
    }

    /**
     * List work orders with optional filtering.
     *
     * Returns a list of work orders that match the filter criteria.
     * Useful for discovering available work or checking order status.
     */
    #[McpTool(
        name: 'work.list',
        description: 'List work orders with optional filtering by state, type, or other criteria'
    )]
    public function list(
        #[Schema(description: 'Filter by order state', enum: ['queued', 'checked_out', 'in_progress', 'submitted', 'approved', 'applied', 'completed', 'rejected', 'failed'])]
        ?string $state = null,

        #[Schema(description: 'Filter by order type')]
        ?string $type = null,

        #[Schema(description: 'Maximum number of results', minimum: 1, maximum: 100)]
        int $limit = 20
    ): array {
        $query = WorkOrder::query()->with(['items']);

        if ($state) {
            $query->inState($state);
        }

        if ($type) {
            $query->ofType($type);
        }

        $orders = $query->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        return [
            'success' => true,
            'count' => $orders->count(),
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'type' => $order->type,
                'state' => $order->state->value,
                'priority' => $order->priority,
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at->toIso8601String(),
                'last_transitioned_at' => $order->last_transitioned_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Get detailed information about a specific work order.
     *
     * Returns complete details about the order including all items and recent events.
     */
    #[McpTool(
        name: 'work.get',
        description: 'Get detailed information about a specific work order'
    )]
    public function get(
        #[Schema(description: 'The UUID of the work order')]
        string $orderId
    ): array {
        $order = WorkOrder::with(['items', 'events' => function ($query) {
            $query->latest()->limit(20);
        }])->findOrFail($orderId);

        return [
            'success' => true,
            'order' => [
                'id' => $order->id,
                'type' => $order->type,
                'state' => $order->state->value,
                'priority' => $order->priority,
                'payload' => $order->payload,
                'meta' => $order->meta,
                'requested_by_type' => $order->requested_by_type?->value,
                'requested_by_id' => $order->requested_by_id,
                'created_at' => $order->created_at->toIso8601String(),
                'last_transitioned_at' => $order->last_transitioned_at?->toIso8601String(),
                'applied_at' => $order->applied_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
            ],
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'state' => $item->state->value,
                'attempts' => $item->attempts,
                'max_attempts' => $item->max_attempts,
                'leased_by_agent_id' => $item->leased_by_agent_id,
                'lease_expires_at' => $item->lease_expires_at?->toIso8601String(),
                'input' => $item->input,
                'result' => $item->result,
                'error' => $item->error,
            ])->toArray(),
            'recent_events' => $order->events->map(fn ($event) => [
                'event' => $event->event->value,
                'actor_type' => $event->actor_type?->value,
                'message' => $event->message,
                'created_at' => $event->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Checkout the next available work item for processing.
     *
     * Acquires a lease on the next available work item from the specified order.
     * The lease must be maintained with heartbeats or it will expire.
     */
    #[McpTool(
        name: 'work.checkout',
        description: 'Checkout (lease) the next available work item from an order'
    )]
    public function checkout(
        #[Schema(description: 'The UUID of the work order')]
        string $orderId,

        #[Schema(description: 'Agent identifier for lease tracking')]
        ?string $agentId = null
    ): array {
        $agentId = $agentId ?? $this->getAgentId();
        $order = WorkOrder::findOrFail($orderId);

        // Get next available item
        $item = $this->leaseService->getNextAvailable($order->id);

        if (!$item) {
            return [
                'success' => false,
                'error' => 'No items available for checkout',
                'code' => 'no_items_available',
            ];
        }

        try {
            $item = $this->leaseService->acquire($item->id, $agentId);

            return [
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'type' => $item->type,
                    'input' => $item->input,
                    'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
                    'heartbeat_every_seconds' => config('work-manager.lease.heartbeat_every_seconds'),
                    'max_attempts' => $item->max_attempts,
                    'current_attempt' => $item->attempts + 1,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'lease_conflict',
            ];
        }
    }

    /**
     * Send a heartbeat to extend the lease on a work item.
     *
     * Must be called periodically to maintain the lease. If the lease expires,
     * the item will be reclaimed and made available to other agents.
     */
    #[McpTool(
        name: 'work.heartbeat',
        description: 'Extend the lease on a work item by sending a heartbeat'
    )]
    public function heartbeat(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'Agent identifier (must match the lease holder)')]
        ?string $agentId = null
    ): array {
        $agentId = $agentId ?? $this->getAgentId();

        try {
            $item = $this->leaseService->extend($itemId, $agentId);

            return [
                'success' => true,
                'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
                'heartbeat_every_seconds' => config('work-manager.lease.heartbeat_every_seconds'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'lease_error',
            ];
        }
    }

    /**
     * Submit completed work item results.
     *
     * Submits the results of processing a work item. The submission will be validated
     * against the order type's acceptance policy before being accepted.
     */
    #[McpTool(
        name: 'work.submit',
        description: 'Submit the results of a completed work item'
    )]
    public function submit(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'The result data from processing this work item')]
        array $result,

        #[Schema(description: 'Optional evidence/verification data')]
        ?array $evidence = null,

        #[Schema(description: 'Optional notes about the work performed')]
        ?string $notes = null,

        #[Schema(description: 'Agent identifier (must match the lease holder)')]
        ?string $agentId = null,

        #[Schema(description: 'Idempotency key to prevent duplicate submissions')]
        ?string $idempotencyKey = null
    ): array {
        $agentId = $agentId ?? $this->getAgentId();

        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check('submit:item:' . $itemId, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $item = WorkItem::findOrFail($itemId);
            $item = $this->executor->submit($item, $result, $agentId, $evidence, $notes);

            $response = [
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'state' => $item->state->value,
                    'result' => $item->result,
                ],
                'order_state' => $item->order->state->value,
            ];

            // Store in idempotency cache if key provided
            if ($idempotencyKey) {
                $this->idempotency->store('submit:item:' . $itemId, $idempotencyKey, $response);
            }

            return $response;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'code' => 'validation_failed',
                'details' => $e->errors(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'submit_error',
            ];
        }
    }

    /**
     * Approve a work order and apply the changes.
     *
     * Approves a submitted work order and executes the apply() method to make
     * the actual changes. Returns a diff describing what was changed.
     */
    #[McpTool(
        name: 'work.approve',
        description: 'Approve a work order and apply the changes'
    )]
    public function approve(
        #[Schema(description: 'The UUID of the work order')]
        string $orderId,

        #[Schema(description: 'Idempotency key to prevent duplicate approvals')]
        ?string $idempotencyKey = null
    ): array {
        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check('approve:order:' . $orderId, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $order = WorkOrder::findOrFail($orderId);
            $result = $this->executor->approve($order, ActorType::USER, Auth::id());

            $response = [
                'success' => true,
                'order' => [
                    'id' => $result['order']->id,
                    'state' => $result['order']->state->value,
                ],
                'diff' => $result['diff'],
            ];

            // Store in idempotency cache if key provided
            if ($idempotencyKey) {
                $this->idempotency->store('approve:order:' . $orderId, $idempotencyKey, $response);
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'approve_error',
            ];
        }
    }

    /**
     * Reject a work order.
     *
     * Rejects a work order with specified errors. Optionally allows rework by
     * transitioning the order back to queued state.
     */
    #[McpTool(
        name: 'work.reject',
        description: 'Reject a work order with error details'
    )]
    public function reject(
        #[Schema(description: 'The UUID of the work order')]
        string $orderId,

        #[Schema(description: 'Array of error details describing why the order was rejected')]
        array $errors,

        #[Schema(description: 'Allow the order to be reworked (transition back to queued)')]
        bool $allowRework = false,

        #[Schema(description: 'Idempotency key to prevent duplicate rejections')]
        ?string $idempotencyKey = null
    ): array {
        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check('reject:order:' . $orderId, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $order = WorkOrder::findOrFail($orderId);
            $order = $this->executor->reject($order, $errors, ActorType::USER, Auth::id(), $allowRework);

            $response = [
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'state' => $order->state->value,
                ],
            ];

            // Store in idempotency cache if key provided
            if ($idempotencyKey) {
                $this->idempotency->store('reject:order:' . $orderId, $idempotencyKey, $response);
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'reject_error',
            ];
        }
    }

    /**
     * Release a lease on a work item.
     *
     * Explicitly releases the lease on a work item, making it available for other agents.
     * Useful when an agent cannot complete the work.
     */
    #[McpTool(
        name: 'work.release',
        description: 'Release the lease on a work item'
    )]
    public function release(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'Agent identifier (must match the lease holder)')]
        ?string $agentId = null
    ): array {
        $agentId = $agentId ?? $this->getAgentId();

        try {
            $item = $this->leaseService->release($itemId, $agentId);

            return [
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'state' => $item->state->value,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'release_error',
            ];
        }
    }

    /**
     * Get logs and events for a work item or order.
     *
     * Returns the event history showing state transitions, submissions, and other
     * important events in the lifecycle of the work.
     */
    #[McpTool(
        name: 'work.logs',
        description: 'Get event logs for a work item or order'
    )]
    public function logs(
        #[Schema(description: 'The UUID of the work item (optional if order_id provided)')]
        ?string $itemId = null,

        #[Schema(description: 'The UUID of the work order (optional if item_id provided)')]
        ?string $orderId = null,

        #[Schema(description: 'Maximum number of events to return', minimum: 1, maximum: 100)]
        int $limit = 50
    ): array {
        if (!$itemId && !$orderId) {
            return [
                'success' => false,
                'error' => 'Either item_id or order_id must be provided',
                'code' => 'missing_parameter',
            ];
        }

        $query = WorkEvent::query();

        if ($itemId) {
            $query->where(function ($q) use ($itemId) {
                $q->where('item_id', $itemId)
                    ->orWhere(function ($subQ) use ($itemId) {
                        $item = WorkItem::find($itemId);
                        if ($item) {
                            $subQ->where('order_id', $item->order_id)->whereNull('item_id');
                        }
                    });
            });
        } elseif ($orderId) {
            $query->where('order_id', $orderId);
        }

        $events = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return [
            'success' => true,
            'count' => $events->count(),
            'events' => $events->map(fn ($event) => [
                'id' => $event->id,
                'order_id' => $event->order_id,
                'item_id' => $event->item_id,
                'event' => $event->event->value,
                'actor_type' => $event->actor_type?->value,
                'actor_id' => $event->actor_id,
                'message' => $event->message,
                'payload' => $event->payload,
                'diff' => $event->diff,
                'created_at' => $event->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Submit a work item part (partial submission).
     *
     * Allows agents to incrementally submit parts of a work item result.
     * Each part is validated independently before being stored.
     */
    #[McpTool(
        name: 'work.submit_part',
        description: 'Submit a partial result for a work item'
    )]
    public function submitPart(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'The key identifying this part (e.g., "identity", "contacts")')]
        string $partKey,

        #[Schema(description: 'The partial result payload for this part')]
        array $payload,

        #[Schema(description: 'Optional sequence number for ordered chunks of the same key')]
        ?int $seq = null,

        #[Schema(description: 'Optional evidence/verification data')]
        ?array $evidence = null,

        #[Schema(description: 'Optional notes about this part')]
        ?string $notes = null,

        #[Schema(description: 'Agent identifier (must match the lease holder)')]
        ?string $agentId = null,

        #[Schema(description: 'Idempotency key to prevent duplicate submissions')]
        ?string $idempotencyKey = null
    ): array {
        $agentId = $agentId ?? $this->getAgentId();

        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check(
                'submit-part:item:' . $itemId . ':' . $partKey . ':' . ($seq ?? 'null'),
                $idempotencyKey
            );
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $item = WorkItem::findOrFail($itemId);
            $part = $this->executor->submitPart($item, $partKey, $seq, $payload, $agentId, $evidence, $notes);

            $response = [
                'success' => true,
                'part' => [
                    'id' => $part->id,
                    'part_key' => $part->part_key,
                    'seq' => $part->seq,
                    'status' => $part->status->value,
                ],
                'item_parts_state' => $item->fresh()->parts_state,
            ];

            // Store in idempotency cache if key provided
            if ($idempotencyKey) {
                $this->idempotency->store(
                    'submit-part:item:' . $itemId . ':' . $partKey . ':' . ($seq ?? 'null'),
                    $idempotencyKey,
                    $response
                );
            }

            return $response;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'code' => 'validation_failed',
                'details' => $e->errors(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'submit_part_error',
            ];
        }
    }

    /**
     * List work item parts.
     *
     * Returns all parts submitted for a work item, optionally filtered by part key.
     */
    #[McpTool(
        name: 'work.list_parts',
        description: 'List all parts submitted for a work item'
    )]
    public function listParts(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'Optional filter by part key')]
        ?string $partKey = null
    ): array {
        try {
            $item = WorkItem::with('parts')->findOrFail($itemId);

            $query = $item->parts();

            if ($partKey) {
                $query->where('part_key', $partKey);
            }

            $parts = $query->orderBy('part_key')
                ->orderByDesc('seq')
                ->orderByDesc('created_at')
                ->get();

            return [
                'success' => true,
                'count' => $parts->count(),
                'parts' => $parts->map(fn ($part) => [
                    'id' => $part->id,
                    'part_key' => $part->part_key,
                    'seq' => $part->seq,
                    'status' => $part->status->value,
                    'payload' => $part->payload,
                    'evidence' => $part->evidence,
                    'notes' => $part->notes,
                    'errors' => $part->errors,
                    'checksum' => $part->checksum,
                    'submitted_by_agent_id' => $part->submitted_by_agent_id,
                    'created_at' => $part->created_at->toIso8601String(),
                ])->toArray(),
                'parts_state' => $item->parts_state,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'list_parts_error',
            ];
        }
    }

    /**
     * Finalize a work item by assembling all parts.
     *
     * Validates that all required parts are present, assembles them into a complete result,
     * and transitions the item to submitted state.
     */
    #[McpTool(
        name: 'work.finalize',
        description: 'Finalize a work item by assembling all submitted parts'
    )]
    public function finalize(
        #[Schema(description: 'The UUID of the work item')]
        string $itemId,

        #[Schema(description: 'Validation mode', enum: ['strict', 'best_effort'])]
        string $mode = 'strict',

        #[Schema(description: 'Idempotency key to prevent duplicate finalizations')]
        ?string $idempotencyKey = null
    ): array {
        // Use idempotency if key provided
        if ($idempotencyKey) {
            $cached = $this->idempotency->check('finalize:item:' . $itemId, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $item = WorkItem::findOrFail($itemId);
            $item = $this->executor->finalizeItem($item, $mode);

            $response = [
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'state' => $item->state->value,
                    'assembled_result' => $item->assembled_result,
                ],
                'order_state' => $item->order->state->value,
            ];

            // Store in idempotency cache if key provided
            if ($idempotencyKey) {
                $this->idempotency->store('finalize:item:' . $itemId, $idempotencyKey, $response);
            }

            return $response;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'code' => 'validation_failed',
                'details' => $e->errors(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'finalize_error',
            ];
        }
    }

    /**
     * Get the current agent ID from the session or generate one.
     */
    protected function getAgentId(): string
    {
        return request()->header('X-Agent-ID')
            ?? Auth::id()
            ?? 'mcp-agent-' . uniqid();
    }
}
