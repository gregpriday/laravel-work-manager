# State Management

## Introduction

Laravel Work Manager uses a **strict state machine** to enforce valid lifecycle progressions for work orders and work items. This ensures data consistency, prevents invalid transitions, and provides a clear audit trail of all changes.

---

## Core Principles

### 1. Explicit State Transitions

All state changes must go through the `StateMachine` service:

```php
// CORRECT
$stateMachine->transitionOrder($order, OrderState::APPROVED);

// WRONG - bypasses state machine
$order->state = OrderState::APPROVED;
$order->save();
```

### 2. Validation Before Transition

The state machine validates transitions against configured rules:

```php
if (!$order->state->canTransitionTo($newState)) {
    throw new IllegalStateTransitionException(
        $order->state->value,
        $newState->value,
        'order'
    );
}
```

### 3. Automatic Event Recording

Every transition automatically creates an event record:

```php
$this->stateMachine->transitionOrder(
    $order,
    OrderState::APPROVED,
    ActorType::USER,
    $userId,
    ['reason' => 'Manual review completed']
);
// Creates WorkEvent with actor, payload, timestamp
```

### 4. Transactional Integrity

All transitions happen in database transactions:

```php
DB::transaction(function () use ($order, $newState) {
    $order->state = $newState;
    $order->save();

    WorkEvent::create([...]);
});
```

---

## Work Order States

### State Enumeration

```php
enum OrderState: string
{
    case QUEUED = 'queued';
    case CHECKED_OUT = 'checked_out';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case APPLIED = 'applied';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case DEAD_LETTERED = 'dead_lettered';
}
```

### State Definitions

#### QUEUED
**Meaning**: Work order created but not yet started.

**Entry Conditions**:
- Just created via `propose()`
- Rejected with rework allowed
- Failed with retry available

**Exit Conditions**:
- First item checked out → `CHECKED_OUT`
- All items submitted → `SUBMITTED`
- Order rejected → `REJECTED`
- Order failed → `FAILED`

**Typical Duration**: Minutes to hours (depends on agent availability)

---

#### CHECKED_OUT
**Meaning**: At least one work item has been leased by an agent.

**Entry Conditions**:
- First agent calls `checkout()` from `QUEUED`

**Exit Conditions**:
- First item moves to `in_progress` → `IN_PROGRESS`
- Order fails → `FAILED`
- Order re-queued (all leases expired) → `QUEUED`

**Typical Duration**: Seconds to minutes (agent starts processing)

---

#### IN_PROGRESS
**Meaning**: Agent(s) actively processing work items.

**Entry Conditions**:
- First item heartbeat from `CHECKED_OUT`

**Exit Conditions**:
- All items submitted → `SUBMITTED`
- Order fails → `FAILED`
- Order re-queued (all leases expired) → `QUEUED`

**Typical Duration**: Minutes to hours (depends on work complexity)

---

#### SUBMITTED
**Meaning**: All work items have been submitted by agents, awaiting approval.

**Entry Conditions**:
- Last item submitted from `IN_PROGRESS`

**Exit Conditions**:
- Backend approves → `APPROVED`
- Backend rejects → `REJECTED`
- Order fails → `FAILED`

**Typical Duration**: Minutes to days (depends on approval process)

---

#### APPROVED
**Meaning**: Backend has approved the work, about to execute.

**Entry Conditions**:
- Manual or auto-approval from `SUBMITTED`

**Exit Conditions**:
- Apply executes successfully → `APPLIED`
- Apply fails → `FAILED`

**Typical Duration**: Milliseconds (immediately applies)

---

#### APPLIED
**Meaning**: Domain changes have been executed successfully.

**Entry Conditions**:
- `apply()` completes from `APPROVED`

**Exit Conditions**:
- All items completed → `COMPLETED`
- Order fails during cleanup → `FAILED`

**Typical Duration**: Milliseconds to seconds (cleanup hooks)

---

#### COMPLETED
**Meaning**: Work order fully complete (terminal state).

**Entry Conditions**:
- All items reach `COMPLETED` from `APPLIED`

**Exit Conditions**: None (terminal)

**Typical Duration**: Permanent

---

#### REJECTED
**Meaning**: Backend rejected the work (terminal or requeued).

**Entry Conditions**:
- Backend calls `reject()` from `SUBMITTED`

**Exit Conditions**:
- Requeue for rework → `QUEUED`
- Abandon → `DEAD_LETTERED`

**Typical Duration**: Permanent or until rework decision

---

