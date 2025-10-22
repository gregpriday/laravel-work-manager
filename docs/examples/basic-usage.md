# Basic Usage Example

This guide walks you through creating your first work order type with Laravel Work Manager. We'll build a minimal viable order type that demonstrates the core concepts.

## Overview

**What we're building**: A simple task execution system where agents can propose tasks, lease them, complete them, submit results, and have the system apply the changes.

**Use case**: Automated task management where AI agents propose work items (e.g., "update user profile", "send email", "process data") and execute them with verification.

**Difficulty**: Beginner

**Time to complete**: 10 minutes

## Prerequisites

- Laravel Work Manager installed and configured
- API routes mounted (see [overview.md](./overview.md))
- Authentication set up (Sanctum or similar)
- A test user with an API token

## Step 1: Create the Order Type Class

Create a new file at `app/WorkTypes/SimpleTaskType.php`:

```php
<?php

namespace App\WorkTypes;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\Log;

/**
 * Simple Task Execution
 *
 * This is the minimal viable order type demonstrating:
 * - Basic schema definition
 * - Simple validation rules
 * - Straightforward apply logic
 * - Lifecycle hooks
 */
class SimpleTaskType extends AbstractOrderType
{
    /**
     * Unique identifier for this work order type.
     *
     * Convention: Use dot notation (namespace.entity.action)
     */
    public function type(): string
    {
        return 'task.simple.execute';
    }

    /**
     * JSON schema for validating the order payload.
     *
     * This defines what data is required when creating a work order.
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['action', 'target'],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The action to perform',
                    'enum' => ['process', 'update', 'sync', 'verify'],
                ],
                'target' => [
                    'type' => 'string',
                    'description' => 'The target of the action',
                    'minLength' => 1,
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'normal', 'high', 'urgent'],
                    'default' => 'normal',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Optional additional data',
                ],
            ],
        ];
    }

    /**
     * Validation rules for agent submissions.
     *
     * These are standard Laravel validation rules.
     * The agent must submit data matching these rules.
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'result_data' => 'required|array',
            'result_data.status' => 'required|string',
            'result_data.message' => 'nullable|string|max:1000',
            'verified' => 'required|boolean|accepted',
            'execution_time_ms' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Custom validation after Laravel rules pass.
     *
     * Use this for business logic validation that goes beyond
     * what Laravel validation rules can express.
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Ensure success flag matches verification
        if ($result['success'] && !$result['verified']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'verified' => ['Successful tasks must be verified'],
            ]);
        }

        // Ensure result_data.status is meaningful
        $validStatuses = ['completed', 'partial', 'skipped'];
        if (!in_array($result['result_data']['status'], $validStatuses)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'result_data.status' => ['Status must be one of: ' . implode(', ', $validStatuses)],
            ]);
        }
    }

    /**
     * Check if the order can be approved.
     *
     * This is called when someone tries to approve the order.
     * Return false to block approval.
     */
    protected function canApprove(WorkOrder $order): bool
    {
        // Ensure all items were successful and verified
        foreach ($order->items as $item) {
            if (!($item->result['success'] ?? false) || !($item->result['verified'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook called before applying the work order.
     *
     * Use for setup, pre-checks, or preparing resources.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        Log::info('Starting task execution', [
            'order_id' => $order->id,
            'action' => $order->payload['action'],
            'target' => $order->payload['target'],
        ]);
    }

    /**
     * Apply the work order - perform the actual domain changes.
     *
     * This method MUST be idempotent - it may be called multiple times.
     */
    public function apply(WorkOrder $order): Diff
    {
        $action = $order->payload['action'];
        $target = $order->payload['target'];

        // Capture the "before" state
        $before = [
            'action' => $action,
            'target' => $target,
            'status' => 'pending',
        ];

        // Perform the actual work
        // In a real implementation, you would:
        // - Update database records
        // - Call external APIs
        // - Process files
        // - Execute business logic
        //
        // For this example, we'll just log the execution

        Log::info('Executing task', [
            'action' => $action,
            'target' => $target,
            'items_count' => $order->items->count(),
        ]);

        $resultsCount = 0;
        $totalExecutionTime = 0;

        foreach ($order->items as $item) {
            // Access the agent's submitted result
            $result = $item->result;

            // Example: Log the result
            Log::info('Processing item result', [
                'item_id' => $item->id,
                'status' => $result['result_data']['status'],
                'execution_time' => $result['execution_time_ms'] ?? 0,
            ]);

            $resultsCount++;
            $totalExecutionTime += $result['execution_time_ms'] ?? 0;
        }

        // Capture the "after" state
        $after = [
            'action' => $action,
            'target' => $target,
            'status' => 'completed',
            'results_count' => $resultsCount,
            'total_execution_time_ms' => $totalExecutionTime,
        ];

        // Return a diff describing what changed
        return $this->makeDiff(
            $before,
            $after,
            "Executed {$action} on {$target} with {$resultsCount} result(s)"
        );
    }

    /**
     * Hook called after successful apply.
     *
     * Use for cleanup, notifications, or triggering follow-up work.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        Log::info('Task execution completed', [
            'order_id' => $order->id,
            'changes' => $diff->toArray(),
        ]);

        // Example follow-up actions:
        // - Clear caches
        // - Send notifications
        // - Queue related jobs
        // - Update analytics
    }
}
```

