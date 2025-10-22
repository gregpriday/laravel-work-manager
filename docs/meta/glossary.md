# Glossary

Definitions of key terms and concepts used in Laravel Work Manager.

## Core Concepts

### Work Order

**Definition**: A high-level contract representing a complete unit of work to be performed.

**Contains**: Type, payload (input data), state, priority, metadata

**Example**: "Sync 100 users from CRM" is a work order that might be broken into multiple work items.

**Related**: Work Item, Order Type

---

### Work Item

**Definition**: An individual, indivisible unit of work that can be leased and processed by a single agent.

**Contains**: Type, input data, result, state, lease information, attempt count

**Relationship**: One Work Order contains one or more Work Items

**Example**: Within the "Sync 100 users" order, "Sync users 1-10" is one work item.

**Related**: Lease, Agent, Work Order

---

### Order Type

**Definition**: A class that defines the complete lifecycle of a category of work, including schema, planning, validation, and execution logic.

**Implements**: `OrderType` interface or extends `AbstractOrderType`

**Defines**:
- `type()`: String identifier (e.g., "user.data.sync")
- `schema()`: JSON schema for payload validation
- `plan()`: How to break order into items
- `apply()`: Idempotent execution logic
- Validation hooks for agent submissions

**Example**: `UserDataSyncType` defines how user data sync orders are processed.

**Related**: Work Order, Acceptance Policy

---

### Lease

**Definition**: A time-limited lock that grants exclusive access to a work item for processing.

**Properties**:
- **TTL** (Time To Live): How long the lease lasts (default 600 seconds)
- **Leased By**: Agent identifier holding the lease
- **Expires At**: When the lease expires

**Purpose**: Prevents multiple agents from processing the same work item concurrently

**Lifecycle**: Acquire → Heartbeat (to extend) → Release (explicit) or Expire (automatic)

**Related**: Work Item, Agent, Heartbeat

---

### Agent

**Definition**: An external entity (AI system, background worker, human operator) that checks out work items, processes them, and submits results.

**Identified By**: Agent ID (string, e.g., "claude-agent-1", "worker-server-3")

**Capabilities**:
- Propose work orders
- Check out (lease) work items
- Heartbeat to maintain leases
- Submit results
- Release leases

**Example**: An AI assistant using Claude via MCP to process research tasks.

**Related**: Lease, Work Item, MCP

---

### State Machine

**Definition**: A system that enforces valid transitions between states for work orders and work items.

**Purpose**: Ensures data integrity by preventing invalid state changes

**Work Order States**:
```
queued → checked_out → in_progress → submitted →
approved → applied → completed
```

**Work Item States**:
```
queued → leased → in_progress → submitted →
accepted → completed
```

**Related**: Work Order, Work Item, Events

---

### Idempotency Key

**Definition**: A unique string provided by the client to ensure an operation is performed only once, even if the request is retried.

**Format**: Arbitrary string, typically descriptive (e.g., "propose-user-sync-123")

**Header**: `X-Idempotency-Key` (configurable)

**Required For**: propose, submit, submit-part, finalize, approve, reject

**Purpose**: Prevents duplicate operations due to network retries or agent errors

**Example**:
```bash
curl -H "X-Idempotency-Key: submit-item-abc-attempt-1" ...
```

**Related**: HTTP API, MCP Tools

---

### Acceptance Policy

**Definition**: A class or interface that defines validation rules for agent submissions and approval readiness checks.

**Can Be**:
- Built into AbstractOrderType (via hooks)
- Separate class implementing `AcceptancePolicy` interface

**Defines**:
- `validateSubmission()`: Validate individual item submissions
- `readyForApproval()`: Check if order is ready to be approved

**Example**: Verifying that all required fields are present and external APIs confirm the data.

**Related**: Order Type, Validation, Verification

---

### Provenance

**Definition**: Metadata about who performed an action and under what circumstances.

**Stored In**: `work_provenance` table

**Contains**:
- Agent ID, name, version
- Model name (for AI agents)
- Request fingerprint
- IP address, user agent
- Timestamp

**Purpose**: Auditability, compliance, debugging

**Related**: Work Event, Agent

---

### Diff

**Definition**: A structured representation of changes made during the `apply()` execution.

**Structure**:
- **Before**: State before changes
- **After**: State after changes
- **Changes**: Computed differences
- **Summary**: Human-readable description

**Purpose**: Audit trail, rollback information, observability

**Example**:
```php
return $this->makeDiff(
    ['count' => 0],
    ['count' => 10],
    'Synced 10 users'
);
```

**Related**: Apply, Work Event

---

## Lifecycle Phases

### Propose

**Definition**: The initial creation of a work order.

**Who**: Agent or system

**Input**: Type and payload (validated against schema)

**Output**: Work Order in `queued` state

**API**: `POST /api/work/propose`

**Related**: Work Order, Order Type, Schema

---

### Plan

**Definition**: The process of breaking a work order into discrete work items.

**Who**: System (calls `OrderType::plan()`)

**When**: Immediately after proposal

**Output**: One or more Work Items in `queued` state

