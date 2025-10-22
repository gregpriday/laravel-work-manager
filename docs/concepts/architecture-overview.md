# Architecture Overview

## Introduction

Laravel Work Manager is built as a layered architecture with clear separation of concerns. This document explains the system design, component interactions, and data flows through the complete work order lifecycle.

---

## System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    AI AGENTS / CLIENTS                           │
│  • HTTP clients                                                  │
│  • MCP-enabled AI IDEs (Cursor, Claude Desktop)                 │
│  • Custom integrations                                           │
└────────────────┬────────────────────────────────────────────────┘
                 │ HTTP/MCP Protocol
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                    HTTP API LAYER                                │
│  WorkOrderApiController                                          │
│  ├─ propose       - Create work orders                          │
│  ├─ checkout      - Lease work items                            │
│  ├─ heartbeat     - Extend leases                               │
│  ├─ submit        - Submit complete results                     │
│  ├─ submit-part   - Submit partial results                      │
│  ├─ finalize      - Assemble parts into final result            │
│  ├─ approve       - Approve and apply orders                    │
│  ├─ reject        - Reject orders                               │
│  ├─ release       - Release leases                              │
│  └─ logs          - Query event history                         │
│                                                                   │
│  Middleware:                                                     │
│  • Idempotency enforcement                                       │
│  • Authorization via policies                                    │
│  • Rate limiting                                                 │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     SERVICE LAYER                                │
├──────────────────────────────────────────────────────────────────┤
│  WorkAllocator         │  WorkExecutor       │  LeaseService    │
│  • propose()           │  • submit()         │  • acquire()     │
│  • plan()              │  • submitPart()     │  • extend()      │
│                        │  • finalizeItem()   │  • release()     │
│                        │  • approve()        │  • reclaim()     │
│                        │  • reject()         │                  │
│                        │  • apply()          │                  │
├──────────────────────────────────────────────────────────────────┤
│  StateMachine          │  IdempotencyService │  OrderTypeRegistry│
│  • transitionOrder()   │  • guard()          │  • register()    │
│  • transitionItem()    │  • check()          │  • get()         │
│  • recordOrderEvent()  │  • store()          │  • all()         │
│  • recordItemEvent()   │                     │                  │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                    ORDER TYPE LAYER                              │
│  Your Custom Types (extend AbstractOrderType)                   │
├──────────────────────────────────────────────────────────────────┤
│  Required Methods:                │  Lifecycle Hooks:            │
│  • type()                         │  • beforeApply()             │
│  • schema()                       │  • apply() ⭐ (idempotent)  │
│  • plan()                         │  • afterApply()              │
│  • acceptancePolicy()             │                              │
│                                   │                              │
│  Validation Hooks:                │  Verification:               │
│  • submissionValidationRules()    │  • afterValidateSubmission() │
│  • validationMessages()           │  • canApprove()              │
│  • partialRules()                 │  • afterValidatePart()       │
│  • requiredParts()                │                              │
│  • assemble()                     │                              │
│  • validateAssembled()            │                              │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     DATA/MODEL LAYER                             │
├──────────────────────────────────────────────────────────────────┤
│  WorkOrder                 │  WorkItem                           │
│  • type, state, payload    │  • state, input, result             │
│  • priority, meta          │  • lease info, attempts             │
│                            │  • parts_state (for partials)       │
│                            │                                     │
│  WorkEvent                 │  WorkProvenance                     │
│  • event, actor, payload   │  • agent metadata                   │
│  • diff, message           │  • request fingerprint              │
│                            │                                     │
│  WorkIdempotencyKey        │  WorkItemPart                       │
│  • scope, key_hash         │  • part_key, seq, status            │
│  • cached_response         │  • payload, evidence, checksum      │
└─────────────────────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                    PERSISTENCE LAYER                             │
│  • MySQL 8+ / PostgreSQL 13+                                     │
│  • Redis (optional, for leasing backend)                         │
│  • File storage (optional, for large artifacts)                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Layered Architecture

### 1. API Layer

**Purpose**: Entry point for all external interactions.

**Components**:
- `WorkOrderApiController` - RESTful endpoints for all operations
- `EnforceWorkOrderOnly` - Middleware to protect legacy routes
- Idempotency middleware
- Authorization policies

