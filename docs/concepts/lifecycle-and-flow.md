# Lifecycle and Flow

## Introduction

This document provides a comprehensive guide to the complete lifecycle of a work order, from proposal through completion. Understanding this flow is essential for implementing custom order types and integrating with the work manager system.

---

## Complete Lifecycle Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                    WORK ORDER LIFECYCLE                              │
└─────────────────────────────────────────────────────────────────────┘

1. PROPOSE    →  2. PLAN     →  3. CHECKOUT  →  4. PROCESS   →  5. SUBMIT
    │                │              │              │               │
    │                │              │              │               │
    ▼                ▼              ▼              ▼               ▼
  Create         Break into     Agent leases   Agent does      Agent returns
  work order     work items     work item      the work        results
    │                │              │              │               │
    │                │              │              │               │
    └────────────────┴──────────────┴──────────────┴───────────────┘
                                                                    │
                                                                    │
    ┌───────────────────────────────────────────────────────────────┘
    │
    ▼
6. VERIFY    →  7. APPROVE   →  8. APPLY     →  9. COMPLETE
    │                │              │               │
    │                │              │               │
    ▼                ▼              ▼               ▼
  Validate      Backend/User    Execute domain   Mark order
  submission    approves work   changes          as complete
```

---

## Phase 1: Proposal

### Purpose
Create a work order representing the intent to perform work of a specific type.

### Who
- System (automated rules)
- Agent (proposing new work)
- User (manual request)

### API Endpoint
```http
POST /api/ai/work/propose
X-Idempotency-Key: propose-{uuid}
Content-Type: application/json

{
    "type": "user.data.sync",
    "payload": {
        "source": "crm",
        "user_ids": [1, 2, 3, 4, 5]
    },
    "priority": "normal",
    "meta": {
        "requestor": "automated-scanner",
        "scan_id": "scan-123"
    }
}
```

### Order Type Hook
```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['source', 'user_ids'],
        'properties' => [
            'source' => [
                'type' => 'string',
                'enum' => ['crm', 'analytics'],
            ],
            'user_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'minItems' => 1,
                'maxItems' => 1000,
            ],
        ],
    ];
}
```

### System Actions
1. Validate payload against schema
2. Create `WorkOrder` (state: `queued`)
3. Create `WorkProvenance` (agent metadata, request fingerprint)
4. Store idempotency key
5. Emit `WorkOrderProposed` event
6. Return work order

### State Transition
```
(none) → queued
```

### Example Response
```json
{
    "id": 42,
    "type": "user.data.sync",
    "state": "queued",
    "payload": { "source": "crm", "user_ids": [1, 2, 3, 4, 5] },
    "priority": "normal",
    "created_at": "2025-10-22T10:00:00Z"
}
```

---

## Phase 2: Planning

### Purpose
Break the work order into discrete work items that agents can lease and process.

### Who
- System (automatically after proposal)

### Order Type Hook
```php
public function plan(WorkOrder $order): array
{
    $userIds = $order->payload['user_ids'];
    $batchSize = 100;
    $batches = array_chunk($userIds, $batchSize);

    return array_map(function ($batch, $index) {
        return [
            'type' => $this->type(),
            'input' => [
                'batch_id' => $index + 1,
                'source' => $order->payload['source'],
                'user_ids' => $batch,
            ],
            'max_attempts' => 3,
        ];
    }, $batches, array_keys($batches));
}
```

### System Actions
1. Call order type's `plan()` method
2. Create `WorkItem` records (state: `queued`)
3. Emit `WorkOrderPlanned` event
4. Return items

### State Transition
```
Order: queued (unchanged)
Items: (none) → queued
```

### Example Planning Output
```
Order #42: user.data.sync (5 users)
├─ Item #101: batch 1 (users 1, 2, 3, 4, 5)
```

For larger orders:
```
Order #43: user.data.sync (250 users)
├─ Item #102: batch 1 (users 1-100)
├─ Item #103: batch 2 (users 101-200)
└─ Item #104: batch 3 (users 201-250)
```

---

## Phase 3: Checkout (Lease Acquisition)

### Purpose
Agent acquires an exclusive lease on a work item to process.

### Who
- Agent

### API Endpoint
```http
POST /api/ai/work/orders/42/checkout
X-Agent-ID: agent-1
X-Agent-Name: SyncAgent
X-Agent-Version: 1.0.0
```

### System Actions
1. Find next available (queued) item for the order
2. Check concurrency limits (if configured)
3. Acquire lease (database lock or Redis SET NX)
4. Set `leased_by_agent_id` and `lease_expires_at` (TTL)
5. Transition item: `queued → leased`
6. Transition order: `queued → checked_out` (first checkout)
7. Emit `WorkItemLeased` and `WorkOrderCheckedOut` events
8. Return work item with input

### State Transitions
```
Order: queued → checked_out (on first item checkout)
Item: queued → leased
```

### Lease Properties
- **TTL**: Default 600 seconds (configurable)
- **Owner**: Agent ID
- **Heartbeat Required**: Every 120 seconds (configurable)
- **Exclusive**: Only one agent can lease an item at a time

### Example Response
```json
{
    "id": 101,
    "order_id": 42,
    "type": "user.data.sync",
    "state": "leased",
    "input": {
        "batch_id": 1,
        "source": "crm",
        "user_ids": [1, 2, 3, 4, 5]
    },
    "leased_by_agent_id": "agent-1",
    "lease_expires_at": "2025-10-22T10:10:00Z"
}
```

---

## Phase 4: Processing (Agent Work)

### Purpose
Agent performs the actual work described in the item's input.

### Who
- Agent

### Agent Actions
1. Receive work item input
2. Perform domain-specific work:
   - Fetch data from external systems
   - Process/transform data
   - Verify results
   - Collect evidence
3. Send heartbeat every 2 minutes to maintain lease

### Heartbeat API
```http
POST /api/ai/work/items/101/heartbeat
X-Agent-ID: agent-1
```

### System Actions (Heartbeat)
1. Verify agent owns the lease
2. Extend `lease_expires_at` by TTL
3. Emit `WorkItemHeartbeat` event
4. Return updated item

### State Transitions
```
Item: leased → in_progress (on first heartbeat)
Order: checked_out → in_progress (when first item moves to in_progress)
```

### Lease Expiration
If agent fails to heartbeat:
- Lease expires automatically
- `work-manager:maintain` command reclaims item
- Item transitions: `in_progress → queued` (if attempts remain)
- Or: `in_progress → failed → dead_lettered` (if max attempts exceeded)

---

## Phase 5: Submission

### Purpose
Agent submits completed work for verification.

### Who
- Agent

### Submission Types

#### Option A: Complete Submission (Single Call)

```http
POST /api/ai/work/items/101/submit
X-Agent-ID: agent-1
X-Idempotency-Key: submit-101-xyz
Content-Type: application/json

