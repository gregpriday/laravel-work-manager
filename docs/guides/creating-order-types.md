# Creating Order Types Guide

**By the end of this guide, you'll be able to:** Build custom order types, extend AbstractOrderType, implement all required and optional methods, and register types with the system.

---

## Overview

An **Order Type** defines the complete lifecycle of a category of work:

- What data is required (schema)
- How to break work into items (planning)
- How to validate agent submissions (acceptance)
- How to execute the work (apply)

---

## Quick Start: Basic Order Type

### Step 1: Create the Class

```php
// app/WorkTypes/UserDataSyncType.php
namespace App\WorkTypes;

use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;

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
            'required' => ['user_ids'],
            'properties' => [
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    public function apply(WorkOrder $order): Diff
    {
        $synced = 0;

        DB::transaction(function () use ($order, &$synced) {
            foreach ($order->items as $item) {
                // Perform the actual sync
                foreach ($item->result['user_ids'] as $userId) {
                    // Update user data...
                    $synced++;
                }
            }
        });

        return $this->makeDiff(
            ['synced' => 0],
            ['synced' => $synced],
            "Synced {$synced} users"
        );
    }
}
```

### Step 2: Register the Type

```php
// app/Providers/AppServiceProvider.php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\UserDataSyncType;

public function boot()
{
    WorkManager::registry()->register(new UserDataSyncType());
}
```

---

## Required Methods

### 1. type(): string

Returns the unique identifier for this order type.

```php
public function type(): string
{
    return 'user.data.sync';  // Must be unique across all types
}
```

**Naming convention**: Use dot notation: `domain.entity.action`

**Examples**:
- `user.data.sync`
- `database.records.insert`
- `research.customer.enrich`
- `report.sales.generate`

### 2. schema(): array

Returns a JSON Schema defining the required payload structure.

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['source', 'user_ids'],
        'properties' => [
            'source' => [
                'type' => 'string',
                'enum' => ['crm', 'analytics'],
            ],
            'user_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'minItems' => 1,
                'maxItems' => 100,
            ],
            'batch_size' => [
                'type' => 'integer',
                'minimum' => 10,
                'maximum' => 100,
                'default' => 50,
            ],
        ],
    ];
}
```

**Schema validation happens at proposal time.**

### 3. apply(WorkOrder $order): Diff

Executes the work order. **MUST be idempotent** - can be called multiple times safely.

```php
public function apply(WorkOrder $order): Diff
{
    $before = ['records' => Record::count()];

    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            // Use updateOrCreate for idempotency
            Record::updateOrCreate(
                ['id' => $item->result['id']],
                $item->result['data']
            );
        }
    });

    $after = ['records' => Record::count()];

    return $this->makeDiff($before, $after, 'Inserted records');
}
```

**Key principles**:
- Wrap in database transaction
- Use `updateOrCreate` / `firstOrCreate` for idempotency
- Check if work already done
- Return meaningful diff

---

## Optional Methods (Validation)

### submissionValidationRules(WorkItem $item): array

Define Laravel validation rules for agent submissions:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'synced_users' => 'required|array',
        'synced_users.*.user_id' => 'required|integer|exists:users,id',
        'synced_users.*.data' => 'required|array',
        'synced_users.*.data.email' => 'required|email',
        'synced_users.*.verified' => 'required|boolean|accepted',
    ];
}
```

### afterValidateSubmission(WorkItem $item, array $result): void