**Responsibilities**:
- HTTP request/response handling
- Authentication and authorization
- Request validation (basic)
- Idempotency enforcement
- Provenance capture (agent headers, request fingerprints)

**Example Endpoints**:
```
POST   /api/ai/work/propose              → WorkAllocator::propose()
POST   /api/ai/work/orders/{id}/checkout → LeaseService::acquire()
POST   /api/ai/work/items/{id}/submit    → WorkExecutor::submit()
POST   /api/ai/work/orders/{id}/approve  → WorkExecutor::approve()
```

---

### 2. Service Layer

**Purpose**: Core business logic and orchestration.

#### WorkAllocator Service

Creates and plans work orders:

```php
class WorkAllocator
{
    public function propose(
        string $type,
        array $payload,
        ?string $priority = null,
        ?array $meta = null
    ): WorkOrder;

    public function plan(WorkOrder $order): Collection;
}
```

**Responsibilities**:
- Validate payload against order type schema
- Create work order in `queued` state
- Call order type's `plan()` method to create work items
- Record provenance
- Emit `WorkOrderProposed` and `WorkOrderPlanned` events

---

#### WorkExecutor Service

Handles submission, verification, approval, and execution:

```php
class WorkExecutor
{
    public function submit(
        WorkItem $item,
        array $result,
        string $agentId,
        ?array $evidence = null,
        ?string $notes = null
    ): WorkItem;

    public function submitPart(
        WorkItem $item,
        string $partKey,
        ?int $seq,
        array $payload,
        string $agentId,
        ?array $evidence = null,
        ?string $notes = null
    ): WorkItemPart;

    public function finalizeItem(
        WorkItem $item,
        string $mode = 'strict'
    ): WorkItem;

    public function approve(
        WorkOrder $order,
        ?ActorType $actorType = null,
        ?string $actorId = null
    ): array;

    public function reject(
        WorkOrder $order,
        array $errors,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        bool $allowRework = false
    ): WorkOrder;

    public function apply(WorkOrder $order, ?OrderType $orderType = null): Diff;

    public function fail(WorkItem $item, array $error): WorkItem;
}
```

