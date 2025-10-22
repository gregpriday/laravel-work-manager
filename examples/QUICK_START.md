# Quick Start: Create Your First Work Order Type

This guide gets you up and running in 5 minutes.

## Step 1: Create Your Order Type Class

```php
// app/WorkTypes/MyFirstWorkType.php

namespace App\WorkTypes;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;

class MyFirstWorkType extends AbstractOrderType
{
    // 1. Define the type identifier
    public function type(): string
    {
        return 'my.first.work';
    }

    // 2. Define what data is required when creating the order
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['action', 'target'],
            'properties' => [
                'action' => ['type' => 'string'],
                'target' => ['type' => 'string'],
            ],
        ];
    }

    // 3. Define what agents must submit after completing work
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'result_data' => 'required|array',
            'verified' => 'required|boolean|accepted',
        ];
    }

    // 4. Perform the actual work (this runs after approval)
    public function apply(WorkOrder $order): Diff
    {
        $action = $order->payload['action'];
        $target = $order->payload['target'];

        // TODO: Implement your domain logic here
        // For example:
        // - Insert database records
        // - Call external APIs
        // - Update files
        // - Process data

        return $this->makeDiff(
            ['status' => 'pending'],
            ['status' => 'completed'],
            "Executed {$action} on {$target}"
        );
    }
}
```

## Step 2: Register Your Type

In `app/Providers/AppServiceProvider.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\MyFirstWorkType;

public function boot()
{
    WorkManager::registry()->register(new MyFirstWorkType());
}
```

## Step 3: Mount the API Routes

In `routes/api.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;

// Simple approach - mount all routes under /ai/work
WorkManager::routes(
    basePath: 'ai/work',
    middleware: ['api', 'auth:sanctum']
);
```

## Step 4: Test It!

### Create a work order:

```bash
curl -X POST http://your-app.test/api/ai/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: test-123" \
  -d '{
    "type": "my.first.work",
    "payload": {
      "action": "test",
      "target": "system"
    }
  }'
```

Response:
```json
{
  "order": {
    "id": "uuid-here",
    "type": "my.first.work",
    "state": "queued",
    "payload": {...}
  }
}
```

### Checkout (lease) a work item:

```bash
curl -X POST http://your-app.test/api/ai/work/orders/{order-id}/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: test-agent"
```

Response:
```json
{
  "item": {
    "id": "item-uuid",
    "type": "my.first.work",
    "input": {...},
    "lease_expires_at": "2025-01-22T12:10:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

### Submit results:

```bash
curl -X POST http://your-app.test/api/ai/work/items/{item-id}/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-456" \
  -H "X-Agent-ID: test-agent" \
  -d '{
    "result": {
      "success": true,
      "result_data": {"status": "ok"},
      "verified": true
    }
  }'
```

### Approve and apply:

```bash
curl -X POST http://your-app.test/api/ai/work/orders/{order-id}/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: approve-789"
```

Response:
```json
{
  "order": {
    "id": "uuid",
    "state": "completed"
  },
  "diff": {
    "before": {"status": "pending"},
    "after": {"status": "completed"},
    "changes": {...},
    "summary": "Executed test on system"
  }
}
```

## Done! 🎉

You now have a working work order system. The agent proposed work, checked it out, completed it, submitted results, and the system verified and applied it.

## Next Steps

1. **Add More Validation**: Enhance `submissionValidationRules()` with your business rules
2. **Add Lifecycle Hooks**: Use `beforeApply()` and `afterApply()` for setup/cleanup
3. **Add Custom Verification**: Override `afterValidateSubmission()` for complex checks
4. **Listen to Events**: Use Laravel events to react to work order lifecycle
5. **Add Planning**: Override `plan()` to break work into multiple items
6. **Schedule Generation**: Create an `AllocatorStrategy` to auto-generate work

See `examples/LIFECYCLE.md` for detailed documentation on all available hooks and features.

See `examples/UserDataSyncType.php` for a complete, production-ready example.
