# Queues and Jobs Guide

**By the end of this guide, you'll be able to:** Use queues with Work Manager, dispatch jobs in lifecycle hooks, and configure queue settings.

---

## Queue Configuration

Configure in `config/work-manager.php`:

```php
'queues' => [
    'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'redis'),
    'maintenance_queue' => 'work:maintenance',
    'planning_queue' => 'work:planning',
    'agent_job_queue_prefix' => 'agents:',
],
```

---

## Dispatching Jobs in Hooks

### After Apply

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Queue notifications
    SendOrderCompletionNotification::dispatch($order)
        ->onQueue('notifications');

    // Queue analytics update
    UpdateAnalytics::dispatch($diff->toArray())
        ->onQueue('analytics');

    // Queue follow-up work
    ProcessResults::dispatch($order)
        ->delay(now()->addMinutes(5))
        ->onQueue('processing');
}
```

### In Event Listeners

```php
Event::listen(WorkOrderCompleted::class, function ($event) {
    GenerateReport::dispatch($event->order)->onQueue('reports');
});
```

---

## See Also

- [Events and Listeners Guide](events-and-listeners.md)
- [Creating Order Types Guide](creating-order-types.md)
- Laravel [Queues Documentation](https://laravel.com/docs/queues)
