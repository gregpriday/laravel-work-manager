# What It Does: Understanding Laravel Work Manager

## Introduction

Laravel Work Manager is an AI-agent oriented work order control plane for Laravel applications. It provides a complete framework for managing work that needs to be proposed, planned, executed, verified, and applied with strong guarantees around state management, idempotency, and auditability.

## The Problem Domain

### Modern AI Systems Need Structured Work Management

Modern AI systems—whether autonomous agents, AI assistants, or human-supervised AI workflows—often perform non-trivial backend work such as:

- Data research and enrichment
- Content generation and fact-checking
- Database migrations and synchronization
- External API integrations
- Multi-step data processing tasks

These operations require more than simple request-response patterns. They need:

1. **Structured Workflows**: Breaking complex work into manageable units
2. **Concurrency Control**: Preventing multiple agents from processing the same work simultaneously
3. **Verification**: Validating that agent work meets business requirements before applying changes
4. **Idempotency**: Ensuring operations can be safely retried without duplicate effects
5. **Auditability**: Tracking who did what, when, and why
6. **State Management**: Enforcing valid progressions through a work lifecycle
7. **Safety**: Preventing unauthorized mutations and ensuring approval gates

### Traditional Approaches Fall Short

Without a dedicated work management system, teams typically build ad-hoc solutions with:

- Direct database mutations without audit trails
- No concurrency control (race conditions)
- Weak or inconsistent validation
- Manual approval processes prone to errors
- Poor retry semantics
- Limited observability

**Laravel Work Manager solves these problems with a comprehensive, framework-native approach.**

---

## Core Concepts

### 1. Work Orders

A **Work Order** represents a high-level contract to perform work of a specific type.

```php
WorkOrder {
    type: "user.data.sync"           // What kind of work
    payload: {                        // Instructions/parameters
        source: "crm",
        user_ids: [1, 2, 3]
    }
    state: OrderState                 // Current lifecycle state
    priority: "normal"                // Execution priority
    meta: { ... }                     // Additional metadata
}
```

**Key Properties**:
- Defines **what** needs to be done (type + payload)
- Tracks **lifecycle state** (queued → approved → applied → completed)
- Contains **metadata** (priority, tags, timestamps)
- Has a relationship to multiple **Work Items**

**Work Orders represent the business intent—the "contract" between requestor and system.**

---

### 2. Work Items

A **Work Item** is a discrete unit of work that an agent can lease, process, and submit results for.

```php
WorkItem {
    type: "user.data.sync"            // Same as parent order
    input: {                          // Specific instructions for agent
        batch_id: 1,
        user_ids: [1, 2, 3]
    }
    result: { ... }                   // Agent's submitted work
    state: ItemState                  // Current state (queued, leased, submitted, etc.)
    leased_by_agent_id: "agent-1"    // Current lease holder
    lease_expires_at: "2025-10-22T..." // Lease TTL
}
```

**Key Properties**:
- One order can have **many items** (batching/parallel execution)
- Items have **lease semantics** (TTL, heartbeat, reclaim)
- Each item has **input** (what to do) and **result** (what was done)
- Items track **attempts** and **errors**

**Work Items are the unit of agent execution—the "tasks" agents check out and complete.**

---

### 3. Order Types

An **Order Type** defines the complete behavior and lifecycle of a category of work.

```php
interface OrderType {
    public function type(): string;                    // Identifier (e.g., "user.data.sync")
    public function schema(): array;                   // JSON schema for payload validation
    public function plan(WorkOrder $order): array;     // Break order into work items
    public function apply(WorkOrder $order): Diff;     // Execute the work (idempotent!)
}
```

**What Order Types Define**:

1. **Schema**: What data is required in the payload
2. **Planning**: How to break work into items (batching strategy)
3. **Validation**: How to verify agent submissions
4. **Execution**: How to apply approved work (database operations, API calls, etc.)
5. **Lifecycle Hooks**: Before/after execution logic

