# API Surface Reference

Complete index of all public APIs, classes, facades, and contracts in Laravel Work Manager.

## Table of Contents

- [Facades](#facades)
- [Service Classes](#service-classes)
- [Models](#models)
- [Contracts (Interfaces)](#contracts-interfaces)
- [Abstract Base Classes](#abstract-base-classes)
- [Enums](#enums)
- [Exceptions](#exceptions)
- [Events](#events)
- [Support Classes](#support-classes)
- [HTTP Controllers](#http-controllers)
- [Console Commands](#console-commands)
- [Middleware](#middleware)

---

## Facades

### `GregPriday\WorkManager\Facades\WorkManager`

Primary facade for interacting with the package.

**Static Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `routes(string $basePath = 'agent/work', array $middleware = ['api'])` | `void` | Register package routes |
| `registry()` | `OrderTypeRegistry` | Access the order type registry |
| `allocator()` | `WorkAllocator` | Access the work allocator service |
| `executor()` | `WorkExecutor` | Access the work executor service |

**Example:**
```php
use GregPriday\WorkManager\Facades\WorkManager;

// Register routes
WorkManager::routes('ai/work', ['api', 'auth:sanctum']);

// Register an order type
WorkManager::registry()->register(new MyOrderType());

// Propose a work order
$order = WorkManager::allocator()->propose(
    type: 'my.work.type',
    payload: ['data' => 'value']
);
```

---

## Service Classes

### `GregPriday\WorkManager\Services\WorkAllocator`

Handles work order proposal and planning.

**Constructor:**
```php
public function __construct(
    protected OrderTypeRegistry $registry,
    protected StateMachine $stateMachine
)
```

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `propose(string $type, array $payload, ?ActorType $requestedByType = null, ?string $requestedById = null, ?array $meta = null, int $priority = 0)` | `WorkOrder` | Propose a new work order |
| `plan(WorkOrder $order, ?OrderType $orderType = null)` | `void` | Plan a work order into discrete work items |

### `GregPriday\WorkManager\Services\WorkExecutor`

Handles work item submission, approval, rejection, and application.

**Constructor:**
```php
public function __construct(
    protected OrderTypeRegistry $registry,
    protected StateMachine $stateMachine
)
```

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `submit(WorkItem $item, array $result, string $agentId, ?array $evidence = null, ?string $notes = null)` | `WorkItem` | Submit a work item result |
| `submitPart(WorkItem $item, string $partKey, ?int $seq, array $payload, string $agentId, ?array $evidence = null, ?string $notes = null)` | `WorkItemPart` | Submit a work item part (partial submission) |
| `finalizeItem(WorkItem $item, string $mode = 'strict')` | `WorkItem` | Finalize a work item by assembling all parts |
| `approve(WorkOrder $order, ?ActorType $actorType = null, ?string $actorId = null)` | `array` | Approve a work order and apply it |
| `apply(WorkOrder $order, ?OrderType $orderType = null)` | `Diff` | Apply an approved work order (idempotent) |
| `reject(WorkOrder $order, array $errors, ?ActorType $actorType = null, ?string $actorId = null, bool $allowRework = false)` | `WorkOrder` | Reject a work order |
| `fail(WorkItem $item, array $error)` | `WorkItem` | Mark a work item as failed |

### `GregPriday\WorkManager\Services\LeaseService`

Manages work item leases with TTL-based concurrency control.

**Constructor:**
```php
public function __construct(
    protected StateMachine $stateMachine
)
```

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `acquire(string $itemId, string $agentId)` | `WorkItem` | Attempt to acquire a lease on a work item |
| `extend(string $itemId, string $agentId)` | `WorkItem` | Extend an existing lease (heartbeat) |
| `release(string $itemId, string $agentId)` | `WorkItem` | Release a lease explicitly |
| `reclaimExpired()` | `int` | Reclaim expired leases (called by maintenance) |
| `getNextAvailable(string $orderId)` | `?WorkItem` | Get the next available item for checkout |

### `GregPriday\WorkManager\Services\StateMachine`

Enforces state transitions and records events.

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `transitionOrder(WorkOrder $order, OrderState $newState, ?ActorType $actorType = null, ?string $actorId = null, ?array $payload = null, ?string $message = null, ?array $diff = null)` | `void` | Transition a work order to a new state |
| `transitionItem(WorkItem $item, ItemState $newState, ?ActorType $actorType = null, ?string $actorId = null, ?array $payload = null, ?string $message = null)` | `void` | Transition a work item to a new state |
| `recordOrderEvent(WorkOrder $order, EventType $event, ?ActorType $actorType = null, ?string $actorId = null, ?array $payload = null, ?string $message = null)` | `void` | Record an order event without state transition |
| `recordItemEvent(WorkItem $item, EventType $event, ?ActorType $actorType = null, ?string $actorId = null, ?array $payload = null, ?string $message = null)` | `void` | Record an item event without state transition |

### `GregPriday\WorkManager\Services\IdempotencyService`

Guards against duplicate operations via header-based deduplication.

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `guard(string $scope, string $key, callable $callback)` | `mixed` | Execute callback with idempotency protection |
| `getHeaderName()` | `string` | Get the configured idempotency header name |
| `isRequired(string $endpoint)` | `bool` | Check if idempotency key is required for endpoint |

### `GregPriday\WorkManager\Services\Registry\OrderTypeRegistry`

Manages registered order type implementations.

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `register(OrderType $orderType)` | `void` | Register an order type |
| `get(string $type)` | `OrderType` | Get a registered order type (throws if not found) |
| `has(string $type)` | `bool` | Check if an order type is registered |
| `all()` | `array` | Get all registered order types |

---

## Models

### `GregPriday\WorkManager\Models\WorkOrder`

Eloquent model representing a work order.

**Relationships:**

| Method | Type | Related Model |
|--------|------|---------------|
| `items()` | `HasMany` | `WorkItem` |
| `events()` | `HasMany` | `WorkEvent` |
| `provenances()` | `HasMany` | `WorkProvenance` |

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isTerminal()` | `bool` | Check if the order is in a terminal state |
| `allItemsComplete()` | `bool` | Check if all items are complete |

**Query Scopes:**

| Scope | Parameters | Description |
|-------|------------|-------------|
| `inState()` | `OrderState\|string $state` | Filter by state |
| `ofType()` | `string $type` | Filter by type |
| `requestedBy()` | `ActorType\|string $type, ?string $id = null` | Filter by requesting actor |

### `GregPriday\WorkManager\Models\WorkItem`

Eloquent model representing a work item.

**Relationships:**

| Method | Type | Related Model |
|--------|------|---------------|
| `order()` | `BelongsTo` | `WorkOrder` |
| `events()` | `HasMany` | `WorkEvent` |
| `provenances()` | `HasMany` | `WorkProvenance` |
| `parts()` | `HasMany` | `WorkItemPart` |

**Public Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isTerminal()` | `bool` | Check if the item is in a terminal state |
| `isLeaseExpired()` | `bool` | Check if the lease has expired |
| `isLeased()` | `bool` | Check if the item is currently leased |
| `hasExhaustedAttempts()` | `bool` | Check if max attempts have been reached |
| `supportsPartialSubmissions()` | `bool` | Check if the item supports partial submissions |
| `getLatestPart(string $partKey)` | `?WorkItemPart` | Get the latest part for a given key |
| `getLatestParts()` | `Collection` | Get all latest parts (one per key) |
| `hasAllRequiredParts()` | `bool` | Check if all required parts have been submitted |

**Query Scopes:**

| Scope | Parameters | Description |
|-------|------------|-------------|
| `inState()` | `ItemState\|string $state` | Filter by state |
| `withExpiredLease()` | - | Get items with expired leases |
| `availableForLease()` | - | Get items available for leasing |
| `leasedBy()` | `string $agentId` | Get items leased by a specific agent |

### `GregPriday\WorkManager\Models\WorkItemPart`

Eloquent model representing a partial submission.

**Relationships:**

| Method | Type | Related Model |
|--------|------|---------------|
| `workItem()` | `BelongsTo` | `WorkItem` |

**Fillable Attributes:**
- `work_item_id`, `part_key`, `seq`, `status`, `payload`, `evidence`, `notes`, `errors`, `checksum`, `submitted_by_agent_id`, `idempotency_key_hash`

### `GregPriday\WorkManager\Models\WorkEvent`

Eloquent model for audit trail events.

**Relationships:**

| Method | Type | Related Model |
|--------|------|---------------|
| `order()` | `BelongsTo` | `WorkOrder` |
| `item()` | `BelongsTo` | `WorkItem` |

### `GregPriday\WorkManager\Models\WorkProvenance`

Eloquent model for agent metadata and request fingerprints.

**Relationships:**

| Method | Type | Related Model |
|--------|------|---------------|
| `order()` | `BelongsTo` | `WorkOrder` |
| `item()` | `BelongsTo` | `WorkItem` |

### `GregPriday\WorkManager\Models\WorkIdempotencyKey`

Eloquent model for stored idempotency keys with cached responses.

**Fillable Attributes:**
- `scope`, `key_hash`, `response_snapshot`

---

## Contracts (Interfaces)

### `GregPriday\WorkManager\Contracts\OrderType`

Interface for custom order type implementations.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `type()` | `string` | Get the unique type identifier |
| `schema()` | `array` | Get the JSON schema for payload validation |
| `plan(WorkOrder $order)` | `array` | Plan the work order into discrete work items |
| `acceptancePolicy()` | `AcceptancePolicy` | Get the acceptance policy for validation |
| `apply(WorkOrder $order)` | `Diff` | Apply the approved work order (idempotent) |

### `GregPriday\WorkManager\Contracts\AcceptancePolicy`

Interface for submission validation policies.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `validateSubmission(WorkItem $item, array $result)` | `void` | Validate an agent submission (throws ValidationException) |
| `readyForApproval(WorkOrder $order)` | `bool` | Check if the order is ready for approval |

### `GregPriday\WorkManager\Contracts\AllocatorStrategy`

Interface for work discovery strategies.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `discoverWork()` | `array` | Discover and return work order specifications |

### `GregPriday\WorkManager\Contracts\PlannerPort`

Alternative interface for work generation.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `generateOrders()` | `array` | Generate work order specifications |

### `GregPriday\WorkManager\Contracts\LeaseBackend`

Interface for lease storage backends.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `acquire(string $itemId, string $agentId, int $ttl)` | `bool` | Attempt to acquire a lease |
| `extend(string $itemId, string $agentId, int $ttl)` | `bool` | Extend an existing lease |
| `release(string $itemId, string $agentId)` | `bool` | Release a lease |
| `isLeased(string $itemId)` | `bool` | Check if an item is currently leased |

### `GregPriday\WorkManager\Contracts\MetricsDriver`

Interface for metrics collection drivers.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `increment(string $metric, int $value = 1, array $tags = [])` | `void` | Increment a counter metric |
| `gauge(string $metric, float $value, array $tags = [])` | `void` | Set a gauge metric |
| `timing(string $metric, float $milliseconds, array $tags = [])` | `void` | Record a timing metric |

### `GregPriday\WorkManager\Contracts\ProvenanceEnricher`

Interface for enriching provenance data.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `enrich(array $data)` | `array` | Enrich provenance data with additional context |

### `GregPriday\WorkManager\Contracts\DiffRenderer`

Interface for rendering diffs in different formats.

**Required Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `render(array $diff)` | `string` | Render a diff array to a string representation |

---

## Abstract Base Classes

### `GregPriday\WorkManager\Support\AbstractOrderType`

Abstract base class for order types with built-in Laravel validation support.

**Protected Properties:**

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$autoApprove` | `bool` | `false` | Enable automatic approval when ready |

**Lifecycle Hooks (Optional Overrides):**

| Method | Parameters | Description |
|--------|------------|-------------|
| `beforeApply(WorkOrder $order)` | - | Called before apply() executes |
| `afterApply(WorkOrder $order, Diff $diff)` | - | Called after apply() succeeds |

**Validation Hooks (Optional Overrides):**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `submissionValidationRules(WorkItem $item)` | `array` | Laravel validation rules for submissions |
| `afterValidateSubmission(WorkItem $item, array $result)` | `void` | Custom validation logic after rules pass |
| `canApprove(WorkOrder $order)` | `bool` | Determine if order can be approved |

**Partial Submission Hooks (Optional Overrides):**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `partialRules(WorkItem $item, string $partKey, ?int $seq)` | `array` | Validation rules for a specific part |
| `afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq)` | `void` | Custom validation after part rules pass |
| `requiredParts(WorkItem $item)` | `array` | Define which parts are required for finalization |
| `assemble(WorkItem $item, Collection $latestParts)` | `array` | Assemble all parts into a single result |
| `validateAssembled(WorkItem $item, array $assembled)` | `void` | Validate the assembled result |

**Helper Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `makeDiff(array $before, array $after, ?string $summary = null)` | `Diff` | Create a diff from before/after arrays |
| `emptyDiff()` | `Diff` | Create an empty diff (no changes) |

### `GregPriday\WorkManager\Support\AbstractAcceptancePolicy`

Abstract base class for custom acceptance policies.

**Required Method Implementations:**
- `validateSubmission(WorkItem $item, array $result): void`
- `readyForApproval(WorkOrder $order): bool`

---

## Enums

### `GregPriday\WorkManager\Support\OrderState`

Work order states (backed enum with `string` type).

**Cases:**
- `QUEUED = 'queued'`
- `CHECKED_OUT = 'checked_out'`
- `IN_PROGRESS = 'in_progress'`
- `SUBMITTED = 'submitted'`
- `APPROVED = 'approved'`
- `APPLIED = 'applied'`
- `COMPLETED = 'completed'`
- `REJECTED = 'rejected'`
- `FAILED = 'failed'`
- `DEAD_LETTERED = 'dead_lettered'`

**Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isTerminal()` | `bool` | Check if state is terminal (completed or dead_lettered) |
| `canTransitionTo(OrderState $newState)` | `bool` | Check if transition to new state is allowed |

### `GregPriday\WorkManager\Support\ItemState`

Work item states (backed enum with `string` type).

**Cases:**
- `QUEUED = 'queued'`
- `LEASED = 'leased'`
- `IN_PROGRESS = 'in_progress'`
- `SUBMITTED = 'submitted'`
- `ACCEPTED = 'accepted'`
- `REJECTED = 'rejected'`
- `COMPLETED = 'completed'`
- `FAILED = 'failed'`
- `DEAD_LETTERED = 'dead_lettered'`

**Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isTerminal()` | `bool` | Check if state is terminal (completed, rejected, or dead_lettered) |
| `canTransitionTo(ItemState $newState)` | `bool` | Check if transition to new state is allowed |

### `GregPriday\WorkManager\Support\ActorType`

Actor types (backed enum with `string` type).

**Cases:**
- `USER = 'user'`
- `AGENT = 'agent'`
- `SYSTEM = 'system'`

### `GregPriday\WorkManager\Support\EventType`

Event types (backed enum with `string` type).

**Cases:**
- `PROPOSED = 'proposed'`
- `PLANNED = 'planned'`
- `CHECKED_OUT = 'checked_out'`
- `LEASED = 'leased'`
- `IN_PROGRESS = 'in_progress'`
- `HEARTBEAT = 'heartbeat'`
- `SUBMITTED = 'submitted'`
- `ACCEPTED = 'accepted'`
- `APPROVED = 'approved'`
- `APPLIED = 'applied'`
- `REJECTED = 'rejected'`
- `LEASE_EXPIRED = 'lease_expired'`
- `FAILED = 'failed'`
- `COMPLETED = 'completed'`
- `DEAD_LETTERED = 'dead_lettered'`
- `RELEASED = 'released'`

### `GregPriday\WorkManager\Support\PartStatus`

Partial submission statuses (backed enum with `string` type).

**Cases:**
- `DRAFT = 'draft'`
- `VALIDATED = 'validated'`
- `REJECTED = 'rejected'`

---

## Exceptions

See [exceptions-reference.md](./exceptions-reference.md) for complete exception documentation.

**Base Exception:**
- `GregPriday\WorkManager\Exceptions\WorkManagerException`

**Specific Exceptions:**
- `IllegalStateTransitionException`
- `LeaseConflictException`
- `LeaseExpiredException`
- `IdempotencyConflictException`
- `OrderTypeNotFoundException`
- `ForbiddenDirectMutationException`

---

## Events

See [events-reference.md](./events-reference.md) for complete event documentation.

**Work Order Events:**
- `WorkOrderProposed`, `WorkOrderPlanned`, `WorkOrderCheckedOut`, `WorkOrderApproved`, `WorkOrderApplied`, `WorkOrderCompleted`, `WorkOrderRejected`

**Work Item Events:**
- `WorkItemLeased`, `WorkItemHeartbeat`, `WorkItemSubmitted`, `WorkItemFailed`, `WorkItemLeaseExpired`, `WorkItemFinalized`

**Work Item Part Events:**
- `WorkItemPartSubmitted`, `WorkItemPartValidated`, `WorkItemPartRejected`

---

## Support Classes

### `GregPriday\WorkManager\Support\Diff`

Represents a diff of changes made during apply().

**Static Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `fromArrays(array $before, array $after, ?string $summary = null)` | `Diff` | Create diff from before/after arrays |
| `empty()` | `Diff` | Create an empty diff |

**Instance Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `toArray()` | `array` | Convert diff to array representation |
| `isEmpty()` | `bool` | Check if diff is empty |

### `GregPriday\WorkManager\Support\Helpers`

Static utility functions.

**Static Methods:**

| Method | Return Type | Description |
|--------|-------------|-------------|
| `uuid()` | `string` | Generate a UUID v4 |
| `validateJsonSchema(array $data, array $schema)` | `array` | Validate data against JSON schema, return errors |

---

## HTTP Controllers

### `GregPriday\WorkManager\Http\Controllers\WorkOrderApiController`

REST API controller for all work order and work item endpoints.

See [routes-reference.md](./routes-reference.md) for complete route documentation.

---

## Console Commands

See [commands-reference.md](./commands-reference.md) for complete command documentation.

**Available Commands:**
- `work-manager:generate` - Generate work orders based on registered strategies
- `work-manager:maintain` - Perform maintenance tasks (reclaim leases, dead-letter, check stale)
- `work-manager:mcp` - Start the MCP server for AI agent integration

---

## Middleware

### `GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly`

Middleware to prevent direct mutations outside the work order system.

**Usage:**
```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware(\GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly::class);
```

**Behavior:**
- Blocks all requests by default
- Allows requests with `X-Work-Order-Context` header containing a valid order ID
- Throws `ForbiddenDirectMutationException` if check fails

---

## Routing

### `GregPriday\WorkManager\Routing\RouteRegistrar`

Service class for registering package routes.

**Public Methods:**

| Method | Parameters | Description |
|--------|------------|-------------|
| `register(string $basePath = 'agent/work', array $middleware = ['api'])` | - | Register all work manager routes |

**Usage:**
```php
app(\GregPriday\WorkManager\Routing\RouteRegistrar::class)
    ->register('ai/work', ['api', 'auth:sanctum']);

// Or via facade
WorkManager::routes('ai/work', ['api', 'auth:sanctum']);
```

---

## Service Provider

### `GregPriday\WorkManager\WorkManagerServiceProvider`

Package service provider. Automatically registered by Laravel.

**Registered Services:**
- `work-manager` - Main package accessor
- `WorkAllocator`, `WorkExecutor`, `LeaseService`, `StateMachine`, `IdempotencyService`
- `OrderTypeRegistry`
- Route registration (if enabled in config)

---

## Related Documentation

- [Configuration Reference](./config-reference.md)
- [Routes Reference](./routes-reference.md)
- [Commands Reference](./commands-reference.md)
- [Events Reference](./events-reference.md)
- [Exceptions Reference](./exceptions-reference.md)
- [Database Schema](./database-schema.md)