{
    "result": {
        "success": true,
        "synced_users": [
            {
                "user_id": 1,
                "verified": true,
                "data": { "name": "John Doe", "email": "john@example.com" }
            },
            {
                "user_id": 2,
                "verified": true,
                "data": { "name": "Jane Smith", "email": "jane@example.com" }
            }
            // ... more users
        ]
    },
    "evidence": {
        "crm_api_response_hash": "abc123...",
        "verification_passed": true
    },
    "notes": "All users verified against CRM"
}
```

#### Option B: Partial Submission (Multiple Calls)

For complex or long-running work:

```http
# Step 1: Submit first part
POST /api/ai/work/items/101/submit-part
X-Agent-ID: agent-1
X-Idempotency-Key: part-101-1
Content-Type: application/json

{
    "part_key": "users_batch_1",
    "seq": 1,
    "payload": {
        "users": [
            { "user_id": 1, "verified": true, "data": {...} },
            { "user_id": 2, "verified": true, "data": {...} }
        ]
    }
}

# Step 2: Submit second part
POST /api/ai/work/items/101/submit-part
X-Agent-ID: agent-1
X-Idempotency-Key: part-101-2

{
    "part_key": "users_batch_2",
    "seq": 2,
    "payload": {
        "users": [
            { "user_id": 3, "verified": true, "data": {...} },
            { "user_id": 4, "verified": true, "data": {...} }
        ]
    }
}

# Step 3: Finalize (assemble all parts)
POST /api/ai/work/items/101/finalize
X-Agent-ID: agent-1
X-Idempotency-Key: finalize-101
Content-Type: application/json

