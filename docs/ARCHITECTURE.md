# Laravel Work Manager Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         AI AGENT / CLIENT                        │
│  (Proposes work, checks out items, submits results)             │
└────────────────┬────────────────────────────────────────────────┘
                 │ HTTP/MCP
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                      HTTP API LAYER                              │
│  WorkOrderApiController                                          │
│  • propose, checkout, heartbeat, submit, approve, reject         │
│  • Idempotency enforcement                                       │
│  • Authorization via policies                                    │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     SERVICE LAYER                                │
├──────────────────────────────────────────────────────────────────┤
│  WorkAllocator         │  WorkExecutor       │  LeaseService    │
│  • propose()           │  • submit()         │  • acquire()     │
│  • plan()              │  • approve()        │  • extend()      │
│                        │  • reject()         │  • release()     │
│                        │  • apply()          │  • reclaim()     │
├──────────────────────────────────────────────────────────────────┤
│  StateMachine          │  IdempotencyService │  Registry        │
│  • transitionOrder()   │  • guard()          │  • register()    │
│  • transitionItem()    │  • check()          │  • get()         │
│  • recordEvent()       │  • store()          │                  │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                    ORDER TYPE LAYER                              │
│  Your Custom Types (extend AbstractOrderType)                   │
├──────────────────────────────────────────────────────────────────┤
│  type()                    │ Lifecycle Hooks:                   │
│  schema()                  │ • beforeApply()                    │
│  plan()                    │ • apply() ⭐                       │
│  acceptancePolicy()        │ • afterApply()                     │
│                            │                                     │
│  Validation Hooks:         │ Verification:                      │
│  • submissionValidationRules()  • afterValidateSubmission()    │
│  • validationMessages()    │ • canApprove()                     │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     DATA/MODEL LAYER                             │
├──────────────────────────────────────────────────────────────────┤
│  WorkOrder                 │  WorkItem                           │
│  • type, state, payload    │  • state, input, result             │
│  • priority, meta          │  • lease info, attempts             │
│                            │                                     │
│  WorkEvent                 │  WorkProvenance                     │
│  • event, actor, payload   │  • agent metadata                   │
│  • diff, message           │  • request fingerprint              │
│                            │                                     │
│  WorkIdempotencyKey        │                                     │
│  • scope, key_hash         │                                     │
└─────────────────────────────────────────────────────────────────┘
```

## Data Flow: Complete Lifecycle

### 1. Proposal Flow
```
Agent/System
    │
    ├─> POST /propose {type, payload}
    │
    ├─> WorkAllocator::propose()
    │   ├─> Validate against schema()
    │   ├─> Create WorkOrder (state: queued)
    │   └─> Emit WorkOrderProposed event
    │
    └─> OrderType::plan(order)
        ├─> Create WorkItems
        └─> Emit WorkOrderPlanned event
```

### 2. Agent Execution Flow
```
Agent
    │
    ├─> POST /orders/{order}/checkout
    │   ├─> LeaseService::acquire(item)
    │   ├─> Lock item with TTL
    │   ├─> Return item.input to agent
    │   └─> Emit WorkItemLeased event
    │
    ├─> [Agent processes work...]
    │
    ├─> POST /items/{item}/heartbeat (every 2 min)
    │   ├─> LeaseService::extend()
    │   └─> Emit WorkItemHeartbeat event
    │
    └─> POST /items/{item}/submit {result}
        ├─> WorkExecutor::submit(item, result)
        ├─> AcceptancePolicy::validateSubmission()
        │   ├─> submissionValidationRules() [Laravel validation]
        │   └─> afterValidateSubmission() [Custom checks]
        ├─> Save item.result
        └─> Emit WorkItemSubmitted event
```

### 3. Verification & Approval Flow
```
System/Backend User
    │
    ├─> AcceptancePolicy::readyForApproval(order)
    │   ├─> Check all items submitted
    │   └─> canApprove() [Custom logic]
    │
    ├─> POST /orders/{order}/approve
    │   ├─> WorkExecutor::approve(order)
    │   ├─> StateMachine::transitionOrder(approved)
    │   ├─> Emit WorkOrderApproved event
    │   │
    │   └─> WorkExecutor::apply(order)
    │       ├─> beforeApply() hook
    │       ├─> OrderType::apply(order) ⭐ [Your domain logic]
    │       ├─> StateMachine::transitionOrder(applied)
    │       ├─> Record diff in WorkEvent
    │       ├─> Emit WorkOrderApplied event
    │       ├─> afterApply() hook
    │       └─> Mark items as accepted/completed
    │
    └─> StateMachine::transitionOrder(completed)
        └─> Emit WorkOrderCompleted event
