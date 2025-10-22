# Events and Listeners Guide

**By the end of this guide, you'll be able to:** Subscribe to Work Manager events, understand event payloads, and implement common event-driven patterns.

---

## Available Events

### Work Order Events

All in `GregPriday\WorkManager\Events` namespace:

- `WorkOrderProposed` - New order created
- `WorkOrderPlanned` - Order broken into work items
- `WorkOrderCheckedOut` - First item checked out
- `WorkOrderApproved` - Order approved
- `WorkOrderApplied` - Order apply() executed
- `WorkOrderCompleted` - Order finished
- `WorkOrderRejected` - Order rejected

### Work Item Events

- `WorkItemLeased` - Item checked out by agent
- `WorkItemHeartbeat` - Lease extended
- `WorkItemSubmitted` - Results submitted
- `WorkItemFailed` - Item processing failed
- `WorkItemLeaseExpired` - Lease expired
- `WorkItemFinalized` - Parts assembled into result

### Work Item Part Events

- `WorkItemPartSubmitted` - Partial result submitted
- `WorkItemPartValidated` - Part validation passed
- `WorkItemPartRejected` - Part validation failed

---

## Subscribing to Events

### In EventServiceProvider

```php
// app/Providers/EventServiceProvider.php
use GregPriday\WorkManager\Events\WorkOrderApplied;
use GregPriday\WorkManager\Events\WorkItemSubmitted;
use App\Listeners\LogWorkOrderApplied;

protected $listen = [
    WorkOrderApplied::class => [
        LogWorkOrderApplied::class,
    ],
    WorkItemSubmitted::class => [
        'App\Listeners\TrackSubmissionMetrics',
    ],
];
```

### In AppServiceProvider

```php
use GregPriday\WorkManager\Events\WorkOrderCompleted;
use Illuminate\Support\Facades\Event;

public function boot()
{
    Event::listen(WorkOrderCompleted::class, function ($event) {
        Log::info('Work order completed', [
            'order_id' => $event->order->id,
            'type' => $event->order->type,
        ]);
    });
}
```

---

## Event Payloads

### WorkOrderApplied

```php
class WorkOrderApplied
{
    public function __construct(
        public WorkOrder $order,
        public Diff $diff,
        public ?string $actorId = null
    ) {}
}
```

**Usage**:
```php
Event::listen(WorkOrderApplied::class, function ($event) {
    $order = $event->order;
    $changes = $event->diff->toArray();

    // Send notifications, update analytics, etc.
});
```

### WorkItemSubmitted

```php
class WorkItemSubmitted
{
    public function __construct(
        public WorkItem $item
    ) {}
}
```

---

## Common Patterns

### Pattern 1: Metrics and Observability

```php
Event::listen(WorkItemSubmitted::class, function ($event) {
    Metrics::increment('work_items.submitted', [
        'type' => $event->item->type,
    ]);

    Metrics::gauge('work_items.processing_time',
        $event->item->created_at->diffInSeconds(now()),
        ['type' => $event->item->type]
    );
});
```

### Pattern 2: Notifications

```php
Event::listen(WorkOrderCompleted::class, function ($event) {
    $admins = User::where('role', 'admin')->get();

    Notification::send($admins, new WorkOrderCompletedNotification(
        $event->order
    ));
});
```

### Pattern 3: Follow-up Actions

```php
Event::listen(WorkOrderApplied::class, function ($event) {
    // Queue follow-up work
    if ($event->order->type === 'user.data.sync') {
        GenerateUserReportJob::dispatch($event->order)->delay(now()->addMinutes(5));
    }
});
```

---

## See Also

- [Creating Order Types Guide](creating-order-types.md) - Lifecycle hooks
- [Queues and Jobs Guide](queues-and-jobs.md) - Dispatching jobs
- Laravel [Events Documentation](https://laravel.com/docs/events)
