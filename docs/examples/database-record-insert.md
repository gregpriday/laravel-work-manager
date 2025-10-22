# Database Record Insert Example

This example demonstrates batch database operations with comprehensive verification, lifecycle hooks, and transaction safety.

## Overview

**What we're building**: A work order type that safely inserts batches of database records with multi-level verification.

**Use case**: Importing data from external sources (CSVs, APIs, data feeds) where you need to verify data integrity before and after insertion.

**Difficulty**: Beginner to Intermediate

**Key Features**:
- Batch database inserts
- Verification logic
- Lifecycle hooks (beforeApply, afterApply)
- Database transactions
- Event handling
- Custom validation

## Complete Code

Create `app/WorkTypes/DatabaseRecordInsertType.php`:

```php
<?php

namespace App\WorkTypes;

use App\Models\Product;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database Record Insert Example
 *
 * This example shows:
 * - Using AbstractOrderType for reduced boilerplate
 * - Laravel validation integration
 * - Lifecycle hooks (beforeApply, afterApply)
 * - Custom verification logic
 * - Database transactions
 * - Event handling
 */
class DatabaseRecordInsertType extends AbstractOrderType
{
    /**
     * The unique identifier for this work order type.
     */
    public function type(): string
    {
        return 'database.record.insert';
    }

    /**
     * JSON schema for validating the initial payload.
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['table', 'records'],
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'enum' => ['products', 'categories', 'tags'], // Allowed tables
                ],
                'records' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'validate' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Define validation rules for agent submissions.
     * These are standard Laravel validation rules.
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'inserted' => 'required|boolean',
            'record_ids' => 'required_if:inserted,true|array',
            'record_ids.*' => 'integer|min:1',
            'verification' => 'required|array',
            'verification.checked' => 'required|boolean',
            'verification.valid' => 'required_if:verification.checked,true|boolean',
        ];
    }

    /**
     * Custom validation after Laravel rules pass.
     * Use this to implement business logic validation.
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify that the records were actually inserted
        if ($result['inserted']) {
            $recordIds = $result['record_ids'];
            $table = $item->order->payload['table'];

            // Check records exist in database
            $model = $this->getModelForTable($table);
            $existingCount = $model::whereIn('id', $recordIds)->count();

            if ($existingCount !== count($recordIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'record_ids' => ['Not all record IDs exist in the database'],
                ]);
            }
        }

        // Verify the verification step was done
        if (!$result['verification']['checked'] || !$result['verification']['valid']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'verification' => ['Records must be verified as valid before submission'],
            ]);
        }
    }

    /**
     * Custom approval check.
     * Only approve if all items are verified as valid.
     */
    protected function canApprove(WorkOrder $order): bool
    {
        // Check all submitted items have valid verification
        foreach ($order->items as $item) {
            if (!isset($item->result['verification']['valid']) ||
                !$item->result['verification']['valid']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook called before applying the work order.
     * Use for setup or pre-checks.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        Log::info('Starting to apply database record insertions', [
            'order_id' => $order->id,
            'table' => $order->payload['table'],
            'record_count' => count($order->payload['records']),
        ]);

        // Could do things like:
        // - Check system capacity
        // - Acquire locks
        // - Backup existing data
    }

    /**
     * Apply the work order - actually perform the database operations.
     * This should be idempotent!
     */
    public function apply(WorkOrder $order): Diff
    {
        $table = $order->payload['table'];
        $insertedIds = [];
        $before = ['table' => $table, 'record_count' => 0];

        // Collect all inserted IDs from work items
        foreach ($order->items as $item) {
            if (isset($item->result['record_ids'])) {
                $insertedIds = array_merge($insertedIds, $item->result['record_ids']);
            }
        }

        // Get current state
        $model = $this->getModelForTable($table);
        $records = $model::whereIn('id', $insertedIds)->get();

        $after = [
            'table' => $table,
            'record_count' => $records->count(),
            'record_ids' => $insertedIds,
        ];

        // Mark records as "applied" (example: update a status field)
        if (!empty($insertedIds) && $model::where('id', $insertedIds[0])->value('id')) {
            // Records exist, mark them as processed
            DB::table($table)
                ->whereIn('id', $insertedIds)
                ->update(['processed' => true, 'processed_at' => now()]);
        }

        return $this->makeDiff(
            $before,
            $after,
            "Inserted and processed {$records->count()} records into {$table}"
        );
    }

    /**
     * Hook called after successful apply.
     * Use for cleanup or triggering downstream processes.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        Log::info('Successfully applied database record insertions', [
            'order_id' => $order->id,
            'changes' => $diff->toArray(),
        ]);

        // Could do things like:
        // - Trigger cache invalidation
        // - Send notifications
        // - Queue follow-up work
        // - Update analytics

        // Example: Dispatch an event
        event(new \App\Events\RecordsProcessed(
            $order->payload['table'],
            $diff->after['record_ids'] ?? []
        ));
    }

    /**
     * Helper to get the Eloquent model for a table name.
     */
    protected function getModelForTable(string $table): string
    {
        return match ($table) {
            'products' => Product::class,
            'categories' => \App\Models\Category::class,
            'tags' => \App\Models\Tag::class,
            default => throw new \Exception("Unknown table: {$table}"),
        };
    }
}
```