**Example Order Types**:
- `user.data.sync` - Synchronize user data from external CRM
- `database.record.insert` - Batch insert records with verification
- `customer.research` - AI research task with evidence collection
- `content.fact.check` - Fact-check content against sources

**Order Types are the extension point—you implement them to define your domain-specific work.**

---

### 4. State Machine

Work orders and items follow **strict state transitions** enforced by the system:

#### Work Order States

```
queued → checked_out → in_progress → submitted → approved → applied → completed
```

**Failed/Rejected Paths**:
```
submitted → rejected → queued (rework)
                    → dead_lettered (abandon)
any state → failed → dead_lettered
```

#### Work Item States

```
queued → leased → in_progress → submitted → accepted → completed
```

**Failed Path**:
```
leased → (lease expires) → queued (retry)
                        → failed (max attempts) → dead_lettered
```

**Enforcement**: The `StateMachine` service validates all transitions and **automatically records events**. Invalid transitions throw `IllegalStateTransitionException`.

---

### 5. Leasing System

Work items use a **TTL-based lease system** to prevent concurrent processing:

**How It Works**:
1. Agent calls `checkout` → acquires exclusive lease on a work item
2. Lease has TTL (default 600 seconds, configurable)
3. Agent must send `heartbeat` every 120 seconds to maintain lease
4. If lease expires, item is automatically reclaimed and re-queued
5. Only one agent can hold a lease at a time

**Lease Backends**:
- **Database** (default): Uses row-level locks (`SELECT FOR UPDATE`)
- **Redis**: Uses `SET NX EX` pattern for better performance at scale

**Concurrency Controls** (optional):
- Max leases per agent (prevent resource hogging)
- Max leases per type (control system load)
- Rate limiting per agent/type

**This prevents race conditions and ensures exactly-once processing semantics.**

---

### 6. Two-Phase Verification

Laravel Work Manager uses a **two-phase verification** approach:

#### Phase 1: Agent Submission Validation (Tactical)

Validates **individual work item submissions** as agents submit them:

```php
// Laravel validation rules
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'status' => 'required|in:success,failed',
        'data' => 'required|array',
        'data.*.verified' => 'required|boolean|accepted',
    ];
}

// Custom business logic
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify data exists in external system
    if (!$this->externalApi->verify($result['data'])) {
        throw ValidationException::withMessages([
            'data' => ['External verification failed'],
        ]);
    }
}
```

**Purpose**: Ensure agent work meets basic requirements and business rules.

#### Phase 2: Approval Readiness (Strategic)

Validates **entire work order** before execution:

```php
protected function canApprove(WorkOrder $order): bool
{
    // All items must be submitted
    if (!$order->allItemsSubmitted()) {
        return false;
    }

    // Cross-item validation
    foreach ($order->items as $item) {
        if (!$item->result['verified'] ?? false) {
            return false;
        }
    }

    return true;
}
```

**Purpose**: Ensure the complete work is ready for production execution.

**This separation allows for tactical validation during agent work and strategic validation before system changes.**

---

### 7. Idempotency

All mutating operations support **idempotency keys** to make retries safe:

```http
POST /api/ai/work/propose
X-Idempotency-Key: propose-123-abc

POST /api/ai/work/items/456/submit
X-Idempotency-Key: submit-456-xyz
```

**How It Works**:
1. Client includes idempotency key in header (configurable name)
2. System hashes and stores the key with request scope
3. Response is cached for the key
4. Retry with same key returns cached response (no duplicate execution)

**Enforced Operations**:
- `propose` - Creating work orders
- `submit` - Submitting work item results
- `submit-part` - Submitting partial results
- `finalize` - Finalizing assembled results
- `approve` - Approving orders
- `reject` - Rejecting orders

**This ensures agent retries (network failures, timeouts) don't create duplicate work or mutations.**

---

### 8. Partial Submissions

For complex or long-running work items, agents can submit results **incrementally**:

```http
# Submit first part
POST /api/ai/work/items/123/submit-part
{
    "part_key": "research_findings",
    "seq": 1,
    "payload": { /* research data */ }
}

# Submit second part
POST /api/ai/work/items/123/submit-part
{
    "part_key": "evidence",
    "seq": 1,
    "payload": { /* evidence data */ }
}

# Finalize (assemble all parts into final result)
POST /api/ai/work/items/123/finalize
```

**Benefits**:
- **Resume across sessions**: Agent can submit parts and continue later
- **Incremental validation**: Each part is validated independently
- **Large results**: Avoid timeout issues with very large results
- **Progress tracking**: System tracks which parts are submitted

**Configuration**:
```php
'partials' => [
    'enabled' => true,
    'max_parts_per_item' => 100,
    'max_payload_bytes' => 1048576, // 1MB default
]
```

**Use Cases**:
- Research tasks with multiple sources
- Multi-step data collection
- Content generation with sections
- Complex calculations with checkpoints

---

### 9. Events and Auditability

Every action generates **Laravel events** with full provenance:

**Order Events**:
- `WorkOrderProposed` - Order created
- `WorkOrderPlanned` - Items created
- `WorkOrderCheckedOut` - First item leased
- `WorkOrderApproved` - Order approved for execution
- `WorkOrderApplied` - Changes applied (includes Diff)
- `WorkOrderCompleted` - All items complete
- `WorkOrderRejected` - Order rejected

**Item Events**:
- `WorkItemLeased` - Item checked out by agent
- `WorkItemHeartbeat` - Lease extended
- `WorkItemSubmitted` - Agent submitted result
- `WorkItemFinalized` - Parts assembled into final result
- `WorkItemFailed` - Item failed
- `WorkItemLeaseExpired` - Lease expired

**Partial Submission Events**:
- `WorkItemPartSubmitted` - Part submitted
- `WorkItemPartValidated` - Part validated successfully
- `WorkItemPartRejected` - Part validation failed

**Event Data Includes**:
- **Actor**: Who performed the action (agent, user, system)
- **Payload**: Action-specific data
- **Diff**: Before/after snapshots (for apply operations)
- **Message**: Human-readable description
- **Timestamp**: When the action occurred

**Storage**: All events are stored in `work_events` table for compliance and debugging.

---

## How It Solves Work Management Problems

### Single Auditable Path for All Mutations

**Problem**: Direct database mutations bypass audit trails and approval gates.

**Solution**: Attach `EnforceWorkOrderOnly` middleware to legacy routes:

```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);
```

This ensures **all mutations flow through the work order system**, creating a complete audit trail.

---

### Safe Concurrent Execution

**Problem**: Multiple agents/workers processing the same item simultaneously causes race conditions.

**Solution**: TTL-based leasing with heartbeat ensures **exactly-once processing**. Expired leases are automatically reclaimed.

---

### Typed, Validated Work

**Problem**: Inconsistent validation across different work types and stages.

**Solution**: Order types define:
- **Schema validation** for payloads (JSON Schema)
- **Laravel validation** for submissions
- **Custom verification logic** in hooks
- **Approval readiness** checks before execution

---

### Idempotent Execution

**Problem**: Retries cause duplicate operations (double charges, duplicate records, etc.).

**Solution**:
- **Idempotency keys** for all mutating API calls
- **Idempotent `apply()` methods** in order types (must be safe to retry)
- **Database transactions** around all mutations

---

### Clear Agent UX

**Problem**: Complex APIs that are hard for AI agents to use correctly.

**Solution**: Simple, linear workflow:
1. `checkout` → Get work item
2. `heartbeat` → Keep lease alive while working
3. `submit` (or `submit-part` + `finalize`) → Return results
4. Wait for `approve` → System applies changes

**MCP Integration**: Built-in MCP server exposes work tools to AI IDEs (Cursor, Claude Desktop).

---

### Strong Observability

**Problem**: Black-box operations with no visibility into what happened.

**Solution**:
- **Event stream** for every action
- **Work provenance** tracking (agent metadata, request fingerprints)
- **Diff records** showing before/after state
- **Query endpoints** for logs and history