**Responsibilities**:
- Verify lease ownership
- Validate submissions (delegates to order type)
- Transition items through states
- Check approval readiness
- Execute approved work (calls order type's `apply()`)
- Handle partial submissions (validate, store, assemble)
- Emit events at each stage

---

#### LeaseService

Manages work item leases with TTL and heartbeat:

```php
class LeaseService
{
    public function acquire(
        WorkOrder $order,
        string $agentId,
        ?int $ttl = null
    ): WorkItem;

    public function extend(
        WorkItem $item,
        string $agentId,
        ?int $ttl = null
    ): WorkItem;

    public function release(
        WorkItem $item,
        string $agentId
    ): WorkItem;

    public function reclaim(): int;
}
```

**Responsibilities**:
- Acquire exclusive leases (database or Redis backend)
- Extend leases via heartbeat
- Explicitly release leases
- Reclaim expired leases (scheduled command)
- Handle concurrency limits (max per agent, max per type)

**Backend Options**:
- **Database**: Row-level locks using `SELECT FOR UPDATE`
- **Redis**: `SET NX EX` pattern for distributed locking

---

#### StateMachine Service

Enforces state transitions and records events:

```php
class StateMachine
{
    public function transitionOrder(
        WorkOrder $order,
        OrderState $newState,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null,
        ?array $diff = null
    ): WorkOrder;

    public function transitionItem(
        WorkItem $item,
        ItemState $newState,
        ?ActorType $actorType = null,
        ?string $actorId = null,
        ?array $payload = null,
        ?string $message = null
    ): WorkItem;

    public function recordOrderEvent(...): WorkEvent;
    public function recordItemEvent(...): WorkEvent;
}
```

**Responsibilities**:
- Validate state transitions against configuration
- Update model state and timestamps
- Record events in `work_events` table
- Emit Laravel events
- Check for order completion

**Guarantees**:
- **Atomicity**: State transitions happen in database transactions
- **Auditability**: Every transition creates an event record
- **Consistency**: Invalid transitions throw exceptions

---

#### IdempotencyService

Prevents duplicate operations via header-based keys:

```php
class IdempotencyService
{
    public function guard(
        string $scope,
        string $key,
        callable $callback
    ): mixed;

    public function check(string $scope, string $key): ?array;
    public function store(string $scope, string $key, array $response): void;
}
```

**Responsibilities**:
- Hash and store idempotency keys
- Cache responses for retries
- Scope keys by operation type (propose, submit, approve, etc.)
- TTL management for stored keys

---

#### OrderTypeRegistry

Manages registered order type implementations:

```php
class OrderTypeRegistry
{
    public function register(OrderType $type): void;
    public function get(string $type): OrderType;
    public function all(): Collection;
}
```

**Responsibilities**:
- Store order type instances
- Retrieve by type string
- Validate uniqueness

---

### 3. Order Type Layer

**Purpose**: Define domain-specific work behavior.

**Base Classes**:
- `AbstractOrderType` - Full-featured base with Laravel validation
- `AbstractAcceptancePolicy` - Separate validation class

**Required Methods**:

```php
abstract class AbstractOrderType implements OrderType
{
    // Identity
    abstract public function type(): string;

    // Schema
    abstract public function schema(): array;

    // Planning
    public function plan(WorkOrder $order): array {
        return [['type' => $this->type(), 'input' => $order->payload]];
    }

    // Execution (MUST be idempotent!)
    abstract public function apply(WorkOrder $order): Diff;

    // Validation
    protected function submissionValidationRules(WorkItem $item): array { return []; }
    protected function afterValidateSubmission(WorkItem $item, array $result): void {}
    protected function canApprove(WorkOrder $order): bool { return true; }

    // Partial Submissions
    protected function partialRules(WorkItem $item, string $partKey, ?int $seq): array { return []; }
    protected function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void {}
    public function requiredParts(WorkItem $item): array { return []; }
    public function assemble(WorkItem $item, Collection $parts): array { return []; }
    public function validateAssembled(WorkItem $item, array $assembled): void {}

    // Lifecycle Hooks
    protected function beforeApply(WorkOrder $order): void {}
    protected function afterApply(WorkOrder $order, Diff $diff): void {}
}
```

**Order Type Responsibilities**:
- Define what data is required (schema)
- Break work into items (planning)
- Validate agent submissions
- Execute domain logic (apply)
- Provide before/after hooks

---

### 4. Data/Model Layer

**Purpose**: Eloquent models with rich behavior.

#### WorkOrder Model

```php
class WorkOrder extends Model
{
    protected $casts = [
        'state' => OrderState::class,
        'payload' => 'array',
        'meta' => 'array',
    ];

    // Relationships
    public function items(): HasMany;
    public function events(): HasMany;
    public function provenance(): HasOne;

    // Query Scopes
    public function scopeByState(Builder $query, OrderState $state);
    public function scopeByType(Builder $query, string $type);

    // Helpers
    public function allItemsSubmitted(): bool;
    public function allItemsComplete(): bool;
}
```

---

#### WorkItem Model

```php
class WorkItem extends Model
{
    protected $casts = [
        'state' => ItemState::class,
        'input' => 'array',
        'result' => 'array',
        'assembled_result' => 'array',
        'parts_state' => 'array',
        'error' => 'array',
        'lease_expires_at' => 'datetime',
    ];

    // Relationships
    public function order(): BelongsTo;
    public function parts(): HasMany;
    public function events(): HasMany;

    // Lease Management
    public function isLeaseExpired(): bool;
    public function isLeasedBy(string $agentId): bool;
    public function canHeartbeat(): bool;
}
```

---

#### WorkItemPart Model

```php
class WorkItemPart extends Model
{
    protected $casts = [
        'status' => PartStatus::class,
        'payload' => 'array',
        'evidence' => 'array',
        'errors' => 'array',
    ];

    public function item(): BelongsTo;
}
```

---

#### WorkEvent Model

```php
class WorkEvent extends Model
{
    protected $casts = [
        'event' => EventType::class,
        'actor_type' => ActorType::class,
        'payload' => 'array',
        'diff' => 'array',
    ];

    public function order(): BelongsTo;
    public function item(): BelongsTo;
}
```

---

## Data Flow: Complete Lifecycle

### 1. Proposal Flow

```
Agent/System
    │
    ├─> POST /propose {type, payload}
    │   └─> IdempotencyService::guard()
    │
    ├─> WorkAllocator::propose()
    │   ├─> Validate against schema()
    │   ├─> Create WorkOrder (state: queued)
    │   ├─> WorkProvenance::create()
    │   └─> Emit WorkOrderProposed
    │
    └─> WorkAllocator::plan()
        ├─> OrderType::plan(order)
        ├─> Create WorkItems (state: queued)
        └─> Emit WorkOrderPlanned
```

**Database Operations**:
```sql
INSERT INTO work_orders (type, payload, state, ...) VALUES (...);
INSERT INTO work_provenances (...) VALUES (...);
INSERT INTO work_items (order_id, type, input, state, ...) VALUES (...);
INSERT INTO work_events (order_id, event, ...) VALUES (...);
INSERT INTO work_idempotency_keys (...) VALUES (...);
```

---

### 2. Checkout & Heartbeat Flow

```
Agent
    │
    ├─> POST /orders/{order}/checkout
    │   └─> LeaseService::acquire(order, agentId)
    │       ├─> Find next queued item
    │       ├─> Check concurrency limits
    │       ├─> Acquire lease (DB lock or Redis SET NX)
    │       ├─> StateMachine::transitionItem(leased)
    │       ├─> Emit WorkItemLeased
    │       └─> Return item.input
    │
    ├─> [Agent processes work...]
    │
    └─> POST /items/{item}/heartbeat (every 2 min)
        └─> LeaseService::extend(item, agentId)
            ├─> Verify lease ownership
            ├─> Extend lease_expires_at
            ├─> Emit WorkItemHeartbeat
            └─> Return item
```

**Database Operations** (database backend):
```sql
-- Acquire lease (atomic)
BEGIN;
SELECT * FROM work_items WHERE id = ? FOR UPDATE;
UPDATE work_items SET state = 'leased', leased_by_agent_id = ?, lease_expires_at = ? WHERE id = ?;
COMMIT;

-- Heartbeat
UPDATE work_items SET lease_expires_at = ? WHERE id = ? AND leased_by_agent_id = ?;
```

**Redis Operations** (Redis backend):
```redis
-- Acquire lease
SET work:lease:item:123 "agent-1" NX EX 600

-- Heartbeat
EXPIRE work:lease:item:123 600
```

---

### 3. Submission Flow (Complete Results)

```
Agent
    │
    └─> POST /items/{item}/submit {result}
        └─> IdempotencyService::guard()
            └─> WorkExecutor::submit(item, result, agentId)
                ├─> Verify lease ownership
                ├─> OrderType::acceptancePolicy()->validateSubmission()
                │   ├─> submissionValidationRules() [Laravel validation]
                │   └─> afterValidateSubmission() [Custom checks]
                ├─> Store item.result
                ├─> StateMachine::transitionItem(submitted)
                ├─> Emit WorkItemSubmitted
                └─> WorkExecutor::checkAutoApproval()
                    └─> If all items submitted && type allows auto-approval
                        └─> WorkExecutor::approve()
```

**Database Operations**:
```sql
UPDATE work_items SET result = ?, state = 'submitted' WHERE id = ?;
INSERT INTO work_events (item_id, event, ...) VALUES (...);
```

---

### 4. Submission Flow (Partial Results)

```
Agent
    │
    ├─> POST /items/{item}/submit-part {part_key, seq, payload}
    │   └─> IdempotencyService::guard()
    │       └─> WorkExecutor::submitPart()
    │           ├─> Verify lease ownership
    │           ├─> OrderType::partialRules() [validation]
    │           ├─> OrderType::afterValidatePart() [custom checks]
    │           ├─> Create/Update WorkItemPart (status: validated)
    │           ├─> Update item.parts_state (materialized view)
    │           ├─> Emit WorkItemPartSubmitted, WorkItemPartValidated
    │           └─> Return part
    │
    └─> POST /items/{item}/finalize
        └─> IdempotencyService::guard()
            └─> WorkExecutor::finalizeItem(item)
                ├─> Check required parts (strict mode)
                ├─> Get latest validated parts
                ├─> OrderType::assemble(item, parts) [combine payloads]
                ├─> OrderType::validateAssembled() [validate final result]
                ├─> Store assembled_result and result
                ├─> StateMachine::transitionItem(submitted)
                ├─> Emit WorkItemFinalized
                └─> WorkExecutor::checkAutoApproval()
```

**Database Operations**:
```sql
-- Submit part
INSERT INTO work_item_parts (work_item_id, part_key, seq, status, payload, checksum, ...) VALUES (...);
UPDATE work_items SET parts_state = ? WHERE id = ?;

-- Finalize
UPDATE work_items SET assembled_result = ?, result = ?, state = 'submitted' WHERE id = ?;
```

---

### 5. Approval & Apply Flow

```
System/User
    │
    ├─> POST /orders/{order}/approve
    │   └─> IdempotencyService::guard()
    │       └─> WorkExecutor::approve(order)
    │           ├─> OrderType::acceptancePolicy()->readyForApproval()
    │           │   └─> canApprove() [Cross-item validation]
    │           ├─> StateMachine::transitionOrder(approved)
    │           ├─> Emit WorkOrderApproved
    │           │
    │           └─> WorkExecutor::apply(order)
    │               ├─> OrderType::beforeApply() [Hook]
    │               ├─> OrderType::apply(order) ⭐ [Domain logic]
    │               ├─> StateMachine::transitionOrder(applied, diff)
    │               ├─> Emit WorkOrderApplied(order, diff)
    │               ├─> Transition all items: submitted → accepted → completed
    │               ├─> OrderType::afterApply(order, diff) [Hook]
    │               └─> StateMachine::checkOrderCompletion()
    │                   └─> If all items complete → transitionOrder(completed)
    │
    └─> Return {order, diff}
```

**Database Operations**:
```sql
BEGIN TRANSACTION;

-- Approve
UPDATE work_orders SET state = 'approved' WHERE id = ?;
INSERT INTO work_events (order_id, event, ...) VALUES (...);

-- Apply (in order type's apply() method)
-- Your domain-specific mutations here...

-- Record applied state with diff
UPDATE work_orders SET state = 'applied', applied_at = NOW() WHERE id = ?;
INSERT INTO work_events (order_id, event, diff, ...) VALUES (...);

-- Complete items
UPDATE work_items SET state = 'accepted', accepted_at = NOW() WHERE order_id = ? AND state = 'submitted';
UPDATE work_items SET state = 'completed' WHERE order_id = ? AND state = 'accepted';

-- Complete order
UPDATE work_orders SET state = 'completed', completed_at = NOW() WHERE id = ?;

COMMIT;
```

---

## Integration Points

### Laravel Events

Every operation emits Laravel events:

```php
// Listen to events
Event::listen(WorkOrderApplied::class, function($event) {
    // $event->order
    // $event->diff

    // Send to monitoring
    Metrics::increment('orders_applied', ['type' => $event->order->type]);

    // Trigger downstream processes
    ProcessFollowUp::dispatch($event->order);
});
```

**Available Events**:
- Order: Proposed, Planned, CheckedOut, Approved, Applied, Completed, Rejected
- Item: Leased, Heartbeat, Submitted, Finalized, Failed, LeaseExpired
- Part: PartSubmitted, PartValidated, PartRejected

---

### Laravel Queues

Background processing via Laravel queues:

```php
// In your order type
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Queue follow-up jobs
    SendNotifications::dispatch($order)->onQueue('notifications');
    UpdateAnalytics::dispatch($diff)->onQueue('analytics');
}
```

**Queue Configuration**:
```php
'queues' => [
    'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'redis'),
    'maintenance_queue' => 'work:maintenance',
    'planning_queue' => 'work:planning',
]
```

---

### Laravel Policies

Authorization via policies:

```php
// WorkOrderPolicy
public function approve(User $user, WorkOrder $order): bool
{
    return $user->hasPermission('work.approve')
        && $order->state === OrderState::SUBMITTED;
}

// In controller
Gate::authorize('approve', $order);
```

**Policy Configuration**:
```php
'policies' => [
    'propose' => 'work.propose',
    'checkout' => 'work.checkout',
    'submit' => 'work.submit',
    'approve' => 'work.approve',
    'reject' => 'work.reject',
]
```

---

### Laravel Validation

Submission validation via Laravel validation rules:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'email' => 'required|email|unique:users,email',
        'status' => 'required|in:success,failed',
        'data' => 'required|array',
        'data.*.verified' => 'required|boolean|accepted',
    ];
}
```

---

### Scheduled Commands

Maintenance via Laravel scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Generate new work orders
    $schedule->command('work-manager:generate')->everyFifteenMinutes();

    // Reclaim expired leases, dead-letter stuck work
    $schedule->command('work-manager:maintain')->everyMinute();
}
```