#### FAILED
**Meaning**: Unexpected error during processing.

**Entry Conditions**:
- Exception during any operation

**Exit Conditions**:
- Retry available → `QUEUED`
- Abandon → `DEAD_LETTERED`

**Typical Duration**: Until retry or dead-letter

---

#### DEAD_LETTERED
**Meaning**: Work abandoned after exhausting retries (terminal).

**Entry Conditions**:
- Max retries exceeded from `FAILED`
- Manual dead-lettering from `REJECTED` or `FAILED`

**Exit Conditions**: None (terminal)

**Typical Duration**: Permanent (requires manual intervention to requeue)

---

## Work Order State Diagram

```
┌─────────┐
│ QUEUED  │ ◄──────────────────────────────────────────┐
└────┬────┘                                             │
     │                                                  │
     │ First item checked out                           │
     ▼                                                  │
┌──────────────┐                                        │
│ CHECKED_OUT  │                                        │
└──────┬───────┘                                        │
       │                                                │
       │ First heartbeat                                │
       ▼                                                │
┌─────────────┐                                         │
│ IN_PROGRESS │                                         │
└──────┬──────┘                                         │
       │                                                │
       │ All items submitted                            │
       ▼                                                │
┌───────────┐                                           │
│ SUBMITTED │                                           │
└─────┬─────┘                                           │
      │                                                 │
      ├──────────► ┌──────────┐                        │
      │            │ REJECTED │───────► Rework? ───────┤
      │            └──────────┘            │           │
      │                 │                  │           │
      │                 │ No rework        │           │
      │                 ▼                  ▼           │
      │            ┌────────────────┐     Dead letter  │
      │            │ DEAD_LETTERED  │                  │
      │            └────────────────┘                  │
      │                                                │
      │ Approved                                       │
      ▼                                                │
┌──────────┐                                            │
│ APPROVED │                                            │
└────┬─────┘                                            │
     │                                                  │
     │ Apply executes                                   │
     ▼                                                  │
┌─────────┐                                             │
│ APPLIED │                                             │
└────┬────┘                                             │
     │                                                  │
     │ All items complete                               │
     ▼                                                  │
┌───────────┐                                           │
│ COMPLETED │                                           │
└───────────┘                                           │
                                                        │
     ┌────────┐                                         │
     │ FAILED │────────► Retry? ───────────────────────┘
     └────────┘              │
                             │ No retry
                             ▼
                        ┌────────────────┐
                        │ DEAD_LETTERED  │
                        └────────────────┘
```

---

## Work Item States

### State Enumeration

```php
enum ItemState: string
{
    case QUEUED = 'queued';
    case LEASED = 'leased';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case DEAD_LETTERED = 'dead_lettered';
}
```

### State Definitions

#### QUEUED
**Meaning**: Work item ready to be leased.

**Entry Conditions**:
- Created during planning
- Lease expired, retry available
- Released by agent

**Exit Conditions**:
- Agent leases → `LEASED`
- Item fails → `FAILED`

---

#### LEASED
**Meaning**: Agent has acquired exclusive lease.

**Entry Conditions**:
- Agent calls `checkout()` from `QUEUED`

**Exit Conditions**:
- Agent sends heartbeat → `IN_PROGRESS`
- Lease expires → `QUEUED` (retry) or `FAILED` (max attempts)
- Agent releases → `QUEUED`
- Item fails → `FAILED`

---

#### IN_PROGRESS
**Meaning**: Agent actively working on item.

**Entry Conditions**:
- First heartbeat from `LEASED`

**Exit Conditions**:
- Agent submits result → `SUBMITTED`
- Lease expires → `QUEUED` (retry) or `FAILED` (max attempts)
- Item fails → `FAILED`

---

#### SUBMITTED
**Meaning**: Agent submitted result, passed validation, awaiting approval.

**Entry Conditions**:
- Agent calls `submit()` or `finalize()` from `IN_PROGRESS`
- Validation passes

**Exit Conditions**:
- Order approved → `ACCEPTED`
- Order rejected → `REJECTED`
- Item fails → `FAILED`

---

#### ACCEPTED
**Meaning**: Order approved, item results accepted for application.

**Entry Conditions**:
- Order transitions to `APPLIED` from `SUBMITTED`

**Exit Conditions**:
- Immediately transitions to `COMPLETED`

---

#### REJECTED
**Meaning**: Order rejected, item results not applied.

**Entry Conditions**:
- Order transitions to `REJECTED`