**Customization**: Override `plan()` method in your order type

**Related**: Work Order, Work Item

---

### Checkout

**Definition**: The process of acquiring a lease on a work item for processing.

**Who**: Agent

**When**: Agent is ready to process work

**Result**: Work Item transitions to `leased`, lease established with TTL

**API**: `POST /api/work/orders/{order}/checkout`

**Related**: Lease, Agent, Work Item

---

### Heartbeat

**Definition**: A signal from the agent to extend the lease on a work item.

**Who**: Agent (must be the lease holder)

**When**: Periodically during processing (default every 120 seconds)

**Purpose**: Keeps lease alive for long-running operations

**API**: `POST /api/work/items/{item}/heartbeat`

**Related**: Lease, Agent

---

### Submit

**Definition**: The process of providing completed work item results for validation.

**Who**: Agent

**When**: After processing is complete

**Validation**: Runs submission validation rules and custom business logic

**Result**: If valid, item transitions to `submitted`; if invalid, stays in current state with errors

**API**: `POST /api/work/items/{item}/submit`

**Related**: Work Item, Validation, Acceptance Policy

---

### Approve

**Definition**: The action of accepting all submitted work items and executing the order's `apply()` logic.

**Who**: Backend user or system (requires authorization)

**When**: After all items are submitted and validated

**Result**: Order transitions through `approved` → `applied` → `completed`

**API**: `POST /api/work/orders/{order}/approve`

**Related**: Apply, Authorization, Diff

---

### Apply

**Definition**: The execution phase where the order type's `apply()` method performs actual domain changes (database writes, API calls, etc.).

**Who**: System (automatically after approval)

**When**: Immediately after approval

**Requirements**: Must be idempotent (can be called multiple times safely)

**Output**: Diff object describing changes

**Related**: Order Type, Idempotency, Diff

---

### Reject

**Definition**: The action of declining a submitted order, typically due to validation failures or business rule violations.

**Who**: Backend user or system

**When**: During review of submitted order

**Options**: Can allow rework (re-queue items) or dead-letter

**API**: `POST /api/work/orders/{order}/reject`

**Related**: Work Order, Dead Letter

---

### Release

**Definition**: Explicitly releasing a lease on a work item, returning it to `queued` state.

**Who**: Agent (must be the lease holder)

**When**: When agent cannot or will not complete the work

**Result**: Item returns to `queued`, can be checked out by another agent

**API**: `POST /api/work/items/{item}/release`

**Related**: Lease, Work Item

---

## State Concepts

### State

**Definition**: The current phase in the lifecycle of a work order or work item.

**Work Order States**:
- `queued`: Newly created, not yet started
- `checked_out`: Agent has leased at least one item
- `in_progress`: Agent is actively working
- `submitted`: All items submitted, awaiting approval
- `approved`: Approved, ready for execution
- `applied`: Changes have been executed
- `completed`: Successfully finished
- `rejected`: Declined by reviewer
- `failed`: Error during processing
- `dead_lettered`: Failed permanently

**Work Item States**:
- `queued`: Available for checkout
- `leased`: Locked by an agent
- `in_progress`: Agent is processing
- `submitted`: Results submitted, awaiting acceptance
- `accepted`: Approved for inclusion in order
- `completed`: Successfully finished
- `rejected`: Declined by validator
- `failed`: Error during processing
- `dead_lettered`: Failed permanently

**Related**: State Machine, Transitions

---

### Transition

**Definition**: A change from one state to another, governed by the state machine.

**Rules**: Only specific transitions are allowed (configured in `config/work-manager.php`)

**Example**: `queued → leased → submitted → accepted → completed`

**Enforcement**: Attempting invalid transition throws `IllegalStateTransitionException`

**Related**: State Machine, State

---

### Dead Letter

**Definition**: The final state for work that has failed permanently and cannot be recovered.

**Causes**:
- Max retry attempts exceeded
- Permanent error condition
- Manual dead-lettering by admin

**Recovery**: Can be manually requeued by admin after fixing root cause

**Purpose**: Prevents infinite retry loops, surfaces systematic failures

**Related**: State, Failed, Maintenance

---

## Validation Concepts

### Schema

**Definition**: A JSON Schema definition describing the required structure and types of a work order's payload.

**Purpose**: Validates proposal payloads before creating work orders

**Example**:
```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['user_ids', 'source'],
        'properties' => [
            'user_ids' => ['type' => 'array'],
            'source' => ['type' => 'string'],
        ],
    ];
}
```

**Related**: Order Type, Validation, Propose

---

### Validation

**Definition**: The process of verifying that data meets specified rules and requirements.

**Types**:
1. **Schema Validation**: Payload matches JSON schema (at proposal)
2. **Submission Validation**: Results match validation rules (at submit)
3. **Business Logic Validation**: Custom checks (in `afterValidateSubmission`)
4. **Approval Readiness**: Order-level checks (in `canApprove`)

**Framework**: Uses Laravel's validation system

**Related**: Acceptance Policy, Schema, Submit

---

### Verification