{
    "mode": "strict"  // or "lenient"
}
```

### Order Type Hooks

#### Complete Submission Validation
```php
// Laravel validation rules
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'synced_users' => 'required|array',
        'synced_users.*.user_id' => 'required|integer',
        'synced_users.*.verified' => 'required|boolean|accepted',
        'synced_users.*.data' => 'required|array',
    ];
}

// Custom business logic validation
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify all users in batch were processed
    $expectedIds = $item->input['user_ids'];
    $syncedIds = array_column($result['synced_users'], 'user_id');

    if (count(array_diff($expectedIds, $syncedIds)) > 0) {
        throw ValidationException::withMessages([
            'synced_users' => ['Not all users in batch were synced'],
        ]);
    }

    // Verify data with external system
    foreach ($result['synced_users'] as $user) {
        if (!$this->externalApi->verify($user['user_id'], $user['data'])) {
            throw ValidationException::withMessages([
                'synced_users' => ["User {$user['user_id']} failed external verification"],
            ]);
        }
    }
}
```

#### Partial Submission Validation
```php
// Validation rules for each part
protected function partialRules(WorkItem $item, string $partKey, ?int $seq): array
{
    if ($partKey === 'users_batch_1' || $partKey === 'users_batch_2') {
        return [
            'users' => 'required|array',
            'users.*.user_id' => 'required|integer',
            'users.*.verified' => 'required|boolean',
            'users.*.data' => 'required|array',
        ];
    }

    return [];
}

// Custom part validation
protected function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
{
    // Verify users in this part
    foreach ($payload['users'] as $user) {
        if (!$this->externalApi->verify($user['user_id'], $user['data'])) {
            throw ValidationException::withMessages([
                'users' => ["User {$user['user_id']} failed verification"],
            ]);
        }
    }
}

// Required parts for finalization
public function requiredParts(WorkItem $item): array
{
    return ['users_batch_1', 'users_batch_2'];
}

// Assemble parts into final result
public function assemble(WorkItem $item, Collection $parts): array
{
    $allUsers = [];

    foreach ($parts as $part) {
        $allUsers = array_merge($allUsers, $part->payload['users']);
    }

    return [
        'success' => true,
        'synced_users' => $allUsers,
    ];
}

// Validate assembled result
public function validateAssembled(WorkItem $item, array $assembled): void
{
    // Use the same validation as complete submission
    $this->afterValidateSubmission($item, $assembled);
}
```

### System Actions (Complete Submission)
1. Verify agent owns lease
2. Verify lease not expired
3. Call order type's validation:
   - `submissionValidationRules()` (Laravel validation)
   - `afterValidateSubmission()` (custom checks)
4. Store `item.result`
5. Transition item: `in_progress → submitted`
6. Emit `WorkItemSubmitted` event
7. Check if order should transition to `submitted` (all items submitted)
8. Check for auto-approval eligibility
9. Return item

### System Actions (Partial Submission)
1. Verify agent owns lease
2. Verify lease not expired
3. Call order type's validation:
   - `partialRules()` (Laravel validation for this part)
   - `afterValidatePart()` (custom checks)
4. Create/update `WorkItemPart` (status: `validated`)
5. Update `item.parts_state` (materialized view)
6. Emit `WorkItemPartSubmitted` and `WorkItemPartValidated` events
7. Return part

### System Actions (Finalize)
1. Check required parts present (strict mode)
2. Get latest validated parts
3. Call order type's assembly:
   - `assemble()` (combine parts)
   - `validateAssembled()` (validate final result)
4. Store `item.assembled_result` and `item.result`
5. Transition item: `in_progress → submitted`
6. Emit `WorkItemFinalized` event
7. Check for auto-approval eligibility
8. Return item

### State Transitions
```
Item: in_progress → submitted
Order: in_progress → submitted (when all items submitted)
```

### Validation Failure Handling
If validation fails:
- Item stays in current state
- Errors stored in `item.error`
- Agent can view errors and resubmit
- `ValidationException` thrown with structured errors

---

## Phase 6: Verification

### Purpose
System validates that all submitted work meets requirements before approval.

### Who
- System (automatic)

### Order Type Hook
```php
protected function canApprove(WorkOrder $order): bool
{
    // Check all items submitted
    if (!$order->allItemsSubmitted()) {
        return false;
    }

    // Cross-item validation
    $totalUsers = 0;
    foreach ($order->items as $item) {
        if (!$item->result['success'] ?? false) {
            return false;
        }

        $totalUsers += count($item->result['synced_users']);
    }

    // Verify total matches expected
    $expectedCount = count($order->payload['user_ids']);
    if ($totalUsers !== $expectedCount) {
        return false;
    }

    return true;
}
```

### System Actions
1. Automatically called after each item submission
2. Check if all items are in `submitted` state
3. Call order type's `canApprove()` method
4. If ready and auto-approval enabled → proceed to approval
5. Otherwise, wait for manual approval

### Approval Readiness Query
```http
GET /api/ai/work/orders/42
```

Response includes `ready_for_approval` flag:
```json
{
    "id": 42,
    "state": "submitted",
    "ready_for_approval": true,
    "items_submitted": 1,
    "items_total": 1
}
```

---

## Phase 7: Approval

### Purpose
Backend or user explicitly approves the work for execution.

### Who
- Backend user (manual review)
- System (auto-approval for trusted types)

### API Endpoint
```http
POST /api/ai/work/orders/42/approve
X-Idempotency-Key: approve-42-xyz
Authorization: Bearer {user_token}
```

### Order Type Hooks
```php
// Control auto-approval
public function shouldAutoApprove(): bool
{
    return false;  // Require manual approval (default)
}