**Exit Conditions**:
- Requeue for rework → `QUEUED`
- Item fails → `FAILED`

---

#### COMPLETED
**Meaning**: Item fully processed and applied (terminal).

**Entry Conditions**:
- From `ACCEPTED`

**Exit Conditions**: None (terminal)

---

#### FAILED
**Meaning**: Item failed during processing.

**Entry Conditions**:
- Lease expired with max attempts exceeded
- Validation failure (permanent)
- Apply failure

**Exit Conditions**:
- Retry available → `QUEUED`
- Abandon → `DEAD_LETTERED`

---

#### DEAD_LETTERED
**Meaning**: Item abandoned (terminal).

**Entry Conditions**:
- Max retries exceeded from `FAILED`
- Manual dead-lettering

**Exit Conditions**: None (terminal, requires manual intervention)

---

## Work Item State Diagram

```
┌─────────┐
│ QUEUED  │ ◄─────────────────────────────────┐
└────┬────┘                                    │
     │                                         │
     │ Agent checkout                          │
     ▼                                         │
┌────────┐                                     │
│ LEASED │                                     │
└───┬────┘                                     │
    │                                          │
    │ Heartbeat                                │
    ▼                                          │
┌─────────────┐                                │
│ IN_PROGRESS │                                │
└──────┬──────┘                                │
       │                                       │
       │ Submit result                         │
       ▼                                       │
┌───────────┐                                  │
│ SUBMITTED │                                  │
└─────┬─────┘                                  │
      │                                        │
      ├─────────► ┌──────────┐                │
      │           │ REJECTED │────────────────┤
      │           └──────────┘                │
      │                                        │
      │ Order approved                         │
      ▼                                        │
┌──────────┐                                   │
│ ACCEPTED │                                   │
└────┬─────┘                                   │
     │                                         │
     │ Immediately                             │
     ▼                                         │
┌───────────┐                                  │
│ COMPLETED │                                  │
└───────────┘                                  │
                                               │
┌────────┐                                     │
│ FAILED │──────► Retry? ─────────────────────┘
└────────┘           │
                     │ No retry
                     ▼
                ┌────────────────┐
                │ DEAD_LETTERED  │
                └────────────────┘
```

---

## State Transition Configuration

### Default Configuration

Defined in `config/work-manager.php`:

```php
'state_machine' => [
    'order_transitions' => [
        'queued' => ['checked_out', 'submitted', 'rejected', 'failed'],
        'checked_out' => ['in_progress', 'queued', 'failed'],
        'in_progress' => ['submitted', 'failed', 'queued'],
        'submitted' => ['approved', 'rejected', 'failed'],
        'approved' => ['applied', 'failed'],
        'applied' => ['completed', 'failed'],
        'rejected' => ['queued', 'dead_lettered'],
        'failed' => ['queued', 'dead_lettered'],
        'completed' => [],
        'dead_lettered' => [],
    ],
    'item_transitions' => [
        'queued' => ['leased', 'failed'],
        'leased' => ['in_progress', 'queued', 'failed'],
        'in_progress' => ['submitted', 'failed', 'queued'],
        'submitted' => ['accepted', 'rejected', 'failed'],
        'accepted' => ['completed'],
        'rejected' => ['queued', 'failed'],
        'completed' => [],
        'failed' => ['queued', 'dead_lettered'],
        'dead_lettered' => [],
    ],
],
```

### Validation Logic

```php
// OrderState enum
public function canTransitionTo(OrderState $newState): bool
{
    $transitions = config('work-manager.state_machine.order_transitions');
    $allowed = $transitions[$this->value] ?? [];

    return in_array($newState->value, $allowed);
}

// StateMachine service
if (!$order->state->canTransitionTo($newState)) {
    throw new IllegalStateTransitionException(
        $order->state->value,
        $newState->value,
        'order'
    );
}
```

---

## StateMachine Service API

### Transition Order

```php
public function transitionOrder(
    WorkOrder $order,
    OrderState $newState,
    ?ActorType $actorType = null,
    ?string $actorId = null,
    ?array $payload = null,
    ?string $message = null,
    ?array $diff = null
): WorkOrder;
```

**Example**:
```php
$stateMachine->transitionOrder(
    $order,
    OrderState::APPROVED,
    ActorType::USER,
    $userId,
    ['reason' => 'Manual review passed'],
    'Order approved after quality check'
);
```

**Actions**:
1. Validate transition is allowed
2. Update order state
3. Set special timestamps (`applied_at`, `completed_at`)
4. Create `WorkEvent` record
5. Emit Laravel event
6. Return updated order

