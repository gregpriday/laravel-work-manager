# Validation and Acceptance Policies Guide

**By the end of this guide, you'll be able to:** Integrate Laravel validation into work orders, create custom verification logic, implement acceptance policies, and understand two-phase validation.

---

## Overview

Work Manager uses **two-phase validation**:

1. **Submission Validation** - When agents submit work item results
2. **Approval Readiness** - Before applying changes to your system

This ensures both individual work quality and overall order consistency.

---

## Phase 1: Submission Validation

### Laravel Validation Rules

The primary validation mechanism uses Laravel's built-in validator:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'success' => 'required|boolean',
        'user_id' => 'required|integer|exists:users,id',
        'email' => 'required|email|unique:users,email',
        'data' => 'required|array',
        'data.name' => 'required|string|min:2|max:255',
        'data.verified' => 'required|boolean|accepted',
    ];
}
```

**Validation happens automatically when agent calls** `POST /items/{item}/submit`

### Available Validation Rules

All standard Laravel rules are available:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        // Basic types
        'field' => 'required|string',
        'count' => 'integer|min:1|max:1000',
        'price' => 'numeric|between:0,9999.99',
        'enabled' => 'boolean',

        // Formats
        'email' => 'email',
        'url' => 'url',
        'date' => 'date|after:today',
        'uuid' => 'uuid',

        // Database validation
        'user_id' => 'exists:users,id',
        'username' => 'unique:users,username',

        // Arrays
        'tags' => 'array',
        'tags.*' => 'string|max:50',
        'items' => 'array|min:1|max:100',
        'items.*.id' => 'required|integer',

        // Conditional
        'country' => 'required_if:international,true',
        'state' => 'required_unless:country,US',

        // Complex
        'phone' => 'regex:/^[0-9]{10}$/',
        'confidence' => 'numeric|min:0|max:1',
    ];
}
```

### Custom Validation Messages

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'email' => [
            'required',
            'email',
            'unique:users,email',
        ],
    ];
}

protected function validationMessages(): array
{
    return [
        'email.required' => 'Email address is required for user sync',
        'email.unique' => 'This email is already registered in our system',
        'data.verified.accepted' => 'Data must be verified before submission',
    ];
}
```

### Context-Aware Validation

Use work item input for dynamic rules:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    $mode = $item->input['mode'] ?? 'standard';

    $rules = [
        'success' => 'required|boolean',
        'data' => 'required|array',
    ];

    // Add mode-specific rules
    if ($mode === 'strict') {
        $rules['data.verified'] = 'required|boolean|accepted';
        $rules['data.confidence'] = 'required|numeric|min:0.9';
    }

    return $rules;
}
```

---

## Phase 1.5: Custom Business Logic

After Laravel validation passes, add custom verification:

### afterValidateSubmission Hook

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // 1. Verify completeness
    $expectedIds = $item->input['user_ids'];
    $syncedIds = array_column($result['synced_users'], 'user_id');

    if (count($expectedIds) !== count($syncedIds)) {
        throw ValidationException::withMessages([
            'synced_users' => ['Not all users were processed'],
        ]);
    }

    // 2. External verification
    if (!$this->externalApi->verify($result['data'])) {
        throw ValidationException::withMessages([
            'data' => ['External verification failed'],
        ]);
    }

    // 3. Cross-field validation
    if ($result['total_amount'] !== array_sum($result['item_amounts'])) {
        throw ValidationException::withMessages([
            'total_amount' => ['Total does not match sum of items'],
        ]);
    }

    // 4. Business rules
    if ($result['confidence'] < 0.7 && !$result['manually_verified']) {
        throw ValidationException::withMessages([
            'confidence' => ['Low confidence requires manual verification'],
        ]);
    }
}
```

### Real-World Example: External API Verification

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $client = new GuzzleHttp\Client([
        'base_uri' => config('services.verification.url'),
    ]);

    try {
        $response = $client->post('/verify', [
            'json' => $result['data'],
            'timeout' => 10,
        ]);

        $verified = $response->json();

        if (!$verified['success']) {
            throw ValidationException::withMessages([
                'data' => $verified['errors'] ?? ['Verification failed'],
            ]);
        }
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        throw ValidationException::withMessages([
            'data' => ['Unable to verify data: ' . $e->getMessage()],
        ]);
    }
}
```