---

## Scaling Patterns

### Horizontal Scaling

**Web/API Tier**:
- Stateless controllers enable load balancing
- Multiple instances behind load balancer
- Redis-backed leases eliminate single DB bottleneck

**Worker Tier**:
- Multiple `work-manager:maintain` processes
- Idempotent lease reclaim (safe to run concurrently)
- Queue workers scale independently

**Database Tier**:
- Read replicas for queries
- Write master for mutations
- Partitioning by `created_at` or `tenant_id` for large datasets

---

### Vertical Scaling

**Single Instance**:
- Increase PHP workers (FPM pool size)
- Increase database connections
- Increase Redis memory (if using Redis lease backend)
- Tune TTL/heartbeat based on workload

---

### Performance Targets

**Recommended Targets**:
- Lease acquisition: < 50ms (p99)
- Submit validation: < 200ms (p99)
- Apply execution: < 5s (p99, depends on domain logic)
- Heartbeat processing: < 20ms (p99)
- Order proposal: < 100ms (p99)

---

## Observability

### Metrics

Built-in metrics support (configurable drivers):

```php
'metrics' => [
    'enabled' => true,
    'driver' => 'prometheus',  // 'log', 'statsd', 'prometheus'
    'namespace' => 'work_manager',
]
```

**Recommended Metrics**:
- Orders created per type
- Items checked out
- Submissions received
- Orders approved/rejected
- Lease expirations
- Time in each state
- Failure reasons

