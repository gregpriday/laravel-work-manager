# Work Order Lifecycle Guide

This guide explains the complete lifecycle of a work order and all the hooks available for customization.

## Complete Lifecycle Flow

```
1. PROPOSE → 2. PLAN → 3. CHECKOUT → 4. SUBMIT → 5. VERIFY → 6. APPROVE → 7. APPLY → 8. COMPLETE
```

### 1. Propose (Create Work Order)

**Who**: Agent or System
**When**: Initial request to perform work
**API**: `POST /ai/work/propose`

```php
// Your OrderType hooks:
public function schema(): array          // Define payload schema
```

**Laravel Events**: `WorkOrderProposed`

---

### 2. Plan (Create Work Items)

**Who**: System
**When**: Immediately after proposal
**Purpose**: Break order into discrete work items

```php
// Your OrderType hooks:
public function plan(WorkOrder $order): array
{
    // Return array of work item configurations
    return [[
        'type' => $this->type(),
        'input' => [...],  // Instructions for agent
        'max_attempts' => 3,
    ]];
}
```

**Laravel Events**: `WorkOrderPlanned`

---

### 3. Checkout (Lease Work Item)

**Who**: Agent
**When**: Agent is ready to process work
**API**: `POST /ai/work/orders/{order}/checkout`

**System Actions**:
- Acquires lease with TTL
- Returns work item input to agent
- Requires heartbeat to maintain lease

**Laravel Events**: `WorkOrderCheckedOut`, `WorkItemLeased`

---

### 4. Submit (Agent Completes Work)

**Who**: Agent
**When**: Agent finishes processing work item
**API**: `POST /ai/work/items/{item}/submit`

Agent provides:
```json
{
  "result": { ... },      // Work output
  "evidence": { ... },    // Proof/verification data
  "notes": "..."          // Optional notes
}
```

**Laravel Events**: `WorkItemSubmitted`

---

### 5. Verify (Validate Submission)

**Who**: System
**When**: Immediately when agent submits
**Purpose**: Validate agent's work before approval

```php
// Your AcceptancePolicy or OrderType hooks:

// Step 5a: Laravel Validation Rules
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'status' => 'required|in:success,failed',
        'data' => 'required|array',
        'data.*.verified' => 'required|boolean|accepted',
    ];
}

// Step 5b: Custom Business Logic Validation
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Check if data actually exists in external system
    if (!$this->externalApi->verify($result['data'])) {
        throw ValidationException::withMessages([
            'data' => ['External verification failed'],
        ]);
    }
}

// Step 5c: Check if Order Ready for Approval
protected function canApprove(WorkOrder $order): bool
{
    // All items must pass verification
    foreach ($order->items as $item) {
        if (!$item->result['verified'] ?? false) {
            return false;
        }
    }
    return true;
}
```

If validation **fails**:
- Item stays in `submitted` state with errors
- Agent can view errors and resubmit
- `ValidationException` with structured error codes

If validation **passes**:
- Item moves to `submitted` state
- Ready for approval step

---

### 6. Approve (Backend Decision)

**Who**: Backend User or System
**When**: After all items verified
**API**: `POST /ai/work/orders/{order}/approve`

```php
// Your OrderType hook (optional):
protected function canApprove(WorkOrder $order): bool
{
    // Additional approval logic
    return true;
}
```

**Laravel Events**: `WorkOrderApproved`

---

### 7. Apply (Execute Work)

**Who**: System
**When**: Immediately after approval
**Purpose**: Actually perform the domain changes

```php
// Your OrderType hooks:

// Before execution
protected function beforeApply(WorkOrder $order): void
{
    Log::info('Starting execution', ['order_id' => $order->id]);
    // Setup, acquire locks, backup data, etc.
}

// Main execution (MUST be idempotent!)
public function apply(WorkOrder $order): Diff
{
    $before = ['count' => 0];

    DB::transaction(function () use ($order) {
        // Perform database operations
        // Update external systems
        // Make the actual changes
    });

    $after = ['count' => 10];

    return $this->makeDiff($before, $after, 'Summary of changes');
}

// After execution
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Cleanup, notifications, cache invalidation, etc.
    Cache::tags(['users'])->flush();
    event(new DataSyncCompleted($order));
}
```

**Laravel Events**: `WorkOrderApplied`

**⚠️ Important**: The `apply()` method **MUST be idempotent** - it may be called multiple times with the same order.

---

### 8. Complete

**Who**: System
**When**: After successful apply
**Purpose**: Mark order as done

All items marked as `accepted` → `completed`
Order marked as `completed`

**Laravel Events**: `WorkOrderCompleted`

---

## Integration with Laravel Features

### Using Laravel Validation

```php
use GregPriday\WorkManager\Support\AbstractOrderType;

class MyOrderType extends AbstractOrderType
{
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'email' => 'required|email|unique:users,email',
            'data' => 'required|array',
            'data.*.field' => 'required|string|min:3',
        ];
    }

    protected function validationMessages(): array
    {
        return [
            'user_id.required' => 'User ID is required for sync',
            'email.unique' => 'This email already exists',
        ];
    }
}
```

### Using Laravel Events

```php
// In your EventServiceProvider or AppServiceProvider:
use GregPriday\WorkManager\Events\WorkOrderApplied;
use GregPriday\WorkManager\Events\WorkItemSubmitted;

Event::listen(WorkOrderApplied::class, function ($event) {
    // $event->order - the work order
    // $event->diff - the changes made

    Log::info('Work order applied', [
        'order_id' => $event->order->id,
        'changes' => $event->diff->toArray(),
    ]);

    // Trigger downstream processes
    NotifyUsers::dispatch($event->order);
});

Event::listen(WorkItemSubmitted::class, function ($event) {
    // $event->item - the work item

    // Send metrics to monitoring
    Metrics::increment('work_items_submitted', [
        'type' => $event->item->type,
    ]);
});
```

### Using Laravel Jobs/Queues

```php
// After apply, queue follow-up work:
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Queue notification emails
    SendUserNotifications::dispatch($order)->onQueue('notifications');

    // Queue analytics update
    UpdateAnalytics::dispatch($diff->toArray())->onQueue('analytics');
}
```

---

## Error Handling

### Validation Errors (Step 5)

Return structured errors that agents can understand:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    if (!$this->verifyExternalData($result['data'])) {
        // Structured error
        throw ValidationException::withMessages([
            'data' => [
                'code:verification_failed: External system verification failed'
            ],
        ]);
    }
}
```

### Apply Errors (Step 7)

If `apply()` throws an exception:
- Order transitions to `failed` state
- Can be retried or dead-lettered
- All items marked as `failed`

---

## Best Practices

1. **Idempotency**: Always make `apply()` idempotent - check if work already done
2. **Transactions**: Wrap database operations in transactions
3. **Verification**: Verify agent work thoroughly before approval
4. **Events**: Use Laravel events for loose coupling
5. **Logging**: Log at each lifecycle stage for debugging
6. **Cleanup**: Use `afterApply()` for cleanup and side effects
7. **Testing**: Test each lifecycle hook independently

---

## Example: Complete Implementation

See `examples/UserDataSyncType.php` and `examples/DatabaseRecordInsertType.php` for complete, production-ready examples showing all lifecycle hooks.