## Step 2: Register the Order Type

Add to `app/Providers/AppServiceProvider.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\SimpleTaskType;

public function boot()
{
    WorkManager::registry()->register(new SimpleTaskType());
}
```

## Step 3: Test the Complete Workflow

Now let's test the complete lifecycle using API requests.

### 3.1: Propose a Work Order

```bash
curl -X POST http://your-app.test/api/ai/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: task-$(date +%s)" \
  -d '{
    "type": "task.simple.execute",
    "payload": {
      "action": "process",
      "target": "user-data",
      "priority": "high",
      "metadata": {
        "requested_by": "system"
      }
    }
  }'
```

**Response:**

```json
{
  "order": {
    "id": "9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6b",
    "type": "task.simple.execute",
    "state": "queued",
    "payload": {
      "action": "process",
      "target": "user-data",
      "priority": "high",
      "metadata": {
        "requested_by": "system"
      }
    },
    "created_at": "2025-01-22T10:00:00Z"
  },
  "items": [
    {
      "id": "9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6c",
      "state": "queued",
      "type": "task.simple.execute",
      "input": {
        "action": "process",
        "target": "user-data",
        "priority": "high",
        "metadata": {
          "requested_by": "system"
        }
      }
    }
  ]
}
```

### 3.2: Checkout a Work Item

The agent leases a work item to process:

```bash
curl -X POST http://your-app.test/api/ai/work/orders/9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6b/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: agent-001"
```

**Response:**

```json
{
  "item": {
    "id": "9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6c",
    "type": "task.simple.execute",
    "state": "leased",
    "input": {
      "action": "process",
      "target": "user-data",
      "priority": "high",
      "metadata": {
        "requested_by": "system"
      }
    },
    "lease_expires_at": "2025-01-22T10:10:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

### 3.3: Send Heartbeat (Optional)

If the work takes longer than 2 minutes, the agent must send heartbeats:

```bash
curl -X POST http://your-app.test/api/ai/work/items/9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6c/heartbeat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: agent-001"
```

### 3.4: Submit Results

After completing the work, the agent submits results:

```bash
curl -X POST http://your-app.test/api/ai/work/items/9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6c/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-$(date +%s)" \
  -H "X-Agent-ID: agent-001" \
  -d '{
    "result": {
      "success": true,
      "result_data": {
        "status": "completed",
        "message": "User data processed successfully"
      },
      "verified": true,
      "execution_time_ms": 1250
    }
  }'
```

**Response:**

```json
{
  "item": {
    "id": "9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6c",
    "state": "submitted",
    "result": {
      "success": true,
      "result_data": {
        "status": "completed",
        "message": "User data processed successfully"
      },
      "verified": true,
      "execution_time_ms": 1250
    }
  }
}
```

### 3.5: Approve and Apply

A human or system approves the work, triggering the apply() method:

```bash
curl -X POST http://your-app.test/api/ai/work/orders/9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6b/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: approve-$(date +%s)"
```

**Response:**

```json
{
  "order": {
    "id": "9d5f8a2b-3c1e-4d6f-8b9a-1c2d3e4f5a6b",
    "state": "completed",
    "approved_at": "2025-01-22T10:05:00Z",
    "completed_at": "2025-01-22T10:05:00Z"
  },
  "diff": {
    "before": {
      "action": "process",
      "target": "user-data",
      "status": "pending"
    },
    "after": {
      "action": "process",
      "target": "user-data",
      "status": "completed",
      "results_count": 1,
      "total_execution_time_ms": 1250
    },
    "changes": {
      "status": {
        "from": "pending",
        "to": "completed"
      }
    },
    "summary": "Executed process on user-data with 1 result(s)"
  }
}
```

## Expected Output

After following these steps:

1. A work order was created in the `queued` state
2. An agent leased the work item
3. The agent submitted results with verification
4. The system validated the submission
5. A human/system approved the order
6. The apply() method executed
7. The order transitioned to `completed`
8. A diff was generated showing what changed

## Key Learnings

### 1. Type Identifier

The `type()` method returns a unique string identifying this order type:

```php
public function type(): string
{
    return 'task.simple.execute'; // namespace.entity.action
}
```

### 2. Schema Definition

The `schema()` method defines what data is required when creating orders:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['action', 'target'],
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['process', 'update']],
            'target' => ['type' => 'string'],
        ],
    ];
}
```