---

### Logging

Comprehensive logging at each layer:

```php
// In order types
protected function beforeApply(WorkOrder $order): void
{
    Log::info('Starting execution', [
        'order_id' => $order->id,
        'type' => $order->type,
        'items_count' => $order->items->count(),
    ]);
}
```

---

### Event Stream

Query event history:

```
GET /api/ai/work/items/{item}/logs
```

Returns:
```json
[
    {
        "event": "leased",
        "actor_type": "agent",
        "actor_id": "agent-1",
        "message": "Item leased by agent-1",
        "created_at": "2025-10-22T10:30:00Z"
    },
    {
        "event": "submitted",
        "actor_type": "agent",
        "actor_id": "agent-1",
        "payload": { "result": { ... } },
        "created_at": "2025-10-22T10:35:00Z"
    }
]
```

---

## Security Architecture

### Authentication

**Routes Configuration**:
```php
'routes' => [
    'guard' => 'sanctum',  // Laravel auth guard
    'middleware' => ['api', 'auth:sanctum'],
]
```

**Agent Authentication**:
- Bearer tokens (Sanctum)
- API keys (custom middleware)
- OAuth2 (Passport)

---

### Authorization

**Policy-Based**:
```php
Gate::authorize('propose', WorkOrder::class);
Gate::authorize('approve', $order);
```

**Header-Based Provenance**:
```
X-Agent-ID: agent-1
X-Agent-Name: ResearchAgent
X-Agent-Version: 1.0.0
X-Request-ID: req-abc-123
```

---

### Idempotency

**Protection Against**:
- Network retries
- Timeout retries
- Replay attacks

**Implementation**:
- Hash and store idempotency keys
- Scope by operation type
- Cache responses for retries
- TTL-based expiration

---

### Audit Trail

**Complete Provenance**:
- Who (actor_type, actor_id, agent metadata)
- What (event type, payload, diff)
- When (timestamp)
- Why (message, context)

**Storage**: All events in `work_events` table, queryable for compliance.

---

## See Also

- [What It Does](what-it-does.md) - Problem domain and core concepts
- [Lifecycle and Flow](lifecycle-and-flow.md) - Complete work order lifecycle
- [State Management](state-management.md) - State machine deep dive
- [Configuration Model](configuration-model.md) - Configuration and precedence
- [Security and Permissions](security-and-permissions.md) - Authentication and authorization
- [ARCHITECTURE.md](architecture-overview.md) - Full architecture document with future enhancements
