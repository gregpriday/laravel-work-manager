# Examples Overview

This directory contains comprehensive, production-ready examples demonstrating how to build work order types with Laravel Work Manager. Each example showcases different patterns, use cases, and features.

## Available Examples

### 1. Basic Usage
**File**: [basic-usage.md](./basic-usage.md)
**Difficulty**: Beginner
**Key Topics**: Minimal viable order type, basic validation, simple apply logic

Start here to understand the fundamentals of creating a work order type.

### 2. Database Record Insert
**File**: [database-record-insert.md](./database-record-insert.md)
**Difficulty**: Beginner
**Key Topics**: Batch database operations, lifecycle hooks, verification logic, transactions

Learn how to safely insert and verify database records with comprehensive validation.

### 3. User Data Sync
**File**: [user-data-sync.md](./user-data-sync.md)
**Difficulty**: Intermediate
**Key Topics**: External API integration, batching, incremental updates, custom planning

See how to synchronize data from external sources with proper error handling.

### 4. Customer Research (Partial Submissions)
**File**: [customer-research-partial.md](./customer-research-partial.md)
**Difficulty**: Advanced
**Key Topics**: Partial submissions, incremental results, complex validation, multi-part workflows

Explore the partial submissions feature for long-running research tasks with incremental progress.

### 5. Content Fact-Check
**File**: [content-fact-check.md](./content-fact-check.md)
**Difficulty**: Intermediate
**Key Topics**: Content verification, evidence tracking, quality control, approval gates

Build a fact-checking workflow with evidence-based validation and confidence scoring.

### 6. City Tier Generation
**File**: [city-tier-generation.md](./city-tier-generation.md)
**Difficulty**: Intermediate
**Key Topics**: Data classification, multi-dimensional scoring, aggregation, reporting

Generate comprehensive ratings across multiple dimensions with citation requirements.

## Prerequisites

### 1. Environment Setup

Ensure you have Laravel Work Manager installed and configured:

```bash
composer require gregpriday/laravel-work-manager
php artisan work-manager:install
php artisan migrate
```

### 2. Authentication Setup

All API endpoints require authentication. Set up Laravel Sanctum or your preferred auth guard:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### 3. API Routes

Mount the work manager routes in your `routes/api.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'ai/work',
    middleware: ['api', 'auth:sanctum']
);
```

### 4. Test User and Token

Create a test user and API token:

```php
$user = User::factory()->create();
$token = $user->createToken('test-agent')->plainTextToken;

// Use this token in API requests:
// Authorization: Bearer {$token}
```

## Running the Examples Locally

### Step 1: Copy the Example Code

Each example includes a complete OrderType class. Copy it to your Laravel application:

```bash
# Create the WorkTypes directory
mkdir -p app/WorkTypes

# Copy example file
cp examples/UserDataSyncType.php app/WorkTypes/
```

### Step 2: Register the Order Type

Add to `app/Providers/AppServiceProvider.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\UserDataSyncType;

public function boot()
{
    WorkManager::registry()->register(new UserDataSyncType());
}
```

### Step 3: Create Required Models

Some examples require specific models. Create them or adapt the code to your existing models:

```bash
php artisan make:model Product -m
php artisan make:model Customer -m
php artisan make:model CustomerEnrichment -m
```

### Step 4: Test with API Requests

Use the API examples in each guide to test the workflow:

```bash
# Save your token
export TOKEN="your-sanctum-token-here"

# Propose work
curl -X POST http://your-app.test/api/ai/work/propose \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: test-$(date +%s)" \
  -d @payload.json
```

### Step 5: Monitor with Events

Listen to work order events for debugging:

```php
use GregPriday\WorkManager\Events\WorkOrderProposed;
use GregPriday\WorkManager\Events\WorkItemSubmitted;
use GregPriday\WorkManager\Events\WorkOrderApplied;

Event::listen([
    WorkOrderProposed::class,
    WorkItemSubmitted::class,
    WorkOrderApplied::class,
], function ($event) {
    Log::info('Work Manager Event', [
        'event' => class_basename($event),
        'order_id' => $event->order->id ?? $event->item->order->id ?? null,
    ]);
});
```

## Common Patterns

### Pattern 1: Basic CRUD Operations

Use this pattern when agents need to create, update, or delete records:

```php
public function apply(WorkOrder $order): Diff
{
    $before = ['count' => Model::count()];

    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            Model::updateOrCreate(
                ['id' => $item->result['id']],
                $item->result['data']
            );
        }
    });

    $after = ['count' => Model::count()];

    return $this->makeDiff($before, $after, 'Summary');
}
```

### Pattern 2: External API Integration

Use this pattern when agents need to fetch or push data to external services:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify external data
    $response = Http::timeout(10)->get($item->input['api_url']);

    if (!$response->successful()) {
        throw ValidationException::withMessages([
            'api' => ['External API verification failed'],
        ]);
    }

    // Compare submitted data with API response
    $apiData = $response->json();
    if ($apiData['id'] !== $result['id']) {
        throw ValidationException::withMessages([
            'id' => ['ID mismatch with external source'],
        ]);
    }
}
```

### Pattern 3: Batching for Performance

Use this pattern when processing large datasets:

```php
public function plan(WorkOrder $order): array
{
    $ids = $order->payload['record_ids'];
    $batchSize = 100; // Adjust based on your needs
    $batches = array_chunk($ids, $batchSize);

    return array_map(fn($batch) => [
        'type' => $this->type(),
        'input' => [
            'batch_ids' => $batch,
            'batch_size' => count($batch),
        ],
        'max_attempts' => 3,
    ], $batches);
}
```

### Pattern 4: Conditional Validation

Use this pattern when validation rules depend on the data:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    $rules = [
        'status' => 'required|in:success,partial,failed',
        'data' => 'required|array',
    ];

    // Add conditional rules based on status
    if ($item->result['status'] ?? null === 'success') {
        $rules['verification'] = 'required|boolean|accepted';
        $rules['verification_url'] = 'required|url';
    }

    return $rules;
}
```

### Pattern 5: Evidence-Based Validation

Use this pattern when agents must provide proof of their work:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'result' => 'required|array',
        'evidence' => 'required|array|min:2',
        'evidence.*.url' => 'required|url',
        'evidence.*.retrieved_at' => 'required|date|before_or_equal:now',
        'evidence.*.confidence' => 'required|numeric|min:0|max:1',
    ];
}

protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Require high average confidence
    $avgConfidence = collect($result['evidence'])
        ->avg('confidence');

    if ($avgConfidence < 0.7) {
        throw ValidationException::withMessages([
            'evidence' => ['Average confidence must be >= 0.7'],
        ]);
    }
}
```

### Pattern 6: Lifecycle Hooks for Side Effects

Use this pattern to keep apply() focused and handle side effects separately:

```php
protected function beforeApply(WorkOrder $order): void
{
    // Pre-execution setup
    Cache::lock('order-'.$order->id, 60)->get();
    Log::info('Starting apply', ['order_id' => $order->id]);
}

public function apply(WorkOrder $order): Diff
{
    // Focus only on the core domain logic
    return DB::transaction(function () use ($order) {
        // ... mutations here ...
        return $this->makeDiff($before, $after, 'Summary');
    });
}

protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Post-execution cleanup and side effects
    Cache::tags(['users'])->flush();

    // Queue follow-up work
    ProcessResults::dispatch($order)->onQueue('work');

    // Send notifications
    event(new WorkCompleted($order, $diff));
}
```

### Pattern 7: Idempotent Apply

ALWAYS make apply() idempotent. Use this pattern:

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        $before = $this->captureState($order);

        // Check if already applied
        if ($this->alreadyApplied($order)) {
            Log::info('Order already applied', ['order_id' => $order->id]);
            return $this->makeDiff($before, $before, 'Already applied');
        }

        // Perform mutations
        foreach ($order->items as $item) {
            Model::updateOrCreate(
                ['unique_key' => $item->result['unique_key']],
                $item->result['data']
            );
        }

        $after = $this->captureState($order);

        return $this->makeDiff($before, $after, 'Applied changes');
    });
}

protected function alreadyApplied(WorkOrder $order): bool
{
    // Check for idempotency
    return Model::where('order_id', $order->id)->exists();
}
```

## Testing Your Order Types

### Unit Testing