// Additional approval checks
protected function canApprove(WorkOrder $order): bool
{
    // Custom approval logic
    return true;
}
```

### System Actions
1. Verify order state is `submitted`
2. Check authorization (policy)
3. Call acceptance policy's `readyForApproval()`
4. Call order type's `canApprove()`
5. Transition order: `submitted → approved`
6. Emit `WorkOrderApproved` event
7. Immediately call `apply()` (Phase 8)
8. Return order and diff

### State Transition
```
Order: submitted → approved → applied (immediate)
```

### Auto-Approval
If order type has `shouldAutoApprove() = true`:
- System automatically approves when ready
- No manual intervention required
- Use for deterministic, low-risk operations

---

## Phase 8: Apply (Execution)

### Purpose
Execute the domain-specific changes (database operations, API calls, etc.).

### Who
- System (automatically after approval)

### Order Type Hooks
```php
// Pre-execution hook
protected function beforeApply(WorkOrder $order): void
{
    Log::info('Starting user data sync', [
        'order_id' => $order->id,
        'items_count' => $order->items->count(),
    ]);

    // Setup: acquire locks, backup data, etc.
}

// Main execution (MUST be idempotent!)
public function apply(WorkOrder $order): Diff
{
    $before = ['synced_count' => 0];
    $syncedCount = 0;

    DB::transaction(function () use ($order, &$syncedCount) {
        foreach ($order->items as $item) {
            foreach ($item->result['synced_users'] as $syncedUser) {
                User::updateOrCreate(
                    ['id' => $syncedUser['user_id']],
                    $syncedUser['data']
                );
                $syncedCount++;
            }
        }
    });

    $after = ['synced_count' => $syncedCount];

    return $this->makeDiff(
        $before,
        $after,
        "Synced data for {$syncedCount} users from CRM"
    );
}

// Post-execution hook
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Cleanup: clear caches, send notifications, etc.
    Cache::tags(['users'])->flush();

    // Queue follow-up work
    SendSyncNotifications::dispatch($order)->onQueue('notifications');

    Log::info('User data sync completed', [
        'order_id' => $order->id,
        'diff' => $diff->toArray(),
    ]);
}
```

### System Actions
1. Call `beforeApply()` hook
2. Call order type's `apply()` method (in transaction)
3. Transition order: `approved → applied` (with diff)
4. Emit `WorkOrderApplied` event
5. Transition all items: `submitted → accepted → completed`
6. Call `afterApply()` hook
7. Return diff

### State Transitions
```
Order: approved → applied
Items: submitted → accepted → completed
```

### Idempotency Requirements
The `apply()` method **MUST be idempotent**:
- May be called multiple times with the same order
- Must produce the same result each time
- Use `updateOrCreate()` or similar patterns
- Check for existing state before mutating

### Diff Structure
```php
class Diff
{
    public function __construct(
        public array $before,
        public array $after,
        public string $message
    ) {}

