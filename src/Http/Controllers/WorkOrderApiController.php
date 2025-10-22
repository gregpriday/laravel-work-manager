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
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class WorkOrderApiController extends Controller
{
    use AuthorizesRequests;
    public function __construct(
        protected WorkAllocator $allocator,
        protected WorkExecutor $executor,
        protected LeaseService $leaseService,
        protected IdempotencyService $idempotency
    ) {
    }

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
        if ($this->idempotency->isRequired('propose') && !$idempotencyKey) {
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
                'propose:' . $validated['type'],
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
        $query = WorkOrder::query();

        if ($request->has('state')) {
            $query->inState($request->input('state'));
        }

        if ($request->has('type')) {
            $query->ofType($request->input('type'));
        }

        if ($request->has('requested_by_type')) {
            $query->requestedBy($request->input('requested_by_type'));
        }

        $limit = min($request->input('limit', 50), 100);

        $orders = $query->with(['items'])
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->paginate($limit);

        return response()->json($orders);
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

        if (!$item) {
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
        if ($this->idempotency->isRequired('submit') && !$idempotencyKey) {
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
                'submit:item:' . $item->id,
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
        if ($this->idempotency->isRequired('approve') && !$idempotencyKey) {
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
                'approve:order:' . $order->id,
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
        if ($this->idempotency->isRequired('reject') && !$idempotencyKey) {
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
                'reject:order:' . $order->id,
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
     * Get the agent ID from the request.
     */
    protected function getAgentId(Request $request): string
    {
        // You can customize this based on your auth setup
        return $request->header('X-Agent-ID') ?? Auth::id() ?? 'unknown';
    }
}