```

## State Transitions

### WorkOrder States
```
queued ──> checked_out ──> in_progress ──> submitted ──> approved
                                                            │
                                                            ▼
                                                         applied
                                                            │
                                                            ▼
                                                        completed

Failed/Rejected paths:
submitted ──> rejected ──> queued (if rework allowed)
                      └──> dead_lettered
any state ──> failed ──> dead_lettered
```

### WorkItem States
```
queued ──> leased ──> in_progress ──> submitted ──> accepted ──> completed

Failed path:
leased ──> (lease expires) ──> queued (retry)
                           └──> failed (max attempts) ──> dead_lettered
```

## Key Concepts

### 1. Order Types
Custom classes defining:
- **Schema**: What data is required
- **Planning**: How to break work into items
- **Validation**: How to verify agent work
- **Execution**: How to apply changes

### 2. Leasing
- Single agent can lease an item at a time
- TTL-based with heartbeat requirement
- Automatic reclaim on expiry
- Prevents concurrent processing

### 3. Idempotency
- Request deduplication via header
- Cached responses for retries
- Scope-based (propose, submit, approve, reject)

### 4. State Machine
- Enforced transitions
- Automatic event recording
- Timestamps for each transition
- Actor tracking

### 5. Verification
Two-phase validation:
1. **Agent Submission**: Laravel rules + custom checks
2. **Approval Readiness**: Cross-item validation

### 6. Auditability
Complete trail via:
- **WorkEvent**: Every state change, action, decision
- **WorkProvenance**: Agent metadata, request fingerprints
- **Diffs**: Before/after snapshots of changes

## Integration Points

### Laravel Events
```php
Event::listen(WorkOrderApplied::class, function($event) {
    // React to order application
});
```

### Laravel Jobs/Queues
```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    ProcessFollowUp::dispatch($order)->onQueue('work');
}
```

### Laravel Validation
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|exists:users,id',
        'email' => 'required|email|unique:users',
    ];
}
```

### Laravel Policies
```php
Gate::allows('approve', $order); // Uses WorkOrderPolicy
```

### Scheduled Commands
```php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
$schedule->command('work-manager:maintain')->everyMinute();
```

## Extending the System

### Create a New Order Type

1. **Extend AbstractOrderType**
```php
class MyType extends AbstractOrderType { }
```

2. **Implement required methods**
```php
public function type(): string { }
public function schema(): array { }
public function apply(WorkOrder $order): Diff { }
```

3. **Add validation**
```php
protected function submissionValidationRules(WorkItem $item): array { }
protected function afterValidateSubmission(WorkItem $item, array $result): void { }
```

4. **Add lifecycle hooks**
```php
protected function beforeApply(WorkOrder $order): void { }
protected function afterApply(WorkOrder $order, Diff $diff): void { }
```

5. **Register**
```php
WorkManager::registry()->register(new MyType());
```

## Security

- **Authentication**: Required for all endpoints (configurable guard)
- **Authorization**: Policy-based per endpoint
- **Idempotency**: Prevents replay attacks
- **Leasing**: Prevents race conditions
- **State Machine**: Prevents invalid transitions
- **Validation**: Multi-layer verification

## Performance Considerations

- **Database Locking**: Uses `FOR UPDATE` to prevent race conditions
- **Transactions**: All mutations wrapped in DB transactions
- **Indexes**: Optimized queries with composite indexes
- **Eager Loading**: Relationships loaded efficiently
- **Queue Integration**: Optional async processing
- **Lease Reclaim**: Batch processing in maintenance

## Monitoring

Track these metrics:
- Orders created per type
- Items checked out
- Submissions received
- Orders approved/rejected
- Lease expirations
- Time in each state
- Failure reasons

Available via events or direct queries on models.