---

### Transition Item

```php
public function transitionItem(
    WorkItem $item,
    ItemState $newState,
    ?ActorType $actorType = null,
    ?string $actorId = null,
    ?array $payload = null,
    ?string $message = null
): WorkItem;
```

**Example**:
```php
$stateMachine->transitionItem(
    $item,
    ItemState::SUBMITTED,
    ActorType::AGENT,
    $agentId,
    ['result' => $result],
    'Agent submitted work'
);
```

**Actions**:
1. Validate transition is allowed
2. Update item state
3. Set special timestamps (`accepted_at`)
4. Create `WorkEvent` record
5. Check if order should complete
6. Emit Laravel event
7. Return updated item

---

### Record Event (Without Transition)

```php
public function recordOrderEvent(
    WorkOrder $order,
    EventType $event,
    ?ActorType $actorType = null,
    ?string $actorId = null,
    ?array $payload = null,
    ?string $message = null,
    ?array $diff = null
): WorkEvent;

public function recordItemEvent(
    WorkItem $item,
    EventType $event,
    ?ActorType $actorType = null,
    ?string $actorId = null,
    ?array $payload = null,
    ?string $message = null
): WorkEvent;
```

**Example**:
```php
$stateMachine->recordItemEvent(
    $item,
    EventType::HEARTBEAT,
    ActorType::AGENT,
    $agentId,
    [],
    'Lease extended'
);
```

---

## Terminal States

### What Are Terminal States?

States from which no further transitions are possible.

### Order Terminal States

```php
public function isTerminal(): bool
{
    return in_array($this, [
        self::COMPLETED,
        self::DEAD_LETTERED,
    ]);
}
```

- **COMPLETED**: Successfully finished
- **DEAD_LETTERED**: Abandoned

### Item Terminal States

```php
public function isTerminal(): bool
{
    return in_array($this, [
        self::COMPLETED,
        self::REJECTED,
        self::DEAD_LETTERED,
    ]);
}
```

- **COMPLETED**: Successfully finished
- **REJECTED**: Order rejected (terminal for item, not order)
- **DEAD_LETTERED**: Abandoned

### Handling Terminal States

```php
if ($order->state->isTerminal()) {
    throw new \Exception('Cannot modify completed order');
}
```

---

## Special Timestamps

### Work Order Timestamps

```php
class WorkOrder extends Model
{
    protected $dates = [
        'created_at',              // When proposed
        'last_transitioned_at',    // Last state change
        'applied_at',              // When domain changes applied
        'completed_at',            // When fully complete
    ];
}
```

**Set By**:
- `applied_at` → Set when transitioning to `APPLIED`
- `completed_at` → Set when transitioning to `COMPLETED`
- `last_transitioned_at` → Set on every transition

---

### Work Item Timestamps

```php
class WorkItem extends Model
{
    protected $dates = [
        'created_at',              // When planned
        'leased_at',               // When first leased
        'lease_expires_at',        // Lease TTL
        'accepted_at',             // When accepted
    ];
}
```

**Set By**:
- `leased_at` → Set when transitioning to `LEASED`
- `lease_expires_at` → Set on lease acquisition/extension
- `accepted_at` → Set when transitioning to `ACCEPTED`

---

## Event Recording

### Event Structure

```php
class WorkEvent extends Model
{
    protected $casts = [
        'event' => EventType::class,
        'actor_type' => ActorType::class,
        'payload' => 'array',
        'diff' => 'array',
    ];

    // Fields:
    // - order_id
    // - item_id (nullable)
    // - event (EventType enum)
    // - actor_type (ActorType enum)
    // - actor_id (string, nullable)
    // - payload (array)
    // - diff (array, nullable)
    // - message (string, nullable)
    // - created_at
}
```

### Event Types

```php
enum EventType: string
{
    case PROPOSED = 'proposed';
    case PLANNED = 'planned';
    case CHECKED_OUT = 'checked_out';
    case LEASED = 'leased';
    case HEARTBEAT = 'heartbeat';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case APPLIED = 'applied';
    case ACCEPTED = 'accepted';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case DEAD_LETTERED = 'dead_lettered';
}
```

### Actor Types

```php
enum ActorType: string
{
    case AGENT = 'agent';
    case USER = 'user';
    case SYSTEM = 'system';
}
```

### Example Event Record