## Step-by-Step Walkthrough

### 1. Schema Definition

The schema defines what data is required when creating the work order:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['table', 'records'],
        'properties' => [
            'table' => [
                'type' => 'string',
                'enum' => ['products', 'categories', 'tags'],
            ],
            'records' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['type' => 'object'],
                        'validate' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
    ];
}
```

This enforces that orders must specify:
- Which table to insert into (limited to allowed tables)
- An array of records with data objects

### 2. Submission Validation

The agent must prove they actually inserted the records:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'inserted' => 'required|boolean',
        'record_ids' => 'required_if:inserted,true|array',
        'record_ids.*' => 'integer|min:1',
        'verification' => 'required|array',
        'verification.checked' => 'required|boolean',
        'verification.valid' => 'required_if:verification.checked,true|boolean',
    ];
}
```

The agent must provide:
- Whether insertion succeeded
- The IDs of inserted records
- Verification that records are valid

### 3. Custom Verification

Beyond Laravel rules, we verify records actually exist:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    if ($result['inserted']) {
        $recordIds = $result['record_ids'];
        $table = $item->order->payload['table'];

        $model = $this->getModelForTable($table);
        $existingCount = $model::whereIn('id', $recordIds)->count();

        if ($existingCount !== count($recordIds)) {
            throw ValidationException::withMessages([
                'record_ids' => ['Not all record IDs exist in the database'],
            ]);
        }
    }
}
```

This prevents agents from lying about inserting records.

### 4. Lifecycle Hooks

Use hooks to add logging and side effects:

```php
// Before execution
protected function beforeApply(WorkOrder $order): void
{
    Log::info('Starting to apply database record insertions');
    // Could acquire locks, backup data, etc.
}

// After execution
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    Log::info('Successfully applied database record insertions');
    event(new RecordsProcessed(...));
}
```

### 5. Idempotent Apply

The apply method marks records as processed without re-inserting:

```php
public function apply(WorkOrder $order): Diff
{
    // Collect IDs that were already inserted by the agent
    $insertedIds = [];
    foreach ($order->items as $item) {
        $insertedIds = array_merge($insertedIds, $item->result['record_ids']);
    }

    // Mark as processed (idempotent operation)
    DB::table($table)
        ->whereIn('id', $insertedIds)
        ->update(['processed' => true, 'processed_at' => now()]);

    return $this->makeDiff($before, $after, 'Summary');
}
```

## Example API Interactions

### 1. Propose Work Order

```bash
curl -X POST http://your-app.test/api/agent/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: insert-$(date +%s)" \
  -d '{
    "type": "database.record.insert",
    "payload": {
      "table": "products",
      "records": [
        {
          "data": {
            "name": "Widget Pro",
            "price": 29.99,
            "sku": "WP-001"
          },
          "validate": true
        },
        {
          "data": {
            "name": "Gadget Max",
            "price": 49.99,
            "sku": "GM-002"
          },
          "validate": true
        }
      ]
    }
  }'
```

**Response:**

```json
{
  "order": {
    "id": "order-uuid",
    "type": "database.record.insert",
    "state": "queued",
    "payload": {
      "table": "products",
      "records": [...]
    }
  },
  "items": [
    {
      "id": "item-uuid",
      "state": "queued",
      "input": {
        "table": "products",
        "records": [...]
      }
    }
  ]
}
```

### 2. Checkout Work Item

```bash
curl -X POST http://your-app.test/api/agent/work/orders/order-uuid/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: import-agent-01"
```

**Response:**

```json
{
  "item": {
    "id": "item-uuid",
    "state": "leased",
    "input": {
      "table": "products",
      "records": [
        {"data": {"name": "Widget Pro", "price": 29.99, "sku": "WP-001"}},
        {"data": {"name": "Gadget Max", "price": 49.99, "sku": "GM-002"}}
      ]
    },
    "lease_expires_at": "2025-01-22T10:10:00Z"
  }
}
```

### 3. Agent Inserts Records

The agent would now:
1. Read the input records
2. Insert them into the products table
3. Verify the insertions
4. Collect the IDs

Example agent code:

```php
// Agent processing logic
$input = $item->input;
$insertedIds = [];

foreach ($input['records'] as $record) {
    $product = Product::create($record['data']);
    $insertedIds[] = $product->id;
}

// Verify insertions
$verified = Product::whereIn('id', $insertedIds)->count() === count($insertedIds);