### 3. Submission Validation

The `submissionValidationRules()` method defines what agents must submit:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'result_data' => 'required|array',
        'verified' => 'required|boolean|accepted',
    ];
}
```

### 4. Custom Validation

The `afterValidateSubmission()` method adds business logic validation:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    if ($result['success'] && !$result['verified']) {
        throw ValidationException::withMessages([
            'verified' => ['Successful tasks must be verified'],
        ]);
    }
}
```

### 5. Approval Gates

The `canApprove()` method controls when orders can be approved:

```php
protected function canApprove(WorkOrder $order): bool
{
    foreach ($order->items as $item) {
        if (!($item->result['success'] ?? false)) {
            return false; // Block approval if any item failed
        }
    }
    return true;
}
```

### 6. Idempotent Apply

The `apply()` method performs the actual work and MUST be idempotent:

```php
public function apply(WorkOrder $order): Diff
{
    // Capture before state
    $before = ['status' => 'pending'];

    // Perform mutations (should be idempotent)
    Log::info('Executing work');

    // Capture after state
    $after = ['status' => 'completed'];

    return $this->makeDiff($before, $after, 'Summary');
}
```

## Variations and Extensions

### Variation 1: Add Custom Planning

Override `plan()` to customize how work items are created:

```php
public function plan(WorkOrder $order): array
{
    // Create multiple items for parallel processing
    return [
        [
            'type' => $this->type(),
            'input' => ['action' => $order->payload['action'], 'target' => 'part-1'],
            'max_attempts' => 3,
        ],
        [
            'type' => $this->type(),
            'input' => ['action' => $order->payload['action'], 'target' => 'part-2'],
            'max_attempts' => 3,
        ],
    ];
}
```

### Variation 2: Add Evidence Requirements

Require agents to provide proof of their work:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'result_data' => 'required|array',
        'verified' => 'required|boolean|accepted',
        'evidence' => 'required|array|min:1',
        'evidence.*.url' => 'required|url',
        'evidence.*.timestamp' => 'required|date',
    ];
}
```

### Variation 3: Add Database Mutations

Make apply() actually modify the database:

```php
public function apply(WorkOrder $order): Diff
{
    $before = ['count' => Task::count()];

    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            Task::create([
                'action' => $item->input['action'],
                'target' => $item->input['target'],
                'result' => $item->result['result_data'],
                'completed_at' => now(),
            ]);
        }
    });

    $after = ['count' => Task::count()];

    return $this->makeDiff($before, $after, 'Created tasks');
}
```

### Variation 4: Add Separate Acceptance Policy

Move validation logic to a dedicated class:

```php
use GregPriday\WorkManager\Support\AbstractAcceptancePolicy;

class SimpleTaskAcceptancePolicy extends AbstractAcceptancePolicy
{
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'result_data' => 'required|array',
            'verified' => 'required|boolean|accepted',
        ];
    }

    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        if ($result['success'] && !$result['verified']) {
            throw ValidationException::withMessages([
                'verified' => ['Successful tasks must be verified'],
            ]);
        }
    }

    protected function canApprove(WorkOrder $order): bool
    {
        return $order->items->every(fn($item) => $item->result['success'] ?? false);
    }
}

// In your OrderType:
public function acceptancePolicy(): ?AcceptancePolicy
{
    return new SimpleTaskAcceptancePolicy();
}
```

## Next Steps

1. **Add More Validation**: Enhance validation rules for your domain
2. **Add Database Operations**: Make apply() perform real database mutations
3. **Add Event Listeners**: React to work order events
4. **Try Advanced Examples**: Check out [user-data-sync.md](./user-data-sync.md) for external API integration

## Troubleshooting

### Validation Failed

If submission validation fails, check the error response:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "verified": ["Successful tasks must be verified"]
  }
}
```

Fix the agent's submission to match the validation rules.

### Approval Blocked

If approval is blocked, check the `canApprove()` logic. The order must meet all requirements before approval is allowed.

### Apply Failed

If apply() throws an exception, the order transitions to `failed` state. Check logs for the exception details and fix the apply() logic.
