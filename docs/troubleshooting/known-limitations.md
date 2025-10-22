# Known Limitations and Edge Cases

This document outlines current limitations, edge cases, and areas for future improvement in Laravel Work Manager.

## Current Limitations

### 1. Database Support

**Limitation**: Only MySQL 8.0+, PostgreSQL 13+, and MariaDB 10.5+ are supported.

**Reason**: The package requires native JSON column support and advanced locking features.

**Workaround**: None. Upgrade your database or use a supported database.

**Future**: Consider adding support for older databases with serialized columns (v2.0).

---

### 2. Single-Server Lease Reclaim

**Limitation**: The `work-manager:maintain` command is not fully distributed. Running on multiple servers simultaneously may cause duplicate reclaim attempts.

**Impact**: Low. Database transactions and idempotency prevent data corruption, but may cause unnecessary database load.

**Workaround**: Run `work-manager:maintain` on a single designated server or use distributed locking:

```php
// In custom maintain command
use Illuminate\Support\Facades\Cache;

if (Cache::lock('work-manager:maintain', 60)->get()) {
    // Run maintenance
    $this->call('work-manager:maintain');
}
```

**Future**: Built-in distributed lock support (v1.2).

---

### 3. No Built-in Work Item Dependencies

**Limitation**: Work items within an order cannot declare dependencies on each other (e.g., "Item B must complete before Item C starts").

**Impact**: All items in an order are independent and can be processed in any order.

**Workaround**: Create separate orders with sequential processing, or implement custom dependency logic in your order type:

```php
public function plan(WorkOrder $order): array
{
    // Create items in stages
    return [
        ['type' => $this->type(), 'input' => ['stage' => 1]],
        // Stage 2 items created by afterApply of stage 1
    ];
}
```

**Future**: DAG-based workflow support with item dependencies (v2.0).

---

### 4. Limited JSON Schema Validation

**Limitation**: Basic JSON schema validation using internal helpers. Not a full JSON Schema Draft 7 implementation.

**Impact**: Complex schema features (pattern properties, conditionals, etc.) may not work as expected.

**Workaround**: Implement additional validation in `afterValidateSubmission()`:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Custom complex validation
    if (!$this->customValidator->validate($result)) {
        throw ValidationException::withMessages([...]);
    }
}
```

**Recommended**: For strict schema validation, integrate `opis/json-schema`:

```php
use Opis\JsonSchema\Validator;

public function validatePayloadSchema(array $payload): void
{
    $validator = new Validator();
    $result = $validator->validate(
        json_decode(json_encode($payload)),
        $this->schema()
    );

    if (!$result->isValid()) {
        throw new ValidationException($result->error());
    }
}
```

**Future**: Full JSON Schema Draft 7 support (v1.2).

---

### 5. No Built-in Rate Limiting Per Agent

**Limitation**: No built-in per-agent rate limiting for API calls.

**Impact**: A misbehaving agent could make excessive API requests.

**Workaround**: Use Laravel's rate limiting middleware:

```php
use Illuminate\Routing\Middleware\ThrottleRequests;

WorkManager::routes(
    middleware: ['api', 'auth:sanctum', 'throttle:60,1'] // 60 req/min
);
```

Or implement custom agent-level limits in your middleware.

**Future**: Built-in agent rate limiting and quota management (v1.2).

---

### 6. Partial Submissions Not Ordered

**Limitation**: Partial submissions (via `submit-part`) are not guaranteed to be processed in submission order.

**Impact**: When finalizing, parts are assembled by `id` order, not submission time.

**Workaround**: Include sequence numbers in your partial data:

```json
{
  "part_data": {
    "sequence": 1,
    "content": "..."
  }
}
```

Then sort in your finalization logic.

**Future**: Add optional `sequence` field to WorkItemPart model (v1.2).

---

### 7. No Built-in Prometheus Metrics Driver

**Limitation**: The Prometheus metrics driver is configured but not yet implemented. Only `log` driver currently works.

**Impact**: Cannot push metrics directly to Prometheus.

**Workaround**: Use the `log` driver and parse logs with Promtail/Fluentd, or implement custom metrics:

```php
// In your EventServiceProvider
use GregPriday\WorkManager\Events\WorkOrderApplied;
use Prometheus\CollectorRegistry;

Event::listen(WorkOrderApplied::class, function ($event) {
    $registry = app(CollectorRegistry::class);
    $counter = $registry->getOrRegisterCounter(
        'work_manager',
        'orders_applied_total',
        'Total orders applied',
        ['type']
    );
    $counter->inc(['type' => $event->order->type]);
});
```

**Future**: Complete Prometheus driver implementation (v1.1).

---

### 8. Apply Hook Cannot Be Async

**Limitation**: The `apply()` method runs synchronously. Long-running operations block the approval request.

**Impact**: Approval requests may timeout for slow operations.

**Workaround**: Keep `apply()` fast and queue follow-up work:

```php
public function apply(WorkOrder $order): Diff
{
    // Fast database operations only
    DB::transaction(function () use ($order) {
        // Quick writes
    });

    // Queue slow operations
    ProcessDataJob::dispatch($order)->onQueue('work');

    return $this->makeDiff($before, $after, 'Summary');
}
```

**Alternative**: Use `afterApply()` for all slow operations.

**Future**: Optional async apply with callback URL (v2.0).

---

### 9. No Built-in Workflow Orchestration

**Limitation**: No built-in support for complex workflows (conditional branches, loops, parallel execution, etc.).

**Impact**: Complex multi-stage workflows require custom implementation.

**Workaround**: Chain orders or implement custom orchestration:

```php
// In afterApply
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    if ($this->needsFollowUp($order)) {
        WorkManager::propose([
            'type' => 'next.stage.type',
            'payload' => [
                'previous_order_id' => $order->id,
                'data' => $diff->after,
            ],
        ]);
    }
}
```

**Future**: Workflow engine with DAG support (v2.0).

---

### 10. Limited Multi-Tenancy Support

**Limitation**: No first-class multi-tenancy. No automatic tenant isolation or per-tenant quotas.

**Impact**: In SaaS applications, all work orders are in a shared space.

**Workaround**: Add `tenant_id` to payload and filter in policies:

```php
// In your order type
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['tenant_id', ...],
        'properties' => [
            'tenant_id' => ['type' => 'string'],
            // ...
        ],
    ];
}

