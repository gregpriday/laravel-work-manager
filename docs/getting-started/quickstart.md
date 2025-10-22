# Quickstart: Your First Work Order Type

Build your first work order type in 5 minutes.

## What We'll Build

A simple work order type that processes data transformation tasks. An agent will:
1. Receive input data
2. Transform it according to rules
3. Submit results
4. System validates and applies the changes

## Step 1: Create Your Order Type Class

Create `app/WorkTypes/DataTransformType.php`:

```php
<?php

namespace App\WorkTypes;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataTransformType extends AbstractOrderType
{
    // 1. Define the type identifier
    public function type(): string
    {
        return 'data.transform';
    }

    // 2. Define the JSON schema for the order payload
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['operation', 'data'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['uppercase', 'lowercase', 'reverse'],
                    'description' => 'Transformation operation to perform',
                ],
                'data' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of strings to transform',
                ],
            ],
        ];
    }

    // 3. Define validation rules for agent submissions
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'transformed_data' => 'required|array',
            'transformed_data.*' => 'string',
            'verified' => 'required|boolean|accepted',
        ];
    }

    // 4. Add custom verification logic (optional)
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify the agent transformed the correct number of items
        $expectedCount = count($item->input['data']);
        $actualCount = count($result['transformed_data']);

        if ($expectedCount !== $actualCount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'transformed_data' => ["Expected {$expectedCount} items, got {$actualCount}"],
            ]);
        }

        // Verify transformation was actually applied
        $operation = $item->input['operation'];
        foreach ($result['transformed_data'] as $index => $transformed) {
            $original = $item->input['data'][$index];

            $expected = match ($operation) {
                'uppercase' => strtoupper($original),
                'lowercase' => strtolower($original),
                'reverse' => strrev($original),
            };

            if ($transformed !== $expected) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "transformed_data.{$index}" => [
                        "Incorrect transformation. Expected '{$expected}', got '{$transformed}'"
                    ],
                ]);
            }
        }
    }

    // 5. Implement the idempotent apply logic
    public function apply(WorkOrder $order): Diff
    {
        $processedCount = 0;
        $before = ['status' => 'pending', 'count' => 0];

        DB::transaction(function () use ($order, &$processedCount) {
            foreach ($order->items as $item) {
                // Your domain logic here - in this example, we just log
                Log::info('Applying transformation', [
                    'operation' => $item->input['operation'],
                    'item_count' => count($item->result['transformed_data']),
                ]);

                // In a real application, you might:
                // - Update database records
                // - Write files
                // - Call external APIs
                // - Dispatch follow-up jobs

                $processedCount += count($item->result['transformed_data']);
            }
        });

        $after = ['status' => 'completed', 'count' => $processedCount];

        return $this->makeDiff(
            $before,
            $after,
            "Transformed {$processedCount} items using {$order->payload['operation']} operation"
        );
    }

    // 6. Add lifecycle hooks (optional)
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        Log::info('Data transformation completed', [
            'order_id' => $order->id,
            'diff' => $diff->toArray(),
        ]);

        // Dispatch follow-up jobs, clear caches, send notifications, etc.
    }
}
```

## Step 2: Register Your Type

In `app/Providers/AppServiceProvider.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\DataTransformType;

public function boot()
{
    WorkManager::registry()->register(new DataTransformType());
}
```

## Step 3: Mount API Routes

In `routes/api.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum']
);
```

> **📝 Base Path Configuration**
>
> This mounts routes at `/api/agent/work/*` (Laravel adds the `/api` prefix automatically).
>
> To use a different base path, change the `basePath` parameter:
> ```php
> WorkManager::routes(basePath: 'agent/work', middleware: ['api', 'auth:sanctum']);
> // Routes will be at /api/agent/work/*
> ```

## Step 4: Test the Complete Workflow

### 4.1 Propose a Work Order

```bash
curl -X POST http://your-app.test/api/agent/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: test-propose-1" \
  -d '{
    "type": "data.transform",
    "payload": {
      "operation": "uppercase",
      "data": ["hello", "world", "laravel"]
    }
  }'
```

**Response:**
```json
{
  "order": {
    "id": "9c8f3d2e-1234-5678-90ab-cdef12345678",
    "type": "data.transform",
    "state": "queued",
    "priority": 0,
    "payload": {
      "operation": "uppercase",
      "data": ["hello", "world", "laravel"]
    },
    "created_at": "2025-01-22T10:00:00Z"
  },
  "items_count": 1
}
```

### 4.2 Checkout a Work Item

```bash
curl -X POST http://your-app.test/api/agent/work/orders/9c8f3d2e-1234-5678-90ab-cdef12345678/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: test-agent-1"
```

