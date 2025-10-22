# User Data Sync Example

This example demonstrates synchronizing user data from external sources with batching, incremental updates, and proper error handling.

## Overview

**What we're building**: A work order type that syncs user data from external systems (CRM, analytics platforms, billing systems) into your Laravel application.

**Use case**: Keeping local user records in sync with external sources, enriching user profiles with data from multiple systems, scheduled data imports.

**Difficulty**: Intermediate

**Key Features**:
- External API integration
- Custom work item planning (batching)
- Incremental updates
- Comprehensive verification
- Cache invalidation
- Error handling

## Complete Code

Create `app/WorkTypes/UserDataSyncType.php`:

```php
<?php

namespace App\WorkTypes;

use App\Models\User;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;

/**
 * User Data Sync Example
 *
 * A realistic example showing:
 * - External data synchronization
 * - Data verification
 * - Incremental updates
 * - Custom planning (batching)
 */
class UserDataSyncType extends AbstractOrderType
{
    public function type(): string
    {
        return 'user.data.sync';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source', 'user_ids'],
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'enum' => ['crm', 'analytics', 'billing'],
                ],
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Plan creates one work item per batch of users.
     */
    public function plan(WorkOrder $order): array
    {
        $userIds = $order->payload['user_ids'];
        $batchSize = 50;
        $batches = array_chunk($userIds, $batchSize);

        return array_map(function ($batch) use ($order) {
            return [
                'type' => $this->type(),
                'input' => [
                    'source' => $order->payload['source'],
                    'user_ids' => $batch,
                    'fields' => $order->payload['fields'] ?? null,
                ],
                'max_attempts' => 3,
            ];
        }, $batches);
    }

    /**
     * Validate agent submission.
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'synced_users' => 'required|array',
            'synced_users.*.user_id' => 'required|integer',
            'synced_users.*.data' => 'required|array',
            'synced_users.*.verified' => 'required|boolean',
            'errors' => 'nullable|array',
        ];
    }

    /**
     * Custom verification.
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify all users in the batch were processed
        $expectedIds = $item->input['user_ids'];
        $syncedIds = array_column($result['synced_users'], 'user_id');

        if (count(array_diff($expectedIds, $syncedIds)) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'synced_users' => ['Not all users in batch were synced'],
            ]);
        }

        // Verify all synced users were verified
        foreach ($result['synced_users'] as $syncedUser) {
            if (!$syncedUser['verified']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'synced_users' => ['All synced user data must be verified'],
                ]);
            }
        }
    }

    /**
     * Apply the sync - update user records in database.
     */
    public function apply(WorkOrder $order): Diff
    {
        $updatedCount = 0;
        $before = [];
        $after = [];

        DB::transaction(function () use ($order, &$updatedCount, &$before, &$after) {
            foreach ($order->items as $item) {
                foreach ($item->result['synced_users'] as $syncedUser) {
                    $user = User::find($syncedUser['user_id']);

                    if ($user) {
                        $before[$user->id] = $user->toArray();

                        // Update user with synced data
                        $user->update($syncedUser['data']);

                        $after[$user->id] = $user->fresh()->toArray();
                        $updatedCount++;
                    }
                }
            }
        });

        return $this->makeDiff(
            ['updated_count' => 0],
            ['updated_count' => $updatedCount],
            "Synced data for {$updatedCount} users from {$order->payload['source']}"
        );
    }

    /**
     * Post-apply actions.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        // Invalidate caches, trigger webhooks, etc.
        \Illuminate\Support\Facades\Cache::tags(['users'])->flush();
    }
}
```

## Step-by-Step Walkthrough

### 1. Custom Planning for Batching

The `plan()` method breaks large user lists into manageable batches:

```php
public function plan(WorkOrder $order): array
{
    $userIds = $order->payload['user_ids'];
    $batchSize = 50; // Adjust based on API rate limits

    $batches = array_chunk($userIds, $batchSize);

    return array_map(function ($batch) use ($order) {
        return [
            'type' => $this->type(),
            'input' => [
                'source' => $order->payload['source'],
                'user_ids' => $batch,
                'fields' => $order->payload['fields'] ?? null,
            ],
            'max_attempts' => 3,
        ];
    }, $batches);
}
```