---

## Phase 2: Approval Readiness

Before applying an order, check if it's ready:

### canApprove Hook

```php
protected function canApprove(WorkOrder $order): bool
{
    // 1. Verify all items have required fields
    foreach ($order->items as $item) {
        if (empty($item->result['verified'])) {
            return false;
        }
    }

    // 2. Cross-item validation
    $totalRecords = $order->items->sum(function ($item) {
        return count($item->result['records'] ?? []);
    });

    $expectedRecords = count($order->payload['record_ids']);

    if ($totalRecords !== $expectedRecords) {
        return false;
    }

    // 3. Business constraints
    $totalAmount = $order->items->sum(fn($item) => $item->result['amount'] ?? 0);

    if ($totalAmount > 100000) {
        // Requires additional approval for large amounts
        return false;
    }

    return true;
}
```

### Example: Aggregate Validation

```php
protected function canApprove(WorkOrder $order): bool
{
    // Collect all synced user IDs across all items
    $syncedUserIds = [];

    foreach ($order->items as $item) {
        $syncedUserIds = array_merge(
            $syncedUserIds,
            array_column($item->result['synced_users'] ?? [], 'user_id')
        );
    }

    // Verify no duplicates
    if (count($syncedUserIds) !== count(array_unique($syncedUserIds))) {
        Log::warning('Duplicate user IDs across work items', [
            'order_id' => $order->id,
        ]);
        return false;
    }

    // Verify matches expected
    $expectedUserIds = $order->payload['user_ids'];

    return count($syncedUserIds) === count($expectedUserIds);
}
```

---

## Acceptance Policies (Advanced)

For complex validation logic, create a separate acceptance policy class:

### Creating a Custom Policy

```php
// app/Policies/UserSyncAcceptancePolicy.php
namespace App\Policies;

use GregPriday\WorkManager\Support\AbstractAcceptancePolicy;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

class UserSyncAcceptancePolicy extends AbstractAcceptancePolicy
{
    public function validateSubmission(WorkItem $item, array $result): void
    {
        // Laravel validation
        $validator = validator($result, [
            'success' => 'required|boolean',
            'synced_users' => 'required|array|min:1',
            'synced_users.*.user_id' => 'required|integer',
            'synced_users.*.email' => 'required|email',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Custom business logic
        $this->verifyUserData($item, $result);
        $this->checkExternalSystem($result);
    }

    public function readyForApproval(WorkOrder $order): bool
    {
        // Check all items submitted
        $allSubmitted = $order->items()
            ->whereIn('state', ['submitted', 'accepted'])
            ->count() === $order->items()->count();

        if (!$allSubmitted) {
            return false;
        }

        // Custom approval logic
        return $this->validateAggregateData($order);
    }

    protected function verifyUserData(WorkItem $item, array $result): void
    {
        foreach ($result['synced_users'] as $user) {
            if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'synced_users' => ["Invalid email for user {$user['user_id']}"],
                ]);
            }
        }
    }

    protected function checkExternalSystem(array $result): void
    {
        // External verification logic
    }

    protected function validateAggregateData(WorkOrder $order): bool
    {
        // Cross-item validation
        return true;
    }
}
```

### Using the Custom Policy

```php
// app/WorkTypes/UserDataSyncType.php
class UserDataSyncType extends AbstractOrderType
{
    public function acceptancePolicy(): AcceptancePolicy
    {
        return new UserSyncAcceptancePolicy();
    }
}
```

---

## Validation Error Responses

When validation fails, agents receive structured error responses:

### Laravel Validation Errors

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ],
    "data.verified": [
      "The data.verified field must be accepted."
    ]
  }
}
```

### Custom Validation Errors

```php
throw ValidationException::withMessages([
    'data' => ['External verification failed: Invalid format'],
    'confidence' => ['Confidence score must be at least 0.7'],
]);
```

Response:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "data": [
      "External verification failed: Invalid format"
    ],
    "confidence": [
      "Confidence score must be at least 0.7"
    ]
  }
}
```

---

## Common Validation Patterns