Custom business logic validation after Laravel rules pass:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify all expected users were processed
    $expectedIds = $item->input['user_ids'];
    $syncedIds = array_column($result['synced_users'], 'user_id');

    $missing = array_diff($expectedIds, $syncedIds);

    if (!empty($missing)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'synced_users' => ['Missing users: ' . implode(', ', $missing)],
        ]);
    }

    // Verify data quality
    foreach ($result['synced_users'] as $user) {
        if (!$this->externalApi->verify($user['data'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'synced_users' => ["User {$user['user_id']} failed verification"],
            ]);
        }
    }
}
```

### canApprove(WorkOrder $order): bool

Check if order is ready for approval (after all items submitted):

```php
protected function canApprove(WorkOrder $order): bool
{
    // Ensure all submissions have been verified
    foreach ($order->items as $item) {
        if (!($item->result['verified'] ?? false)) {
            return false;
        }
    }

    // Check total count makes sense
    $totalUsers = $order->items->sum(function ($item) {
        return count($item->result['synced_users'] ?? []);
    });

    $expectedUsers = count($order->payload['user_ids']);

    return $totalUsers === $expectedUsers;
}
```

---

## Optional Methods (Lifecycle Hooks)

### plan(WorkOrder $order): array

Break an order into work items. Default creates a single item.

```php
public function plan(WorkOrder $order): array
{
    $batchSize = $order->payload['batch_size'] ?? 50;
    $userIds = $order->payload['user_ids'];

    // Split into batches
    $batches = array_chunk($userIds, $batchSize);

    return array_map(function ($batch) {
        return [
            'type' => $this->type(),
            'input' => [
                'user_ids' => $batch,
                'source' => $order->payload['source'],
            ],
            'max_attempts' => 3,
        ];
    }, $batches);
}
```

### beforeApply(WorkOrder $order): void

Setup before execution:

```php
protected function beforeApply(WorkOrder $order): void
{
    // Acquire locks
    Cache::lock('sync-users')->get();

    // Backup data
    $this->backupService->snapshot('users');

    // Log execution start
    Log::info('Starting user sync', ['order_id' => $order->id]);
}
```

### afterApply(WorkOrder $order, Diff $diff): void

Cleanup and side effects:

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Release locks
    Cache::lock('sync-users')->release();

    // Clear caches
    Cache::tags(['users'])->flush();

    // Queue follow-up jobs
    ProcessUserAnalytics::dispatch($order)->onQueue('analytics');

    // Send notifications
    event(new UsersSynced($order, $diff));

    // Log completion
    Log::info('User sync completed', [
        'order_id' => $order->id,
        'synced' => $diff->toArray()['after']['synced'],
    ]);
}
```

---

## Advanced: Partial Submissions

For long-running or complex work, agents can submit results incrementally.

### Required Methods

```php
public function requiredParts(WorkItem $item): array
{
    return ['identity', 'firmographics', 'contacts'];
}

public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
{
    return match ($partKey) {
        'identity' => [
            'name' => 'required|string',
            'domain' => 'required|url',
        ],
        'firmographics' => [
            'employees' => 'required|integer|min:1',
            'revenue' => 'nullable|numeric',
        ],
        'contacts' => [
            'contacts' => 'required|array',
            'contacts.*.email' => 'required|email',
        ],
        default => [],
    };
}

public function assemble(WorkItem $item, Collection $latestParts): array
{
    $result = [];

    foreach ($latestParts as $part) {
        $result[$part->part_key] = $part->payload;
    }

    return $result;
}

protected function validateAssembled(WorkItem $item, array $assembled): void
{
    // Validate the complete dataset
    if (empty($assembled['identity']) || empty($assembled['contacts'])) {
        throw ValidationException::withMessages([
            'assembled' => ['Must include identity and contacts'],
        ]);
    }
}
```

See [Partial Submissions Guide](partial-submissions.md) for complete details.

---

## Auto-Approval

For safe, deterministic operations, enable auto-approval:

```php
class DatabaseRecordInsertType extends AbstractOrderType
{
    protected bool $autoApprove = true;  // Enable auto-approval

    // When all items are submitted and canApprove() returns true,
    // the system automatically calls approve() and apply()
}
```

**Use when**:
- Operations are safe and reversible
- No human review needed
- Validation is comprehensive

**Don't use when**:
- Operations are destructive
- Compliance requires human approval
- External system changes need review

---

## Custom Acceptance Policy

For complex validation, separate into its own class:

```php
// app/Policies/UserSyncAcceptancePolicy.php
namespace App\Policies;

use GregPriday\WorkManager\Support\AbstractAcceptancePolicy;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;

class UserSyncAcceptancePolicy extends AbstractAcceptancePolicy
{
    public function validateSubmission(WorkItem $item, array $result): void
    {
        $validator = validator($result, [
            'success' => 'required|boolean',
            'synced_users' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        // Custom verification
        $this->verifyExternalApi($result);
    }

    public function readyForApproval(WorkOrder $order): bool
    {
        // All items submitted
        $submitted = $order->items()
            ->whereIn('state', ['submitted', 'accepted'])
            ->count();

        if ($submitted !== $order->items()->count()) {
            return false;
        }

        // Additional checks
        return $this->allUsersVerified($order);
    }

    protected function verifyExternalApi(array $result): void
    {
        // External verification logic
    }

    protected function allUsersVerified(WorkOrder $order): bool
    {
        // Cross-item validation
        return true;
    }
}
```

Then use in order type:

```php
class UserDataSyncType extends AbstractOrderType
{
    public function acceptancePolicy(): AcceptancePolicy
    {
        return new UserSyncAcceptancePolicy();
    }
}
```

---

## Testing Order Types

```php
// tests/Feature/UserDataSyncTypeTest.php
use Tests\TestCase;
use App\WorkTypes\UserDataSyncType;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserDataSyncTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_identifier()
    {
        $type = new UserDataSyncType();

        $this->assertEquals('user.data.sync', $type->type());
    }

    public function test_validates_payload_schema()
    {
        $type = new UserDataSyncType();
        $schema = $type->schema();

        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('user_ids', $schema['required']);
    }

    public function test_apply_is_idempotent()
    {
        $order = WorkOrder::factory()->create([
            'type' => 'user.data.sync',
            'payload' => ['user_ids' => [1, 2, 3]],
        ]);

        $type = new UserDataSyncType();

        // First apply
        $diff1 = $type->apply($order->fresh());

        // Second apply (should be safe)
        $diff2 = $type->apply($order->fresh());

        $this->assertEquals($diff1->toArray(), $diff2->toArray());
    }
}
```

---

## Common Patterns

### Batch Processing

```php
public function plan(WorkOrder $order): array
{
    $records = $order->payload['records'];
    $batchSize = 100;

    return collect($records)
        ->chunk($batchSize)
        ->map(fn($batch) => [
            'type' => $this->type(),
            'input' => ['records' => $batch->toArray()],
        ])
        ->toArray();
}
```

### External API Sync

```php
public function apply(WorkOrder $order): Diff
{
    $client = new GuzzleHttp\Client([
        'base_uri' => config('services.external.url'),
    ]);

    $synced = [];

    DB::transaction(function () use ($order, $client, &$synced) {
        foreach ($order->items as $item) {
            $response = $client->post('/sync', [
                'json' => $item->result['data'],
            ]);

            $synced[] = $response->json();
        }
    });

    return $this->makeDiff([], $synced, 'Synced to external API');
}
```

### Database Migrations

```php
public function apply(WorkOrder $order): Diff
{
    $migrated = 0;

    DB::transaction(function () use ($order, &$migrated) {
        foreach ($order->items as $item) {
            // Migrate from old to new schema
            $old = OldModel::find($item->result['id']);
            if ($old) {
                NewModel::create([
                    'id' => $old->id,
                    'new_field' => $old->old_field,
                ]);
                $migrated++;
            }
        }
    });

    return $this->makeDiff(
        ['migrated' => 0],
        ['migrated' => $migrated],
        "Migrated {$migrated} records"
    );
}
```

---

## Troubleshooting

### OrderTypeNotFoundException

**Problem**: Type not found when proposing order

**Solution**: Ensure type is registered in `AppServiceProvider::boot()`

### Schema Validation Fails

**Problem**: Valid payload rejected

**Solution**: Test schema with JSON Schema validator, check required fields

### apply() Called Multiple Times

**Problem**: apply() executed twice, duplicating data

**Solution**: Make apply() idempotent using `updateOrCreate` or existence checks

---

## See Also

- [Validation and Acceptance Policies Guide](validation-and-acceptance-policies.md) - Deep dive on validation
- [Partial Submissions Guide](partial-submissions.md) - Incremental work submission
- [Testing Guide](testing.md) - Testing order types
- [examples/UserDataSyncType.php](../../examples/UserDataSyncType.php) - Complete example
- [examples/LIFECYCLE.md](../../examples/LIFECYCLE.md) - All lifecycle hooks