Benefits:
- Parallel processing by multiple agents
- Smaller API requests (respect rate limits)
- Better error isolation (one batch failure doesn't affect others)
- Progress tracking per batch

### 2. Comprehensive Validation

The validation ensures all users in a batch are processed:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Check completeness
    $expectedIds = $item->input['user_ids'];
    $syncedIds = array_column($result['synced_users'], 'user_id');

    if (count(array_diff($expectedIds, $syncedIds)) > 0) {
        throw ValidationException::withMessages([
            'synced_users' => ['Not all users in batch were synced'],
        ]);
    }

    // Check verification
    foreach ($result['synced_users'] as $syncedUser) {
        if (!$syncedUser['verified']) {
            throw ValidationException::withMessages([
                'synced_users' => ['All synced user data must be verified'],
            ]);
        }
    }
}
```

### 3. Transaction-Safe Updates

The apply() method uses database transactions:

```php
public function apply(WorkOrder $order): Diff
{
    $updatedCount = 0;

    DB::transaction(function () use ($order, &$updatedCount) {
        foreach ($order->items as $item) {
            foreach ($item->result['synced_users'] as $syncedUser) {
                $user = User::find($syncedUser['user_id']);
                if ($user) {
                    $user->update($syncedUser['data']);
                    $updatedCount++;
                }
            }
        }
    });

    return $this->makeDiff(
        ['updated_count' => 0],
        ['updated_count' => $updatedCount],
        "Synced {$updatedCount} users"
    );
}
```

## Example API Interactions

### 1. Propose Sync for 150 Users

```bash
curl -X POST http://your-app.test/api/agent/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: sync-$(date +%s)" \
  -d '{
    "type": "user.data.sync",
    "payload": {
      "source": "crm",
      "user_ids": [1, 2, 3, ..., 150],
      "fields": ["email", "phone", "company"]
    }
  }'
```

**Response** (3 work items created - 3 batches of 50):

```json
{
  "order": {
    "id": "order-uuid",
    "type": "user.data.sync",
    "state": "queued"
  },
  "items": [
    {
      "id": "item-1-uuid",
      "input": {"source": "crm", "user_ids": [1...50]}
    },
    {
      "id": "item-2-uuid",
      "input": {"source": "crm", "user_ids": [51...100]}
    },
    {
      "id": "item-3-uuid",
      "input": {"source": "crm", "user_ids": [101...150]}
    }
  ]
}
```

### 2. Checkout First Batch

```bash
curl -X POST http://your-app.test/api/agent/work/orders/order-uuid/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: sync-agent-01"
```

**Response:**

```json
{
  "item": {
    "id": "item-1-uuid",
    "state": "leased",
    "input": {
      "source": "crm",
      "user_ids": [1, 2, 3, ..., 50],
      "fields": ["email", "phone", "company"]
    }
  }
}
```

### 3. Agent Processes Batch

The agent would:
1. Connect to the CRM API
2. Fetch data for each user
3. Verify data integrity
4. Submit results

Example agent code:

```php
$input = $item->input;
$syncedUsers = [];

foreach ($input['user_ids'] as $userId) {
    // Fetch from CRM API
    $crmData = Http::get("https://crm.example.com/api/users/{$userId}")->json();

    // Extract requested fields
    $data = [];
    foreach ($input['fields'] as $field) {
        $data[$field] = $crmData[$field] ?? null;
    }

    // Verify data is not empty
    $verified = !empty(array_filter($data));

    $syncedUsers[] = [
        'user_id' => $userId,
        'data' => $data,
        'verified' => $verified,
    ];
}

$result = [
    'success' => true,
    'synced_users' => $syncedUsers,
    'errors' => [],
];
```

### 4. Submit First Batch

```bash
curl -X POST http://your-app.test/api/agent/work/items/item-1-uuid/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-1-$(date +%s)" \
  -H "X-Agent-ID: sync-agent-01" \
  -d '{
    "result": {
      "success": true,
      "synced_users": [
        {
          "user_id": 1,
          "data": {
            "email": "user1@example.com",
            "phone": "+1234567890",
            "company": "Acme Corp"
          },
          "verified": true
        },
        {
          "user_id": 2,
          "data": {
            "email": "user2@example.com",
            "phone": "+1234567891",
            "company": "Widget Inc"
          },
          "verified": true
        }
        // ... remaining 48 users
      ],
      "errors": []
    }
  }'
```

### 5. Repeat for Other Batches

Other agents (or the same agent) would checkout and process the remaining batches (item-2-uuid, item-3-uuid) in parallel.

### 6. Approve After All Batches Complete

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
    "before": {"updated_count": 0},
    "after": {"updated_count": 150},
    "summary": "Synced data for 150 users from crm"
  }
}
```

## Expected Output

After completing the workflow:

1. 150 users are updated with data from CRM
2. Updates are applied in a single transaction
3. User cache is invalidated
4. A diff shows how many users were updated

## Key Learnings

### 1. Batching Strategy

Break large datasets into batches for:
- **Parallel processing**: Multiple agents work simultaneously
- **Rate limit compliance**: Smaller API requests
- **Error isolation**: One batch failure doesn't affect others
- **Progress visibility**: Track completion per batch

### 2. External API Integration