```json
{
    "id": 123,
    "order_id": 42,
    "item_id": 101,
    "event": "submitted",
    "actor_type": "agent",
    "actor_id": "agent-1",
    "payload": {
        "result": { "success": true, "count": 5 }
    },
    "message": "Agent submitted work",
    "created_at": "2025-10-22T10:30:00Z"
}
```

---

## Auto-Completion Logic

### Order Completion

The `StateMachine` automatically checks if an order should complete after each item transition:

```php
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
```

**Triggered By**:
- Item transitioning to `COMPLETED`
- Item transitioning to `DEAD_LETTERED`

**Condition**:
```php
public function allItemsComplete(): bool
{
    return $this->items()
        ->whereNotIn('state', [
            ItemState::COMPLETED->value,
            ItemState::DEAD_LETTERED->value,
        ])
        ->doesntExist();
}
```

---

## Best Practices

### 1. Always Use StateMachine Service

```php
// GOOD
$stateMachine->transitionOrder($order, OrderState::APPROVED);

// BAD - bypasses validation and events
$order->state = OrderState::APPROVED;
$order->save();
```

### 2. Provide Context in Transitions

```php
$stateMachine->transitionOrder(
    $order,
    OrderState::APPROVED,
    ActorType::USER,
    $user->id,
    [
        'reason' => 'Quality check passed',
        'reviewer' => $user->name,
    ],
    'Order approved after manual review'
);
```

### 3. Handle Illegal Transitions Gracefully

```php
try {
    $stateMachine->transitionOrder($order, OrderState::APPROVED);
} catch (IllegalStateTransitionException $e) {
    return response()->json([
        'error' => 'Invalid state transition',
        'current_state' => $e->currentState,
        'attempted_state' => $e->attemptedState,
    ], 400);
}
```

### 4. Query by State

```php
// Active orders
$active = WorkOrder::whereIn('state', [
    OrderState::QUEUED->value,
    OrderState::CHECKED_OUT->value,
    OrderState::IN_PROGRESS->value,
    OrderState::SUBMITTED->value,
])->get();

// Terminal orders
$terminal = WorkOrder::whereIn('state', [
    OrderState::COMPLETED->value,
    OrderState::DEAD_LETTERED->value,
])->get();
```

### 5. Listen to State Change Events

```php
Event::listen(WorkOrderApproved::class, function ($event) {
    // React to approval
    NotifyStakeholders::dispatch($event->order);
});

Event::listen(WorkItemSubmitted::class, function ($event) {
    // Track submission metrics
    Metrics::increment('items_submitted', [
        'type' => $event->item->type,
    ]);
});
```

---

## Debugging State Issues

### Check Current State

```php
// Via model
$order->state;  // OrderState enum
$order->state->value;  // 'queued', 'submitted', etc.

// Via query
WorkOrder::where('state', OrderState::SUBMITTED->value)->count();
```

### Check Allowed Transitions

```php
$order->state->canTransitionTo(OrderState::APPROVED);  // bool

// Get all allowed transitions
$transitions = config('work-manager.state_machine.order_transitions');
$allowed = $transitions[$order->state->value];  // array
```

### View Event History

```php
$events = $order->events()->orderBy('created_at')->get();

foreach ($events as $event) {
    echo "{$event->created_at}: {$event->event} by {$event->actor_type}\n";
}
```

### Trace State Changes

```sql
-- All state changes for an order
SELECT event, actor_type, actor_id, message, created_at
FROM work_events
WHERE order_id = 42
AND event IN ('proposed', 'checked_out', 'in_progress', 'submitted', 'approved', 'applied', 'completed')
ORDER BY created_at;
```

---

## Custom State Transitions

### When to Customize

**WARNING**: Only customize state transitions if you have a very specific workflow requirement. The default configuration covers 99% of use cases.

### How to Customize

Edit `config/work-manager.php`:

```php
'state_machine' => [
    'order_transitions' => [
        'queued' => ['checked_out', 'submitted', 'rejected', 'failed', 'custom_state'],
        'custom_state' => ['approved', 'rejected'],
        // ... rest of transitions
    ],
],
```

**Note**: Custom states require adding new enum cases to `OrderState` or `ItemState`, which means modifying package code. This is **not recommended** and will make upgrades difficult.

---

## See Also

- [What It Does](what-it-does.md) - Core concepts and problem domain
- [Architecture Overview](architecture-overview.md) - System design
- [Lifecycle and Flow](lifecycle-and-flow.md) - Complete lifecycle with state transitions
- [Configuration Model](configuration-model.md) - Configuration options
- [Security and Permissions](security-and-permissions.md) - Authorization