**Definition**: Broader term encompassing validation plus additional checks (evidence review, external API verification, etc.).

**Two-Phase**:
1. **Submission**: Per-item validation when agent submits
2. **Approval**: Cross-item validation before applying

**Related**: Validation, Acceptance Policy

---

## Advanced Concepts

### Partial Submission

**Definition**: A feature allowing agents to submit work item results incrementally in multiple parts, rather than all at once.

**Use Cases**: Large research tasks, multi-step processes, resumable work

**Endpoints**:
- `POST /items/{item}/submit-part`: Submit one part
- `GET /items/{item}/parts`: List all parts
- `POST /items/{item}/finalize`: Assemble all parts into final result

**Configuration**: Enable/disable and set limits in `config/work-manager.php`

**Related**: Work Item, Submit, Finalize

---

### Finalize

**Definition**: The process of assembling all partial submissions into the final work item result.

**Who**: Agent (after submitting all parts)

**Validation**: Each part validated independently, final result validated as whole

**Transition**: Item moves from `in_progress` to `submitted`

**API**: `POST /api/work/items/{item}/finalize`

**Related**: Partial Submission, Submit

---

### MCP (Model Context Protocol)

**Definition**: A standardized protocol for AI-application integration, allowing AI agents to discover and call tools.

**Purpose**: Provides AI agents structured access to Work Manager functionality

**Transports**:
- **STDIO**: Local process communication (for AI IDEs)
- **HTTP**: Remote access via Server-Sent Events

**Tools**: 13 MCP tools map to Work Manager operations (propose, checkout, submit, etc.)

**Related**: Agent, HTTP API

---

### Registry

**Definition**: A service that manages registered order types, making them available to the system.

**Usage**:
```php
WorkManager::registry()->register(new UserSyncType());
$type = WorkManager::registry()->get('user.sync');
```

**Location**: Singleton service, typically populated in `AppServiceProvider::boot()`

**Related**: Order Type

---

### Maintenance

**Definition**: Background process that reclaims expired leases, dead-letters failed work, and alerts on stale orders.

**Command**: `php artisan work-manager:maintain`

**Frequency**: Run every minute via scheduler

**Operations**:
- Reclaim expired leases
- Retry failed items (if attempts < max)
- Dead-letter permanently failed items
- Alert on stale orders

**Related**: Lease, Dead Letter, Failed

---

### Idempotency

**Definition**: The property that an operation can be performed multiple times with the same result, causing no additional side effects after the first successful execution.

**Requirement**: The `apply()` method MUST be idempotent

**Implementation**: Use `updateOrCreate`, check for existing state, wrap in transactions

**Example**:
```php
public function apply(WorkOrder $order): Diff
{
    DB::transaction(function () use ($order) {
        User::updateOrCreate(
            ['id' => $order->payload['user_id']],
            ['name' => $order->payload['name']]
        );
    });
}
```

**Related**: Apply, Idempotency Key

---

### Auditability

**Definition**: The ability to trace all actions, changes, and decisions made within the system.

**Implemented Via**:
- **Work Events**: Every state transition and action
- **Work Provenance**: Agent metadata for all operations
- **Diffs**: Before/after snapshots of changes

**Purpose**: Compliance, debugging, accountability

**Related**: Work Event, Provenance, Diff

---

### Work Event

**Definition**: A record of any significant action or state transition in the work order lifecycle.

**Stored In**: `work_events` table

**Contains**:
- Event type (e.g., "proposed", "submitted", "applied")
- Actor (user, agent, system)
- Timestamp
- Payload (context data)
- Diff (for apply events)
- Message (human-readable description)

**Purpose**: Complete audit trail

**Related**: State Machine, Auditability, Provenance

---

### Authorization

**Definition**: The process of determining whether a user or agent has permission to perform an action.

**Mechanism**: Laravel policies configured in `config/work-manager.php`

**Policies**:
- `propose`: Can create work orders
- `checkout`: Can lease work items
- `submit`: Can submit results
- `approve`: Can approve orders
- `reject`: Can reject orders

**Related**: Authentication, Policies

---

### TTL (Time To Live)

**Definition**: The duration (in seconds) that a lease remains valid before expiring.

**Default**: 600 seconds (10 minutes)

**Configuration**: `config/work-manager.php` → `lease.ttl_seconds`

**Purpose**: Ensures stuck agents don't hold leases indefinitely

**Related**: Lease, Heartbeat, Expiration

---

## Acronyms

- **API**: Application Programming Interface
- **HTTP**: HyperText Transfer Protocol
- **MCP**: Model Context Protocol
- **JSON**: JavaScript Object Notation
- **TTL**: Time To Live
- **CRUD**: Create, Read, Update, Delete
- **DTO**: Data Transfer Object
- **REST**: Representational State Transfer

---

## See Also

- [README.md](../../README.md) - Main documentation
- [ARCHITECTURE.md](../ARCHITECTURE.md) - System design
- [FAQ](../troubleshooting/faq.md) - Common questions
- [Common Errors](../troubleshooting/common-errors.md) - Troubleshooting