// In WorkOrderPolicy
public function propose(User $user): bool
{
    return $user->tenant->hasQuota('work_orders');
}

// Add global scope
class WorkOrder extends Model
{
    protected static function booted()
    {
        static::addGlobalScope('tenant', function ($builder) {
            if (auth()->check()) {
                $builder->whereJsonContains(
                    'payload->tenant_id',
                    auth()->user()->tenant_id
                );
            }
        });
    }
}
```

**Future**: Native multi-tenancy with tenant_id column and automatic isolation (v1.3).

---

## Edge Cases

### 1. Lease Expiration During Submit

**Scenario**: Agent's lease expires while submitting results.

**Behavior**: Submit will fail with `LeaseExpiredException`.

**Mitigation**: Ensure agents heartbeat frequently enough:
```php
// Before long operations
curl -X POST /items/{item}/heartbeat

// Then submit
curl -X POST /items/{item}/submit
```

---

### 2. Concurrent Approval Attempts

**Scenario**: Two users try to approve the same order simultaneously.

**Behavior**: One succeeds, the other gets `IllegalStateTransitionException` (order already in `applied` or `completed` state).

**Mitigation**: Use idempotency keys. Retry with same key returns cached response.

---

### 3. Agent Resubmits After Item Accepted

**Scenario**: Agent submits, item is accepted, then agent tries to submit again.

**Behavior**: Fails with state transition error (item in `accepted` or `completed` state).

**Mitigation**: Agents should track submitted items and not resubmit. Use idempotency keys for safe retries.

---

### 4. Order Type Unregistered Mid-Flight

**Scenario**: Order type is unregistered while work items are being processed.

**Behavior**:
- Existing orders continue to work
- New items can be checked out
- Approval fails with `OrderTypeNotFoundException`

**Mitigation**: Never unregister types with active orders. Instead:
1. Stop generating new orders of that type
2. Let existing orders complete
3. Then remove type registration

---

### 5. Database Connection Lost During Apply

**Scenario**: Database connection drops during `apply()` execution.

**Behavior**: Transaction rolls back, apply throws exception, order transitions to `failed`.

**Mitigation**:
- Use database connection pooling
- Configure reconnect in `config/database.php`:
```php
'mysql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
],
```
- Implement retry logic in maintenance command

---

### 6. Large Payload Submission

**Scenario**: Agent submits very large result payload (> 1MB).

**Behavior**: May hit database column limits or PHP memory limits.

**Mitigation**: Use partial submissions for large data:

```bash
# Submit in chunks
curl -X POST /items/{item}/submit-part -d '{"part": "chunk1"}'
curl -X POST /items/{item}/submit-part -d '{"part": "chunk2"}'
curl -X POST /items/{item}/finalize
```

Configure limits in `config/work-manager.php`:
```php
'partials' => [
    'max_parts_per_item' => 100,
    'max_payload_bytes' => 1048576, // 1MB
],
```

---

### 7. Clock Skew in Distributed Systems

**Scenario**: Server clocks are out of sync across multiple servers.

**Behavior**: Lease TTL calculations may be off, causing premature or delayed reclamation.

**Mitigation**:
- Use NTP to sync server clocks
- Or use Redis backend (Redis handles TTL internally)
- Add buffer to TTL calculations:
```php
'lease' => [
    'ttl_seconds' => 600 + 30, // Add 30s buffer
],
```

---

### 8. Validation Rules Change After Submission

**Scenario**: Order type's `submissionValidationRules()` change while items are in progress.

**Behavior**: Old submissions may not pass new validation when retrying.

**Mitigation**: Version your order types:
```php
// Old version
class UserSyncTypeV1 extends AbstractOrderType
{
    public function type(): string
    {
        return 'user.sync.v1';
    }
}

// New version with stricter validation
class UserSyncTypeV2 extends AbstractOrderType
{
    public function type(): string
    {
        return 'user.sync.v2';
    }
}
```

Register both until v1 orders complete.

---

## Known Test Gaps

Some edge case tests are currently skipped pending investigation:

1. **Lease conflict detection**: Complex race condition scenarios
2. **Order readiness checks**: Cross-item validation edge cases
3. **Concurrent partial submissions**: High-concurrency partial submission race conditions

These are tracked in the test suite with `markTestSkipped()` and do not affect core functionality.

---

## Planned Improvements

See [ARCHITECTURE.md](../ARCHITECTURE.md) "Recommended Improvements" section for detailed future enhancements:

1. Enhanced JSON schema validation
2. Auto-approval by type (implemented, needs docs)
3. Concurrency governance & rate limiting
4. Redis lease backend (implemented)
5. Event outbox pattern
6. Multi-tenancy support
7. Evidence & provenance standards
8. Observability & metrics (Prometheus)
9. Safety & compliance guardrails
10. Enhanced failure handling

---

## Reporting Issues

If you encounter a limitation or edge case not documented here:

1. Check [GitHub issues](https://github.com/gregpriday/laravel-work-manager/issues)
2. Create a new issue with:
   - Clear description of the limitation
   - Impact on your use case
   - Workaround if found
   - Minimal reproduction code

Your feedback helps prioritize future improvements.