**Response:**
```json
{
  "item": {
    "id": "item-uuid-here",
    "order_id": "9c8f3d2e-1234-5678-90ab-cdef12345678",
    "type": "data.transform",
    "state": "leased",
    "input": {
      "operation": "uppercase",
      "data": ["hello", "world", "laravel"]
    },
    "lease_expires_at": "2025-01-22T10:10:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

### 4.3 Submit Results

```bash
curl -X POST http://your-app.test/api/agent/work/items/item-uuid-here/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: test-submit-1" \
  -H "X-Agent-ID: test-agent-1" \
  -d '{
    "result": {
      "success": true,
      "transformed_data": ["HELLO", "WORLD", "LARAVEL"],
      "verified": true
    }
  }'
```

**Response:**
```json
{
  "item": {
    "id": "item-uuid-here",
    "state": "submitted",
    "result": {
      "success": true,
      "transformed_data": ["HELLO", "WORLD", "LARAVEL"],
      "verified": true
    }
  },
  "order_state": "submitted"
}
```

### 4.4 Approve and Apply

```bash
curl -X POST http://your-app.test/api/agent/work/orders/9c8f3d2e-1234-5678-90ab-cdef12345678/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: test-approve-1"
```

**Response:**
```json
{
  "order": {
    "id": "9c8f3d2e-1234-5678-90ab-cdef12345678",
    "state": "completed",
    "applied_at": "2025-01-22T10:05:00Z",
    "completed_at": "2025-01-22T10:05:01Z"
  },
  "diff": {
    "before": {
      "status": "pending",
      "count": 0
    },
    "after": {
      "status": "completed",
      "count": 3
    },
    "summary": "Transformed 3 items using uppercase operation"
  }
}
```

## Done! 🎉

You've successfully:
- ✅ Created a custom order type with validation
- ✅ Registered it with Work Manager
- ✅ Proposed a work order
- ✅ Checked out (leased) a work item
- ✅ Submitted results with validation
- ✅ Approved and applied the work

## Understanding What Happened

### Lifecycle Flow

```
1. Propose → System validated payload against schema
2. Plan    → System created 1 work item (default behavior)
3. Checkout → Agent leased the item (TTL: 10 minutes)
4. Submit  → System validated results against rules
5. Verify  → Custom logic verified transformations
6. Approve → Backend user/system approved the work
7. Apply   → Your apply() method executed
8. Complete → Order marked completed with diff recorded
```

### State Transitions

**Work Order:**
```
queued → checked_out → in_progress → submitted → approved → applied → completed
```

**Work Item:**
```
queued → leased → in_progress → submitted → accepted → completed
```

### Audit Trail

Every action was recorded. View the event log:

```bash
curl http://your-app.test/api/agent/work/items/item-uuid-here/logs \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Next Steps

### Add More Validation

Enhance `afterValidateSubmission()` with business logic:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Check external system
    if (!$this->externalApi->verify($result['data'])) {
        throw ValidationException::withMessages([
            'data' => ['External verification failed'],
        ]);
    }

    // Validate against database
    $existing = Model::where('id', $result['id'])->first();
    if ($existing) {
        throw ValidationException::withMessages([
            'id' => ['Record already exists'],
        ]);
    }
}
```

### Add Lifecycle Hooks

```php
protected function beforeApply(WorkOrder $order): void
{
    // Setup: acquire locks, backup data, notify systems
    $this->acquireExclusiveLock($order->payload['resource']);
}

protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Cleanup: dispatch jobs, clear caches, send webhooks
    ProcessFollowUp::dispatch($order)->onQueue('work');
    Cache::tags(['transforms'])->flush();
}
```

### Break Into Multiple Items

Override `plan()` to create multiple work items:

```php
public function plan(WorkOrder $order): array
{
    $data = $order->payload['data'];
    $batchSize = 10;
    $batches = array_chunk($data, $batchSize);

    return array_map(function ($batch) use ($order) {
        return [
            'type' => $this->type(),
            'input' => [
                'operation' => $order->payload['operation'],
                'data' => $batch,
            ],
            'max_attempts' => 3,
        ];
    }, $batches);
}
```

### Listen to Events

In `EventServiceProvider`:

```php
use GregPriday\WorkManager\Events\WorkOrderCompleted;

Event::listen(WorkOrderCompleted::class, function ($event) {
    Log::info('Order completed', [
        'order_id' => $event->order->id,
        'type' => $event->order->type,
    ]);

    // Trigger downstream processes
    NotifyStakeholders::dispatch($event->order);
});
```

### Use the MCP Server

Start the MCP server for AI agents:

```bash
php artisan work-manager:mcp --transport=stdio
```

Configure in Cursor or Claude Desktop to enable AI agents to discover and use work tools automatically.

---

## See Also

- [Creating Order Types](../guides/creating-order-types.md) - Complete order type guide
- [Validation & Acceptance Policies](../guides/validation-and-acceptance-policies.md) - Advanced validation
- [Lifecycle & Flow](../concepts/lifecycle-and-flow.md) - Understanding the complete lifecycle
- [HTTP API](../guides/http-api.md) - Complete API reference
- [MCP Server Integration](../guides/mcp-server-integration.md) - AI agent integration
- [Examples](../examples/overview.md) - More complex examples
