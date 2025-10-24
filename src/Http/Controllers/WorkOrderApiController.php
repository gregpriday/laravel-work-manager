<?php

namespace GregPriday\WorkManager\Http\Controllers;

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\IdempotencyService;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * HTTP endpoints for work orders (propose/checkout/submit/approve/reject/release).
 *
 * @internal Maps requests to services; auth/idempotency via middleware.
 *
 * @see docs/guides/http-api.md
 */
class WorkOrderApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected WorkAllocator $allocator,
        protected WorkExecutor $executor,
        protected LeaseService $leaseService,
        protected IdempotencyService $idempotency
    ) {}

    /**
     * Propose a new work order.
     */
    public function propose(Request $request): JsonResponse
    {
        $this->authorize('propose', WorkOrder::class);

        $validated = $request->validate([
            'type' => 'required|string|max:120',
            'payload' => 'required|array',
            'meta' => 'nullable|array',
            'priority' => 'nullable|integer',
        ]);

        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('propose') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'propose:'.$validated['type'],
                $idempotencyKey,
                fn () => $this->createOrder($validated)
            );

            return response()->json($result, 201);
        }

        $result = $this->createOrder($validated);

        return response()->json($result, 201);
    }

    /**
     * List work orders with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        // Normalize simple filter parameters to Spatie's filter[] format for backward compatibility
        // Support both ?state=queued and ?filter[state]=queued
        $normalizedRequest = $this->normalizeFilterParameters($request);

        // Build from request using centralized query builder (supports query string OR JSON body)
        $qb = \GregPriday\WorkManager\Support\WorkOrderQuery::make($normalizedRequest);

        // Pagination via page[size] or limit (alias), page[number]
        // Support both 'limit' and 'per_page' as aliases for 'page.size'
        $requested = (int) ($request->input('limit') ?? $request->input('per_page') ?? $request->input('page.size') ?? config('work-manager.query.default_page_size_http', 50));
        $size = max(1, min($requested, config('work-manager.query.max_page_size', 100)));
        $pageNumber = max(1, (int) ($request->input('page.number') ?? 1));

        $orders = $qb->paginate($size, ['*'], 'page', $pageNumber)->appends($request->query());

        return response()->json($orders);
    }

    /**
     * Normalize filter parameters from simple format to Spatie's filter[] format.
     *
     * Converts ?state=queued to ?filter[state]=queued for backward compatibility.
     */
    protected function normalizeFilterParameters(Request $request): Request
    {
        $simpleFilters = ['state', 'type', 'id', 'requested_by_type', 'requested_by_id'];
        $normalized = $request->all();

        foreach ($simpleFilters as $key) {
            if ($request->has($key) && ! $request->has("filter.{$key}")) {
                $normalized['filter'][$key] = $request->input($key);
                unset($normalized[$key]);
            }
        }

        return Request::create(
            $request->getRequestUri(),
            $request->getMethod(),
            $normalized,
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent()
        );
    }

    /**
     * Show a specific work order.
     */
    public function show(WorkOrder $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load(['items', 'events' => function ($query) {
            $query->latest()->limit(20);
        }]);

        return response()->json(['order' => $order]);
    }

    /**
     * Checkout (lease) the next available work item for an order.
     */
    public function checkout(WorkOrder $order, Request $request): JsonResponse
    {
        $this->authorize('checkout', $order);

        $agentId = $this->getAgentId($request);

        // Get next available item
        $item = $this->leaseService->getNextAvailable($order->id);

        if (! $item) {
            return response()->json([
                'error' => [
                    'code' => 'no_items_available',
                    'message' => 'No work items available for checkout',
                ],
            ], 409);
        }

        try {
            $item = $this->leaseService->acquire($item->id, $agentId);

            return response()->json([
                'item' => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'input' => $item->input,
                    'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
                    'heartbeat_every_seconds' => config('work-manager.lease.heartbeat_every_seconds'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'lease_conflict',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * Global checkout - acquire the next available work item across all orders.
     */
    public function checkoutGlobal(Request $request): JsonResponse
    {
        $this->authorize('checkout', WorkOrder::class);

        $validated = $request->validate([
            'type' => 'nullable|string|max:120',
            'min_priority' => 'nullable|integer',
            'tenant_id' => 'nullable|string',
        ]);

        $agentId = $this->getAgentId($request);
        $item = $this->leaseService->acquireNextAvailable($agentId, $validated);

        if (! $item) {
            return response()->json([
                'error' => [
                    'code' => 'no_items_available',
                    'message' => 'No work items available matching filters',
                ],
            ], 409);
        }

        return response()->json([
            'item' => [
                'id' => $item->id,
                'type' => $item->type,
                'input' => $item->input,
                'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
                'heartbeat_every_seconds' => config('work-manager.lease.heartbeat_every_seconds'),
            ],
        ]);
    }

    /**
     * Extend the lease on a work item (heartbeat).
     */
    public function heartbeat(WorkItem $item, Request $request): JsonResponse
    {
        $agentId = $this->getAgentId($request);

        try {
            $item = $this->leaseService->extend($item->id, $agentId);

            return response()->json([
                'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'lease_error',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * Submit work item results.
     */
    public function submit(WorkItem $item, Request $request): JsonResponse
    {
        $this->authorize('submit', $item->order);

        $validated = $request->validate([
            'result' => 'required|array',
            'evidence' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $agentId = $this->getAgentId($request);
        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('submit') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'submit:item:'.$item->id,
                $idempotencyKey,
                fn () => $this->submitItem($item, $validated, $agentId)
            );

            return response()->json($result, 202);
        }

        $result = $this->submitItem($item, $validated, $agentId);

        return response()->json($result, 202);
    }

    /**
     * Approve a work order.
     */
    public function approve(WorkOrder $order, Request $request): JsonResponse
    {
        $this->authorize('approve', $order);

        $actorType = ActorType::USER;
        $actorId = Auth::id();

        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('approve') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'approve:order:'.$order->id,
                $idempotencyKey,
                fn () => $this->executor->approve($order, $actorType, $actorId)
            );

            return response()->json($result);
        }

        $result = $this->executor->approve($order, $actorType, $actorId);

        return response()->json($result);
    }

    /**
     * Reject a work order.
     */
    public function reject(WorkOrder $order, Request $request): JsonResponse
    {
        $this->authorize('reject', $order);

        $validated = $request->validate([
            'errors' => 'required|array',
            'errors.*.code' => 'required|string',
            'errors.*.message' => 'required|string',
            'errors.*.field' => 'nullable|string',
            'allow_rework' => 'nullable|boolean',
        ]);

        $actorType = ActorType::USER;
        $actorId = Auth::id();

        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('reject') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'reject:order:'.$order->id,
                $idempotencyKey,
                fn () => [
                    'order' => $this->executor->reject(
                        $order,
                        $validated['errors'],
                        $actorType,
                        $actorId,
                        $validated['allow_rework'] ?? false
                    ),
                ]
            );

            return response()->json($result);
        }

        $order = $this->executor->reject(
            $order,
            $validated['errors'],
            $actorType,
            $actorId,
            $validated['allow_rework'] ?? false
        );

        return response()->json(['order' => $order]);
    }

    /**
     * Release a work item lease.
     */
    public function release(WorkItem $item, Request $request): JsonResponse
    {
        $agentId = $this->getAgentId($request);

        try {
            $item = $this->leaseService->release($item->id, $agentId);

            return response()->json(['item' => $item]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'lease_error',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * Get logs/events for a work item.
     */
    public function logs(WorkItem $item): JsonResponse
    {
        $events = WorkEvent::where('item_id', $item->id)
            ->orWhere(function ($query) use ($item) {
                $query->where('order_id', $item->order_id)
                    ->whereNull('item_id');
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc') // Tie-breaker for events with same timestamp
            ->limit(100)
            ->get();

        return response()->json(['events' => $events]);
    }

    /**
     * Helper to create an order.
     */
    protected function createOrder(array $data): array
    {
        $order = $this->allocator->propose(
            type: $data['type'],
            payload: $data['payload'],
            requestedByType: ActorType::AGENT,
            requestedById: Auth::id(),
            meta: $data['meta'] ?? null,
            priority: $data['priority'] ?? 0
        );

        return ['order' => $order];
    }

    /**
     * Helper to submit an item.
     */
    protected function submitItem(WorkItem $item, array $data, string $agentId): array
    {
        try {
            $item = $this->executor->submit(
                $item,
                $data['result'],
                $agentId,
                $data['evidence'] ?? null,
                $data['notes'] ?? null
            );

            return [
                'item' => $item,
                'state' => $item->state->value,
            ];
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }
    }

    /**
     * Submit a work item part.
     */
    public function submitPart(WorkItem $item, Request $request): JsonResponse
    {
        $this->authorize('submit', $item->order);

        $validated = $request->validate([
            'part_key' => 'required|string|max:120',
            'seq' => 'nullable|integer|min:0',
            'payload' => 'required|array',
            'evidence' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $agentId = $this->getAgentId($request);
        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('submit-part') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'submit-part:item:'.$item->id.':'.$validated['part_key'].':'.($validated['seq'] ?? 'null'),
                $idempotencyKey,
                fn () => $this->submitPartInternal($item, $validated, $agentId)
            );

            return response()->json($result, 202);
        }

        $result = $this->submitPartInternal($item, $validated, $agentId);

        return response()->json($result, 202);
    }

    /**
     * List work item parts.
     */
    public function listParts(WorkItem $item, Request $request): JsonResponse
    {
        $this->authorize('view', $item->order);

        $query = $item->parts();

        if ($request->has('part_key')) {
            $query->where('part_key', $request->input('part_key'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $parts = $query->orderBy('part_key')
            ->orderByDesc('seq')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
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
        ]);
    }

    /**
     * Finalize a work item.
     */
    public function finalize(WorkItem $item, Request $request): JsonResponse
    {
        $this->authorize('submit', $item->order);

        $validated = $request->validate([
            'mode' => 'nullable|string|in:strict,best_effort',
        ]);

        $mode = $validated['mode'] ?? 'strict';
        $idempotencyKey = $request->header($this->idempotency->getHeaderName());

        // Enforce idempotency key if required
        if ($this->idempotency->isRequired('finalize') && ! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency key is required for this endpoint',
                    'header' => $this->idempotency->getHeaderName(),
                ],
            ], 428);
        }

        if ($idempotencyKey) {
            $result = $this->idempotency->guard(
                'finalize:item:'.$item->id,
                $idempotencyKey,
                fn () => $this->finalizeInternal($item, $mode)
            );

            return response()->json($result, 202);
        }

        $result = $this->finalizeInternal($item, $mode);

        return response()->json($result, 202);
    }

    /**
     * Helper to submit a part.
     */
    protected function submitPartInternal(WorkItem $item, array $data, string $agentId): array
    {
        try {
            $part = $this->executor->submitPart(
                $item,
                $data['part_key'],
                $data['seq'] ?? null,
                $data['payload'],
                $agentId,
                $data['evidence'] ?? null,
                $data['notes'] ?? null
            );

            return [
                'success' => true,
                'part' => [
                    'id' => $part->id,
                    'part_key' => $part->part_key,
                    'seq' => $part->seq,
                    'status' => $part->status->value,
                ],
                'item_parts_state' => $item->fresh()->parts_state,
            ];
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }
    }

    /**
     * Helper to finalize an item.
     */
    protected function finalizeInternal(WorkItem $item, string $mode): array
    {
        try {
            $item = $this->executor->finalizeItem($item, $mode);

            return [
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'state' => $item->state->value,
                    'assembled_result' => $item->assembled_result,
                ],
                'order_state' => $item->order->state->value,
            ];
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }
    }

    /**
     * Get the agent ID from the request.
     */
    protected function getAgentId(Request $request): string
    {
        // You can customize this based on your auth setup
        return $request->header('X-Agent-ID')
            ?? $request->header('X-Agent-Id') // support both casings
            ?? (Auth::check() ? (string) Auth::id() : 'unknown'); // ensure string
    }
}