When integrating with external APIs:
- Handle rate limits (adjust batch size)
- Verify data before submitting
- Include error details in submissions
- Use retry logic (max_attempts)

### 3. Data Verification

Ensure data quality by:
- Verifying all batch users are processed
- Checking data is not empty
- Validating against expected schema
- Rejecting unverified data

### 4. Cache Management

After data changes:
- Invalidate affected caches
- Use cache tags for granular control
- Consider event-driven invalidation

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    Cache::tags(['users'])->flush();

    // Or more granular:
    foreach ($order->items as $item) {
        foreach ($item->result['synced_users'] as $syncedUser) {
            Cache::forget("user.{$syncedUser['user_id']}");
        }
    }
}
```

## Variations and Extensions

### Variation 1: Handle Partial Failures

Allow some users in a batch to fail:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'synced_users' => 'required|array',
        'failed_users' => 'nullable|array',
        'failed_users.*.user_id' => 'required|integer',
        'failed_users.*.error' => 'required|string',
    ];
}

protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $totalUsers = count($item->input['user_ids']);
    $syncedUsers = count($result['synced_users']);
    $failedUsers = count($result['failed_users'] ?? []);

    // Require at least 80% success rate
    if ($syncedUsers < ($totalUsers * 0.8)) {
        throw ValidationException::withMessages([
            'synced_users' => ['Too many failures (minimum 80% success required)'],
        ]);
    }
}
```

### Variation 2: Add Field Mapping

Transform external field names to local field names:

```php
public function apply(WorkOrder $order): Diff
{
    $fieldMap = [
        'full_name' => 'name',
        'email_address' => 'email',
        'mobile' => 'phone',
    ];

    DB::transaction(function () use ($order, $fieldMap) {
        foreach ($order->items as $item) {
            foreach ($item->result['synced_users'] as $syncedUser) {
                $mappedData = [];
                foreach ($syncedUser['data'] as $key => $value) {
                    $localKey = $fieldMap[$key] ?? $key;
                    $mappedData[$localKey] = $value;
                }

                User::find($syncedUser['user_id'])->update($mappedData);
            }
        }
    });

    return $this->makeDiff($before, $after, 'Summary');
}
```

### Variation 3: Add Conflict Resolution

Handle conflicts when local data differs from external data:

```php
public function apply(WorkOrder $order): Diff
{
    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            foreach ($item->result['synced_users'] as $syncedUser) {
                $user = User::find($syncedUser['user_id']);

                foreach ($syncedUser['data'] as $field => $value) {
                    // Only update if external data is newer
                    if ($user->$field !== $value) {
                        $user->$field = $value;
                        $user->{"${field}_updated_from"} = 'crm';
                        $user->{"${field}_updated_at"} = now();
                    }
                }

                $user->save();
            }
        }
    });

    return $this->makeDiff($before, $after, 'Summary');
}
```

### Variation 4: Add Webhook Notifications

Notify external systems after sync:

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Notify CRM that sync completed
    Http::post('https://crm.example.com/webhooks/sync-complete', [
        'order_id' => $order->id,
        'users_synced' => $diff->after['updated_count'],
        'timestamp' => now()->toIso8601String(),
    ]);

    // Send internal notifications
    event(new UserDataSynced($order, $diff));
}
```

### Variation 5: Add Delta Sync

Only sync changed data:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['source', 'user_ids'],
        'properties' => [
            'source' => ['type' => 'string'],
            'user_ids' => ['type' => 'array'],
            'sync_mode' => [
                'type' => 'string',
                'enum' => ['full', 'delta'],
                'default' => 'delta',
            ],
            'since' => [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Only sync changes since this timestamp',
            ],
        ],
    ];
}
```

## Next Steps

1. **Add Partial Submissions**: See [customer-research-partial.md](./customer-research-partial.md)
2. **Add Fact-Checking**: See [content-fact-check.md](./content-fact-check.md)
3. **Build Production API Client**: Create robust external API integration
4. **Add Monitoring**: Track sync success rates and performance

## Troubleshooting

### Batch Size Too Large

If you hit API rate limits:
- Reduce batch size in `plan()`
- Add delays between requests in agent code
- Increase max_attempts for retries

### Partial Batch Failures

If some users fail validation:
- Check agent logs for API errors
- Verify external system availability
- Consider allowing partial success (see Variation 1)

### Performance Issues

If apply() is slow:
- Use eager loading: `User::whereIn('id', $ids)->get()`
- Batch updates with `DB::table()->update()`
- Move slow operations to queued jobs in `afterApply()`

### Cache Invalidation Not Working

If stale data persists:
- Verify cache tags are configured correctly
- Check cache driver supports tagging (Redis, Memcached)
- Use more specific cache keys
