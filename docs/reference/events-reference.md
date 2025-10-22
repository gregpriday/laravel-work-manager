# Events Reference

Complete documentation of all events fired by Laravel Work Manager for observability and integration.

## Table of Contents

- [Event Overview](#event-overview)
- [Work Order Events](#work-order-events)
- [Work Item Events](#work-item-events)
- [Work Item Part Events](#work-item-part-events)
- [Listening to Events](#listening-to-events)
- [Event Payloads](#event-payloads)
- [Typical Use Cases](#typical-use-cases)

---

## Event Overview

All events in the package follow Laravel's event system and can be listened to using standard event listeners, subscribers, or closures.

### Event Categories

1. **Work Order Events** - Fired when work order state changes
2. **Work Item Events** - Fired when work item state changes or lease operations occur
3. **Work Item Part Events** - Fired when partial submissions are processed

### Common Event Properties

All events extend base classes and include:

| Property | Type | Description |
|----------|------|-------------|
| `$order` or `$item` or `$part` | Model | The relevant Eloquent model |
| `$payload` | array\|null | Optional event-specific data |

### Event Traits

All events use Laravel's event traits:
- `Illuminate\Foundation\Events\Dispatchable`
- `Illuminate\Queue\SerializesModels`

---

## Work Order Events

Events related to work order lifecycle. All extend `GregPriday\WorkManager\Events\WorkOrderEvent`.

### WorkOrderProposed

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderProposed`

**When Fired:** After a new work order is created and persisted

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The newly created work order |
| `$payload` | array\|null | Proposal metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Log order creation for audit
- Send notifications to stakeholders
- Trigger external integrations (webhooks, Slack, etc.)
- Update dashboards or metrics

**Example:**
```php
Event::listen(WorkOrderProposed::class, function ($event) {
    Log::info('Work order proposed', [
        'order_id' => $event->order->id,
        'type' => $event->order->type,
        'priority' => $event->order->priority,
    ]);
});
```

---

### WorkOrderPlanned

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderPlanned`

**When Fired:** After work order is planned into discrete work items

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The work order that was planned |
| `$payload` | array\|null | Planning metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track planning completion
- Update work item counts in dashboards
- Notify agents that work is available
- Calculate estimated completion time

**Example:**
```php
Event::listen(WorkOrderPlanned::class, function ($event) {
    $itemCount = $event->order->items()->count();

    Log::info('Work order planned', [
        'order_id' => $event->order->id,
        'item_count' => $itemCount,
    ]);
});
```

---

### WorkOrderCheckedOut

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderCheckedOut`

**When Fired:** When an agent checks out (leases) a work item from the order

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The parent work order |
| `$payload` | array\|null | Checkout metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track agent activity
- Update order progress metrics
- Monitor checkout patterns

---

### WorkOrderApproved

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderApproved`

**When Fired:** After a work order is approved but before it is applied

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The approved work order |
| `$payload` | array\|null | Approval metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Log approval for compliance/audit
- Notify stakeholders of approval
- Trigger pre-apply webhooks
- Update approval dashboards

**Example:**
```php
Event::listen(WorkOrderApproved::class, function ($event) {
    Notification::send(
        User::role('admin'),
        new WorkOrderApprovedNotification($event->order)
    );
});
```

---

### WorkOrderApplied

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderApplied`

**When Fired:** After a work order's changes are successfully applied to the system

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The applied work order |
| `$payload` | array\|null | Contains the `Diff` object describing changes |

**Example Payload (in $payload):**
```php
// Access via: $event->payload['diff']
[
    'summary' => 'Synced 100 users',
    'operations' => [
        ['op' => 'add', 'path' => '/users/123', 'value' => [...]],
        ['op' => 'update', 'path' => '/users/456', 'value' => [...]],
    ],
    'stats' => [
        'added' => 50,
        'updated' => 45,
        'deleted' => 5,
    ],
]
```

**Note:** The full `Diff` object is passed as a constructor parameter and also available via `$event->payload`.

**Typical Listeners:**
- Clear related caches
- Dispatch follow-up jobs
- Trigger downstream integrations
- Send completion notifications
- Update business metrics

**Example:**
```php
Event::listen(WorkOrderApplied::class, function ($event) {
    // Clear cache
    Cache::tags(['users'])->flush();

    // Dispatch follow-up job
    if ($event->order->type === 'user.data.sync') {
        UpdateSearchIndexJob::dispatch($event->order);
    }

    // Log diff summary
    Log::info('Work order applied', [
        'order_id' => $event->order->id,
        'summary' => $event->payload['diff']['summary'] ?? 'No summary',
    ]);
});
```

---

### WorkOrderCompleted

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderCompleted`

**When Fired:** When a work order reaches final completed state

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The completed work order |
| `$payload` | array\|null | Completion metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Archive order data
- Clean up temporary resources
- Generate completion reports
- Update success metrics

---

### WorkOrderRejected

**Namespace:** `GregPriday\WorkManager\Events\WorkOrderRejected`

**When Fired:** When a work order is rejected by a reviewer

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$order` | `WorkOrder` | The rejected work order |
| `$payload` | array\|null | Rejection metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Notify submitting agent of rejection
- Log rejection reasons for analysis
- Trigger rework workflows if `allow_rework` was true
- Update rejection metrics

**Example:**
```php
Event::listen(WorkOrderRejected::class, function ($event) {
    // Get rejection errors from order events
    $rejectionEvent = $event->order->events()
        ->where('event', 'rejected')
        ->latest()
        ->first();

    Log::warning('Work order rejected', [
        'order_id' => $event->order->id,
        'errors' => $rejectionEvent->payload['errors'] ?? [],
    ]);
});
```

---

## Work Item Events

Events related to work item lifecycle and lease operations. All extend `GregPriday\WorkManager\Events\WorkItemEvent`.

### WorkItemLeased

**Namespace:** `GregPriday\WorkManager\Events\WorkItemLeased`

**When Fired:** When an agent successfully acquires a lease on a work item

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The leased work item |
| `$payload` | array\|null | Lease metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track agent workload
- Monitor lease acquisition patterns
- Update real-time dashboards
- Log agent activity

**Example:**
```php
Event::listen(WorkItemLeased::class, function ($event) {
    Redis::incr("agent:{$event->item->leased_by_agent_id}:active_leases");

    Log::info('Work item leased', [
        'item_id' => $event->item->id,
        'agent_id' => $event->item->leased_by_agent_id,
        'expires_at' => $event->item->lease_expires_at,
    ]);
});
```

---

### WorkItemHeartbeat

**Namespace:** `GregPriday\WorkManager\Events\WorkItemHeartbeat`

**When Fired:** When an agent extends a lease via heartbeat

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The work item being heartbeat |
| `$payload` | array\|null | Heartbeat metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Monitor agent health
- Detect slow-running work
- Update last-seen timestamps
- Track heartbeat frequency

**Example:**
```php
Event::listen(WorkItemHeartbeat::class, function ($event) {
    Redis::set(
        "agent:{$event->item->leased_by_agent_id}:last_heartbeat",
        now()->timestamp,
        'EX',
        300
    );
});
```

---

### WorkItemSubmitted

**Namespace:** `GregPriday\WorkManager\Events\WorkItemSubmitted`

**When Fired:** After an agent submits results for a work item and validation passes

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The submitted work item |
| `$payload` | array\|null | Submission metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track submission success rate
- Analyze result quality
- Trigger result processing jobs
- Update completion progress

**Example:**
```php
Event::listen(WorkItemSubmitted::class, function ($event) {
    Metrics::increment('work_items.submitted', [
        'type' => $event->item->type,
        'agent' => $event->item->leased_by_agent_id,
    ]);

    // Check if order should auto-approve
    $order = $event->item->order;
    if ($order->items()->where('state', '!=', 'submitted')->doesntExist()) {
        Log::info('All items submitted, order ready for review', [
            'order_id' => $order->id,
        ]);
    }
});
```

---

### WorkItemFailed

**Namespace:** `GregPriday\WorkManager\Events\WorkItemFailed`

**When Fired:** When a work item transitions to failed state

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The failed work item |
| `$payload` | array\|null | Failure metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Alert on repeated failures
- Analyze failure patterns
- Trigger incident response
- Update failure metrics

**Example:**
```php
Event::listen(WorkItemFailed::class, function ($event) {
    Log::error('Work item failed', [
        'item_id' => $event->item->id,
        'type' => $event->item->type,
        'error' => $event->item->error,
        'attempts' => $event->item->attempts,
    ]);

    // Alert if max attempts exceeded
    if ($event->item->hasExhaustedAttempts()) {
        Notification::route('slack', config('slack.alerts_webhook'))
            ->notify(new WorkItemExhaustedNotification($event->item));
    }
});
```

---

### WorkItemLeaseExpired

**Namespace:** `GregPriday\WorkManager\Events\WorkItemLeaseExpired`

**When Fired:** When a lease expires and is reclaimed by the maintenance command

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The work item with expired lease |
| `$payload` | array\|null | Expiration metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track lease expiration rate
- Monitor agent reliability
- Alert on high expiration rates
- Update agent health scores

**Example:**
```php
Event::listen(WorkItemLeaseExpired::class, function ($event) {
    $agentId = $event->item->leased_by_agent_id;

    Redis::incr("agent:{$agentId}:expired_leases");

    Log::warning('Lease expired', [
        'item_id' => $event->item->id,
        'agent_id' => $agentId,
        'attempts' => $event->item->attempts,
    ]);
});
```

---

### WorkItemFinalized

**Namespace:** `GregPriday\WorkManager\Events\WorkItemFinalized`

**When Fired:** After all parts are assembled and the work item is finalized

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$item` | `WorkItem` | The finalized work item |
| `$payload` | array\|null | Finalization metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track partial submission workflows
- Analyze assembly success rate
- Monitor completion time
- Trigger result processing

**Example:**
```php
Event::listen(WorkItemFinalized::class, function ($event) {
    $partCount = $event->item->parts()->count();

    Log::info('Work item finalized', [
        'item_id' => $event->item->id,
        'parts_assembled' => $partCount,
    ]);
});
```

---

## Work Item Part Events

Events related to partial submissions. All extend `GregPriday\WorkManager\Events\WorkItemPartEvent`.

### WorkItemPartSubmitted

**Namespace:** `GregPriday\WorkManager\Events\WorkItemPartSubmitted`

**When Fired:** When a part is submitted (before validation)

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$part` | `WorkItemPart` | The submitted part |
| `$payload` | array\|null | Submission metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track submission frequency
- Monitor part submission patterns
- Update progress indicators

**Example:**
```php
Event::listen(WorkItemPartSubmitted::class, function ($event) {
    Log::info('Work item part submitted', [
        'part_id' => $event->part->id,
        'part_key' => $event->part->part_key,
        'seq' => $event->part->seq,
        'work_item_id' => $event->part->work_item_id,
    ]);
});
```

---

### WorkItemPartValidated

**Namespace:** `GregPriday\WorkManager\Events\WorkItemPartValidated`

**When Fired:** After a part passes validation

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$part` | `WorkItemPart` | The validated part |
| `$payload` | array\|null | Validation metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Track validation success rate
- Monitor data quality
- Update completion progress
- Trigger incremental processing

**Example:**
```php
Event::listen(WorkItemPartValidated::class, function ($event) {
    $item = $event->part->workItem;
    $validatedParts = $item->parts()->where('status', 'validated')->count();
    $totalParts = $item->parts()->count();

    Log::info('Part validated', [
        'part_key' => $event->part->part_key,
        'progress' => "{$validatedParts}/{$totalParts}",
    ]);
});
```

---

### WorkItemPartRejected

**Namespace:** `GregPriday\WorkManager\Events\WorkItemPartRejected`

**When Fired:** When a part fails validation

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$part` | `WorkItemPart` | The rejected part |
| `$payload` | array\|null | Rejection metadata |

**Example Payload:**
```php
null  // No additional payload
```

**Typical Listeners:**
- Alert on validation failures
- Track common validation errors
- Provide feedback to agents
- Update quality metrics

**Example:**
```php
Event::listen(WorkItemPartRejected::class, function ($event) {
    Log::warning('Work item part rejected', [
        'part_id' => $event->part->id,
        'part_key' => $event->part->part_key,
        'errors' => $event->part->errors,
        'submitted_by' => $event->part->submitted_by_agent_id,
    ]);

    // Track common validation errors
    foreach ($event->part->errors['validation'] ?? [] as $field => $messages) {
        Metrics::increment('part_validation_errors', [
            'field' => $field,
            'part_key' => $event->part->part_key,
        ]);
    }
});
```

---

## Listening to Events

### Using Event Listeners

**Create a Listener:**

```bash
php artisan make:listener WorkOrderApprovedListener
```

**Implement the Listener:**

```php
namespace App\Listeners;

use GregPriday\WorkManager\Events\WorkOrderApproved;
use Illuminate\Support\Facades\Log;

class WorkOrderApprovedListener
{
    public function handle(WorkOrderApproved $event): void
    {
        Log::info('Work order approved', [
            'order_id' => $event->order->id,
            'type' => $event->order->type,
        ]);

        // Your business logic
    }
}
```

**Register in EventServiceProvider:**

```php
protected $listen = [
    WorkOrderApproved::class => [
        WorkOrderApprovedListener::class,
    ],
];
```

---

### Using Closure Listeners

**In EventServiceProvider:**

```php
use GregPriday\WorkManager\Events\WorkOrderApplied;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(WorkOrderApplied::class, function ($event) {
        Cache::tags(['users'])->flush();
    });
}
```

---

### Using Event Subscribers

**Create a Subscriber:**

```bash
php artisan make:listener WorkOrderSubscriber
```

**Implement the Subscriber:**

```php
namespace App\Listeners;

use GregPriday\WorkManager\Events\WorkOrderProposed;
use GregPriday\WorkManager\Events\WorkOrderApproved;
use GregPriday\WorkManager\Events\WorkOrderApplied;
use Illuminate\Events\Dispatcher;

class WorkOrderSubscriber
{
    public function handleProposed(WorkOrderProposed $event): void
    {
        // Handle proposed
    }

    public function handleApproved(WorkOrderApproved $event): void
    {
        // Handle approved
    }

    public function handleApplied(WorkOrderApplied $event): void
    {
        // Handle applied
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            WorkOrderProposed::class => 'handleProposed',
            WorkOrderApproved::class => 'handleApproved',
            WorkOrderApplied::class => 'handleApplied',
        ];
    }
}
```

**Register in EventServiceProvider:**

```php
protected $subscribe = [
    WorkOrderSubscriber::class,
];
```

---

## Event Payloads

### Accessing Event Data

All events provide access to their associated models and optional payload:

```php
Event::listen(WorkOrderApplied::class, function ($event) {
    // Access the order
    $order = $event->order;
    echo $order->id;
    echo $order->type;
    echo $order->state->value;

    // Access relationships
    $items = $order->items;
    $events = $order->events;

    // Access payload (if available)
    $diff = $event->payload['diff'] ?? null;
});
```

### Payload Examples

**WorkOrderApplied:**
```php
$event->payload = [
    'diff' => [
        'summary' => 'Synced 100 users',
        'operations' => [...],
        'stats' => ['added' => 50, 'updated' => 45, 'deleted' => 5],
    ],
];
```

**Most Other Events:**
```php
$event->payload = null;  // No additional payload
```

---

## Typical Use Cases

### 1. Audit Logging

```php
Event::listen([
    WorkOrderProposed::class,
    WorkOrderApproved::class,
    WorkOrderRejected::class,
    WorkOrderApplied::class,
], function ($event) {
    AuditLog::create([
        'event' => class_basename($event),
        'order_id' => $event->order->id,
        'order_type' => $event->order->type,
        'user_id' => auth()->id(),
        'metadata' => $event->payload,
    ]);
});
```

### 2. Cache Invalidation

```php
Event::listen(WorkOrderApplied::class, function ($event) {
    match ($event->order->type) {
        'user.data.sync' => Cache::tags(['users'])->flush(),
        'product.data.sync' => Cache::tags(['products'])->flush(),
        default => null,
    };
});
```

### 3. Downstream Integrations

```php
Event::listen(WorkOrderApplied::class, function ($event) {
    // Trigger external webhooks
    WebhookJob::dispatch($event->order, $event->payload);

    // Update search index
    UpdateSearchIndexJob::dispatch($event->order);

    // Sync to data warehouse
    SyncToWarehouseJob::dispatch($event->order);
});
```

### 4. Metrics and Monitoring

```php
Event::listen([
    WorkItemLeased::class,
    WorkItemSubmitted::class,
    WorkItemFailed::class,
], function ($event) {
    $eventName = class_basename($event);

    Metrics::increment("work_items.{$eventName}", [
        'type' => $event->item->type,
        'agent' => $event->item->leased_by_agent_id ?? 'unknown',
    ]);
});
```

### 5. Notifications

```php
Event::listen(WorkOrderApproved::class, function ($event) {
    $order = $event->order;
    $requester = User::find($order->requested_by_id);

    if ($requester) {
        $requester->notify(new WorkOrderApprovedNotification($order));
    }
});

Event::listen(WorkItemFailed::class, function ($event) {
    if ($event->item->hasExhaustedAttempts()) {
        Notification::route('slack', config('slack.alerts_webhook'))
            ->notify(new WorkItemExhaustedNotification($event->item));
    }
});
```

### 6. Follow-up Work

```php
Event::listen(WorkOrderApplied::class, function ($event) {
    if ($event->order->type === 'user.data.sync') {
        // Dispatch job to send welcome emails
        SendWelcomeEmailsJob::dispatch($event->order);
    }
});
```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Database Schema](./database-schema.md) - Database structure for events
- [Config Reference](./config-reference.md) - Configuration options