    public function toArray(): array
    {
        return [
            'before' => $this->before,
            'after' => $this->after,
            'message' => $this->message,
        ];
    }
}
```

Example:
```json
{
    "before": { "synced_count": 0 },
    "after": { "synced_count": 5 },
    "message": "Synced data for 5 users from CRM"
}
```

---

## Phase 9: Completion

### Purpose
Mark the work order as complete once all items are processed.

### Who
- System (automatic)

### System Actions
1. After all items transition to `completed`
2. Transition order: `applied → completed`
3. Set `completed_at` timestamp
4. Emit `WorkOrderCompleted` event

### State Transition
```
Order: applied → completed
```

### Final State
```json
{
    "id": 42,
    "type": "user.data.sync",
    "state": "completed",
    "created_at": "2025-10-22T10:00:00Z",
    "applied_at": "2025-10-22T10:15:00Z",
    "completed_at": "2025-10-22T10:15:01Z",
    "items": [
        {
            "id": 101,
            "state": "completed",
            "accepted_at": "2025-10-22T10:15:00Z"
        }
    ]
}
```

---

## Failure Paths

### Submission Validation Failure

```
Item: in_progress → (stays in_progress)
```

Agent can:
- View validation errors
- Fix issues
- Resubmit with same idempotency key (overwrites)

---

### Approval Rejection

```http
POST /api/ai/work/orders/42/reject
X-Idempotency-Key: reject-42-xyz
Content-Type: application/json

{
    "errors": {
        "reason": "Data quality issues detected",
        "details": "User emails contain invalid domains"
    },
    "allow_rework": true
}
```

State transitions:
```
Order: submitted → rejected (allow_rework=false)
    or submitted → queued (allow_rework=true, for rework)
Items: submitted → rejected
```

---

### Lease Expiration

If agent fails to heartbeat:

```
Item: in_progress → queued (retry, if attempts remain)
   or in_progress → failed → dead_lettered (max attempts exceeded)
```

Maintenance command reclaims expired leases:
```bash
php artisan work-manager:maintain
```

---

### Apply Failure

If `apply()` throws an exception:

```
Order: applied → failed
Items: accepted → failed
```

Error recorded in `work_events` with exception details.

---

## State Transition Summary

### Work Order States

```
┌─────────┐
│ queued  │ ←───────────────────────────┐
└────┬────┘                             │
     │                                  │
     ▼                                  │
┌──────────────┐                        │
│ checked_out  │                        │
└──────┬───────┘                        │
       │                                │
       ▼                                │
┌─────────────┐                         │
│ in_progress │                         │
└──────┬──────┘                         │
       │                                │
       ▼                                │
┌───────────┐                           │
│ submitted │                           │
└─────┬─────┘                           │
      │                                 │
      ├─────────► ┌──────────┐         │
      │           │ rejected │─────────┤
      │           └──────────┘         │
      │                                │
      ▼                                │
┌──────────┐                            │
│ approved │                            │
└────┬─────┘                            │
     │                                  │
     ▼                                  │
┌─────────┐                             │
│ applied │                             │
└────┬────┘                             │
     │                                  │
     ▼                                  │
┌───────────┐                           │
│ completed │                           │
└───────────┘                           │
                                        │
┌────────┐                              │
│ failed │──────────────────────────────┤
└────────┘                              │
                                        │
┌────────────────┐                      │
│ dead_lettered  │ ◄────────────────────┘
└────────────────┘
```

### Work Item States

```
┌─────────┐
│ queued  │ ◄──────────────────────────┐
└────┬────┘                            │
     │                                 │
     ▼                                 │
┌────────┐                             │
│ leased │                             │
└───┬────┘                             │
    │                                  │
    ▼                                  │
┌─────────────┐                        │
│ in_progress │                        │
└──────┬──────┘                        │
       │                               │
       ▼                               │
┌───────────┐                          │
│ submitted │                          │
└─────┬─────┘                          │
      │                                │
      ├─────────► ┌──────────┐        │
      │           │ rejected │────────┤
      │           └──────────┘        │
      │                               │
      ▼                               │
┌──────────┐                           │
│ accepted │                           │
└────┬─────┘                           │
     │                                 │
     ▼                                 │
┌───────────┐                          │
│ completed │                          │
└───────────┘                          │
                                       │
┌────────┐                             │
│ failed │─────────────────────────────┤
└────────┘                             │
                                       │
┌────────────────┐                     │
│ dead_lettered  │ ◄───────────────────┘
└────────────────┘
```

---

## Timeline Example

**Complete end-to-end timeline for a user data sync order:**

```
T+0s    [PROPOSE]     Order #42 created (state: queued)
T+0s    [PLAN]        Item #101 created (state: queued)
T+1s    [CHECKOUT]    Agent-1 acquires lease on Item #101
                      Order → checked_out, Item → leased