$result = [
    'inserted' => true,
    'record_ids' => $insertedIds,
    'verification' => [
        'checked' => true,
        'valid' => $verified,
    ],
];
```

### 4. Submit Results

```bash
curl -X POST http://your-app.test/api/agent/work/items/item-uuid/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-$(date +%s)" \
  -H "X-Agent-ID: import-agent-01" \
  -d '{
    "result": {
      "inserted": true,
      "record_ids": [1001, 1002],
      "verification": {
        "checked": true,
        "valid": true
      }
    }
  }'
```

**Response:**

```json
{
  "item": {
    "id": "item-uuid",
    "state": "submitted",
    "result": {
      "inserted": true,
      "record_ids": [1001, 1002],
      "verification": {
        "checked": true,
        "valid": true
      }
    }
  }
}
```

### 5. Approve and Apply

```bash
curl -X POST http://your-app.test/api/agent/work/orders/order-uuid/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: approve-$(date +%s)"
```

**Response:**

```json
{
  "order": {
    "id": "order-uuid",
    "state": "completed"
  },
  "diff": {
    "before": {
      "table": "products",
      "record_count": 0
    },
    "after": {
      "table": "products",
      "record_count": 2,
      "record_ids": [1001, 1002]
    },
    "summary": "Inserted and processed 2 records into products"
  }
}
```

## Expected Output

After completing the workflow:

1. Two products are inserted into the database
2. The system verifies they exist
3. On approval, they're marked as "processed"
4. A diff shows before/after state
5. An event is dispatched for downstream processing

## Key Learnings

### 1. Multi-Level Validation

This example demonstrates three validation levels:

- **Schema validation**: Payload structure
- **Laravel rules**: Field-level validation
- **Custom verification**: Database consistency checks

### 2. Verification Before Approval

The agent must prove they did the work by:
- Providing record IDs
- Having those IDs verified in the database
- Confirming verification was performed

### 3. Lifecycle Hooks

Use hooks to separate concerns:
- `beforeApply()`: Logging, locks, setup
- `apply()`: Core business logic
- `afterApply()`: Events, notifications, cleanup

### 4. Idempotency Pattern

The apply() method doesn't re-insert records. Instead, it:
1. Collects IDs from agent submissions
2. Verifies they exist
3. Marks them as processed (can be run multiple times safely)

### 5. Transaction Safety

While not shown explicitly here, you should wrap apply() in transactions:

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        // Your mutations here
        return $this->makeDiff($before, $after, 'Summary');
    });
}
```

## Variations and Extensions

### Variation 1: Batch by Size

Create multiple work items for large datasets:

```php
public function plan(WorkOrder $order): array
{
    $records = $order->payload['records'];
    $batchSize = 50;
    $batches = array_chunk($records, $batchSize);

    return array_map(fn($batch) => [
        'type' => $this->type(),
        'input' => [
            'table' => $order->payload['table'],
            'records' => $batch,
        ],
        'max_attempts' => 3,
    ], $batches);
}
```

### Variation 2: Add Duplicate Detection

Prevent duplicate records:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Check for duplicates
    $recordIds = $result['record_ids'];
    $table = $item->order->payload['table'];

    $duplicates = DB::table($table)
        ->whereIn('id', $recordIds)
        ->where('processed', true)
        ->count();

    if ($duplicates > 0) {
        throw ValidationException::withMessages([
            'record_ids' => ['Some records are already processed'],
        ]);
    }
}
```

### Variation 3: Add Rollback Support

Store backup data for potential rollbacks:

```php
protected function beforeApply(WorkOrder $order): void
{
    // Backup existing records
    $table = $order->payload['table'];
    $snapshot = DB::table($table)->get();

    // Store snapshot for potential rollback
    cache()->put("backup.{$order->id}", $snapshot, now()->addHours(24));
}
```

### Variation 4: Add Data Transformation

Transform data during apply:

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            foreach ($item->result['record_ids'] as $id) {
                $record = Product::find($id);

                // Transform data
                $record->price = $record->price * 1.1; // Add 10% markup
                $record->sku = strtoupper($record->sku); // Normalize SKU
                $record->save();
            }
        }

        return $this->makeDiff($before, $after, 'Transformed records');
    });
}
```

## Next Steps

1. **Try External API Integration**: See [user-data-sync.md](./user-data-sync.md)
2. **Add Partial Submissions**: See [customer-research-partial.md](./customer-research-partial.md)
3. **Build Custom Validation**: Create complex business rules
4. **Add Event Listeners**: React to database changes

## Troubleshooting

### Verification Failed

If verification fails, check:
- Are records actually in the database?
- Do the IDs match what the agent submitted?
- Is the database connection working?

### Approval Blocked

If approval is blocked:
- Check `canApprove()` logic
- Verify all items have `verification.valid = true`
- Check logs for validation errors

### Idempotency Issues

If apply() isn't idempotent:
- Use `updateOrCreate()` instead of `create()`
- Check for existing records before mutating
- Use transactions to ensure atomic operations