```php
use Tests\TestCase;
use App\WorkTypes\UserDataSyncType;
use GregPriday\WorkManager\Models\WorkOrder;

class UserDataSyncTypeTest extends TestCase
{
    public function test_schema_validation()
    {
        $type = new UserDataSyncType();

        $validator = Validator::make([
            'source' => 'crm',
            'user_ids' => [1, 2, 3],
        ], $type->schemaRules());

        $this->assertTrue($validator->passes());
    }

    public function test_submission_validation()
    {
        $type = new UserDataSyncType();
        $item = WorkItem::factory()->create([
            'type' => 'user.data.sync',
            'input' => ['source' => 'crm', 'user_ids' => [1, 2]],
        ]);

        $rules = $type->submissionValidationRules($item);

        $validator = Validator::make([
            'success' => true,
            'synced_users' => [
                ['user_id' => 1, 'data' => ['email' => 'test@example.com'], 'verified' => true],
                ['user_id' => 2, 'data' => ['email' => 'test2@example.com'], 'verified' => true],
            ],
        ], $rules);

        $this->assertTrue($validator->passes());
    }
}
```

### Integration Testing

```php
public function test_complete_workflow()
{
    // 1. Propose
    $response = $this->postJson('/api/ai/work/propose', [
        'type' => 'user.data.sync',
        'payload' => [
            'source' => 'crm',
            'user_ids' => [1, 2],
        ],
    ]);

    $orderId = $response->json('order.id');

    // 2. Checkout
    $response = $this->postJson("/api/ai/work/orders/{$orderId}/checkout");
    $itemId = $response->json('item.id');

    // 3. Submit
    $response = $this->postJson("/api/ai/work/items/{$itemId}/submit", [
        'result' => [
            'success' => true,
            'synced_users' => [
                ['user_id' => 1, 'data' => ['email' => 'test@example.com'], 'verified' => true],
                ['user_id' => 2, 'data' => ['email' => 'test2@example.com'], 'verified' => true],
            ],
        ],
    ]);

    $response->assertStatus(200);

    // 4. Approve
    $response = $this->postJson("/api/ai/work/orders/{$orderId}/approve");
    $response->assertStatus(200);

    // Verify apply was successful
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
}
```

## Configuration Tips

### 1. Lease Settings

Adjust lease TTL and heartbeat based on expected work duration:

```php
// config/work-manager.php

'lease' => [
    'ttl_seconds' => 600,        // 10 minutes for long-running work
    'heartbeat_seconds' => 120,  // 2 minutes between heartbeats
    'backend' => 'redis',        // Use Redis for high concurrency
],
```

### 2. Retry Configuration

Configure retry behavior per work item:

```php
public function plan(WorkOrder $order): array
{
    return [[
        'type' => $this->type(),
        'input' => [...],
        'max_attempts' => 5,           // Retry up to 5 times
        'retry_delay_seconds' => 60,   // Wait 1 minute between retries
    ]];
}
```

### 3. Partial Submissions

Enable and configure partial submissions:

```php
// config/work-manager.php

'partials' => [
    'enabled' => true,
    'max_parts_per_item' => 50,
    'max_payload_bytes' => 1048576, // 1MB per part
],
```

## Debugging Tips

### 1. Enable Debug Logging

```php
// config/work-manager.php

'debug' => env('WORK_MANAGER_DEBUG', true),
```

### 2. View Work Order Events

```bash
php artisan work-manager:logs {order-id}
```

### 3. Check Item State

```php
$item = WorkItem::find($itemId);
dd([
    'state' => $item->state,
    'result' => $item->result,
    'validation_errors' => $item->validation_errors,
    'attempts' => $item->attempts,
]);
```

### 4. Monitor Lease Status

```bash
# Check if lease is expired
php artisan tinker
>>> WorkItem::find($itemId)->lease_expires_at->isPast()
```

## Next Steps

1. **Read the Basic Usage Guide**: Start with [basic-usage.md](./basic-usage.md) to understand the fundamentals
2. **Pick a Similar Example**: Choose an example that matches your use case
3. **Adapt to Your Needs**: Modify the example code for your domain
4. **Test Thoroughly**: Use the testing patterns above
5. **Deploy**: Follow the deployment guide in the main documentation

## Additional Resources

- [Architecture Documentation](../ARCHITECTURE.md) - System design and data flows
- [MCP Server Guide](../MCP_SERVER.md) - AI agent integration
- [Lifecycle Guide](../../examples/LIFECYCLE.md) - Complete lifecycle hooks documentation
- [API Reference](../reference/) - Complete API documentation