T+120s  [HEARTBEAT]   Agent-1 extends lease, Item → in_progress
T+240s  [HEARTBEAT]   Agent-1 extends lease
T+300s  [SUBMIT]      Agent-1 submits results
                      Validation passes, Item → submitted
                      Order → submitted (all items submitted)
T+301s  [VERIFY]      System checks approval readiness
                      ready_for_approval = true
T+310s  [APPROVE]     Backend user approves order
                      Order → approved
T+310s  [APPLY]       System executes domain logic
                      Database operations complete
                      Diff recorded, Order → applied
                      Item → accepted → completed
T+311s  [COMPLETE]    Order → completed
```

**Total time: 311 seconds (~5 minutes)**

---

## Integration with Laravel Events

Subscribe to events to react to lifecycle changes:

```php
// EventServiceProvider or AppServiceProvider

use GregPriday\WorkManager\Events\*;

Event::listen(WorkOrderProposed::class, function ($event) {
    Log::info('New work order proposed', [
        'order_id' => $event->order->id,
        'type' => $event->order->type,
    ]);
});

Event::listen(WorkItemLeased::class, function ($event) {
    Metrics::increment('work_items_leased', [
        'type' => $event->item->type,
        'agent_id' => $event->item->leased_by_agent_id,
    ]);
});

Event::listen(WorkItemSubmitted::class, function ($event) {
    // Notify monitoring system
    Log::info('Work item submitted', [
        'item_id' => $event->item->id,
        'agent_id' => $event->item->leased_by_agent_id,
    ]);
});

Event::listen(WorkOrderApplied::class, function ($event) {
    // Log changes
    Log::info('Work order applied', [
        'order_id' => $event->order->id,
        'diff' => $event->diff->toArray(),
    ]);

    // Trigger downstream processes
    NotifyStakeholders::dispatch($event->order);
});

Event::listen(WorkOrderCompleted::class, function ($event) {
    // Update metrics
    Metrics::increment('orders_completed', [
        'type' => $event->order->type,
    ]);
});
```

---

## Best Practices

### 1. Idempotency in apply()
```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        // Use updateOrCreate, not create
        foreach ($order->items as $item) {
            User::updateOrCreate(
                ['id' => $item->result['user_id']],
                $item->result['data']
            );
        }

        return $this->makeDiff($before, $after);
    });
}
```

### 2. Thorough Validation
```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Validate completeness
    if (count($result['users']) !== count($item->input['user_ids'])) {
        throw ValidationException::withMessages([
            'users' => ['Incomplete user list'],
        ]);
    }

    // Validate external consistency
    foreach ($result['users'] as $user) {
        if (!$this->verifyWithExternalSystem($user)) {
            throw ValidationException::withMessages([
                'users' => ["User {$user['id']} failed external verification"],
            ]);
        }
    }
}
```

### 3. Comprehensive Logging
```php
protected function beforeApply(WorkOrder $order): void
{
    Log::info('Applying work order', [
        'order_id' => $order->id,
        'type' => $order->type,
        'items_count' => $order->items->count(),
    ]);
}

protected function afterApply(WorkOrder $order, Diff $diff): void
{
    Log::info('Work order applied successfully', [
        'order_id' => $order->id,
        'diff' => $diff->toArray(),
    ]);
}
```

### 4. Proper Error Handling
```php
public function apply(WorkOrder $order): Diff
{
    try {
        return DB::transaction(function () use ($order) {
            // Your mutations
            return $this->makeDiff($before, $after);
        });
    } catch (\Exception $e) {
        Log::error('Apply failed', [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
        ]);

        throw $e;  // Let system handle failure transition
    }
}
```

---

## See Also

- [What It Does](what-it-does.md) - Problem domain and core concepts
- [Architecture Overview](architecture-overview.md) - System design and layers
- [State Management](state-management.md) - State machine deep dive
- [Configuration Model](configuration-model.md) - Configuration options
- [Security and Permissions](security-and-permissions.md) - Authorization
- [Lifecycle Hooks Guide](../examples/LIFECYCLE.md) - Detailed hook documentation
- [Quick Start Guide](../examples/QUICK_START.md) - Get started quickly