### Pattern 1: Batch Completeness

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $expected = $item->input['record_ids'];
    $processed = array_column($result['records'], 'id');

    $missing = array_diff($expected, $processed);

    if (!empty($missing)) {
        throw ValidationException::withMessages([
            'records' => ['Missing records: ' . implode(', ', $missing)],
        ]);
    }
}
```

### Pattern 2: Threshold Validation

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $successRate = $result['successful'] / $result['total'];

    if ($successRate < 0.95) {
        throw ValidationException::withMessages([
            'success_rate' => ["Success rate too low: {$successRate}"],
        ]);
    }
}
```

### Pattern 3: External System Sync

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    foreach ($result['records'] as $record) {
        $exists = ExternalSystem::find($record['external_id']);

        if (!$exists) {
            throw ValidationException::withMessages([
                'records' => ["Record {$record['id']} not found in external system"],
            ]);
        }
    }
}
```

### Pattern 4: Checksum Verification

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $expectedChecksum = $item->input['checksum'];
    $actualChecksum = md5(json_encode($result['data']));

    if ($expectedChecksum !== $actualChecksum) {
        throw ValidationException::withMessages([
            'data' => ['Data integrity check failed'],
        ]);
    }
}
```

---

## Testing Validation

```php
// tests/Feature/UserSyncValidationTest.php
use Tests\TestCase;
use GregPriday\WorkManager\Models\WorkItem;
use App\WorkTypes\UserDataSyncType;
use Illuminate\Validation\ValidationException;

class UserSyncValidationTest extends TestCase
{
    public function test_requires_success_field()
    {
        $type = new UserDataSyncType();
        $item = WorkItem::factory()->create();

        $this->expectException(ValidationException::class);

        $type->acceptancePolicy()->validateSubmission($item, [
            'synced_users' => [],
        ]);
    }

    public function test_validates_email_format()
    {
        $type = new UserDataSyncType();
        $item = WorkItem::factory()->create();

        $this->expectException(ValidationException::class);

        $type->acceptancePolicy()->validateSubmission($item, [
            'success' => true,
            'synced_users' => [
                ['user_id' => 1, 'email' => 'invalid-email'],
            ],
        ]);
    }

    public function test_custom_verification_passes()
    {
        $type = new UserDataSyncType();
        $item = WorkItem::factory()->create([
            'input' => ['user_ids' => [1, 2]],
        ]);

        $type->acceptancePolicy()->validateSubmission($item, [
            'success' => true,
            'synced_users' => [
                ['user_id' => 1, 'email' => 'user1@example.com', 'verified' => true],
                ['user_id' => 2, 'email' => 'user2@example.com', 'verified' => true],
            ],
        ]);

        // Should not throw exception
        $this->assertTrue(true);
    }
}
```

---

## Best Practices

1. **Validate Early**: Use Laravel rules for structure, custom logic for business rules
2. **Be Specific**: Provide clear error messages agents can act on
3. **Check Completeness**: Verify all expected data is present
4. **External Verification**: Validate against source of truth when possible
5. **Idempotency**: Validation should be repeatable without side effects
6. **Performance**: Keep validation fast; move expensive checks to `canApprove()`
7. **Logging**: Log validation failures for debugging

---

## Troubleshooting

### Validation Always Passes

**Problem**: Validation rules not being applied

**Solutions**:
1. Check rules are in `submissionValidationRules()`, not constructor
2. Verify method is `protected`, not `private`
3. Ensure acceptance policy is properly returned

### Custom Validation Not Running

**Problem**: `afterValidateSubmission()` not called

**Solutions**:
1. Must be `protected` method
2. Only runs after Laravel validation passes
3. Check no exception thrown in Laravel validation

### canApprove Always Returns False

**Problem**: Order stuck in submitted state

**Solutions**:
1. Check item states are 'submitted' or 'accepted'
2. Verify cross-item validation logic
3. Add logging to debug the condition

---

## See Also

- [Creating Order Types Guide](creating-order-types.md) - Order type basics
- [HTTP API Guide](http-api.md) - Submit endpoint details
- Laravel [Validation Documentation](https://laravel.com/docs/validation)
- [examples/LIFECYCLE.md](../../examples/LIFECYCLE.md) - Complete lifecycle