Subscribe to events for monitoring:
```php
Event::listen(WorkOrderApplied::class, function($event) {
    // Send to SIEM, metrics, alerts, etc.
});
```

---

## Real-World Use Cases

### 1. User Data Synchronization

**Scenario**: Sync user data from external CRM to local database.

```php
// Order: sync 1000 users
Order { type: "user.data.sync", payload: { source: "crm", user_ids: [1..1000] } }

// Plan: Break into 10 batches of 100 users each
Items: [
    { batch_id: 1, user_ids: [1..100] },
    { batch_id: 2, user_ids: [101..200] },
    ...
]

// Agent: Process each batch
foreach (batch in items) {
    data = fetchFromCRM(batch.user_ids)
    submit({ success: true, synced_users: data })
}

// Apply: Update local database
foreach (item in order.items) {
    User::updateOrCreate(item.result.synced_users)
}
```

---

### 2. AI Research Tasks

**Scenario**: AI agent researches a topic and provides findings with evidence.

```php
// Order: research topic
Order { type: "customer.research", payload: { customer_id: 123, topics: [...] } }

// Agent: Submit parts incrementally
submit_part("research_findings", { findings: [...] })
submit_part("evidence", { sources: [...], credibility: "high" })
submit_part("summary", { executive_summary: "..." })
finalize()  // Assemble all parts

// Verify: Check evidence quality
afterValidateSubmission() {
    ensure all sources are credible
    ensure quotes match sources
}

// Apply: Store in knowledge base
apply() {
    CustomerKnowledge::create(assembled_result)
}
```

---

### 3. Database Record Insertion

**Scenario**: Batch insert records with verification.

```php
// Order: insert 500 records
Order { type: "database.record.insert", payload: { table: "products", records: [...] } }

// Plan: Batches of 50
Items: [ { batch: 1, records: [1..50] }, ... ]

// Agent: Verify and prepare each batch
submit({
    records: [ { id: 1, verified: true, data: {...} }, ... ]
})

// Apply: Insert with idempotency
DB::transaction(function() {
    foreach (item.result.records as record) {
        Product::updateOrCreate(['id' => record.id], record.data)
    }
})
```

---

## Integration with Laravel

### Native Laravel Features

**Validation**:
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'email' => 'required|email|unique:users',
        'data.*.field' => 'required|string|min:3',
    ];
}
```

**Events**:
```php
Event::listen(WorkOrderApplied::class, function($event) {
    Log::info('Order applied', ['diff' => $event->diff]);
});
```

**Jobs/Queues**:
```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    SendNotifications::dispatch($order)->onQueue('notifications');
}
```

**Policies**:
```php
Gate::allows('approve', $order);  // Uses WorkOrderPolicy
```

**Database**:
```php
DB::transaction(function() use ($order) {
    // Your mutations here
});
```

---

## Architecture Principles

1. **Idempotency First**: All mutations are idempotent and replayable
2. **State Machine Enforcement**: Never bypass state transitions
3. **Two-Phase Verification**: Validate submissions tactically, verify readiness strategically
4. **Auditability**: Every action generates events with provenance
5. **Lease-Based Concurrency**: Prevent race conditions with TTL leases
6. **Type Safety**: JSON schemas for payloads, Laravel validation for submissions
7. **Agent Ergonomics**: Simple, clear API for AI agents
8. **Extension Points**: Order types, validation hooks, lifecycle hooks

---

## See Also

- [Architecture Overview](architecture-overview.md) - System design and data flows
- [Lifecycle and Flow](lifecycle-and-flow.md) - Complete work order lifecycle
- [State Management](state-management.md) - State machine deep dive
- [Configuration Model](configuration-model.md) - Configuration and precedence
- [Security and Permissions](security-and-permissions.md) - Authentication and authorization
- [Quick Start Guide](../examples/QUICK_START.md) - Get started in 5 minutes
- [Lifecycle Hooks Guide](../examples/LIFECYCLE.md) - All available hooks
- [MCP Server Documentation](../MCP_SERVER.md) - AI agent integration
