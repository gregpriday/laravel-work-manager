# Common Errors and Solutions

This guide covers the most common errors you may encounter when using Laravel Work Manager and how to resolve them.

## Table of Contents

- [Installation & Migration Errors](#installation--migration-errors)
- [Order Type Errors](#order-type-errors)
- [Lease & Concurrency Errors](#lease--concurrency-errors)
- [Validation Errors](#validation-errors)
- [State Transition Errors](#state-transition-errors)
- [Idempotency Errors](#idempotency-errors)
- [Authentication & Authorization Errors](#authentication--authorization-errors)
- [MCP Server Errors](#mcp-server-errors)
- [Configuration Errors](#configuration-errors)

---

## Installation & Migration Errors

### Error: "Class 'GregPriday\WorkManager\WorkManagerServiceProvider' not found"

**Cause**: Package not installed or autoloader not refreshed.

**Solution**:
```bash
composer install
composer dump-autoload
```

If still failing, clear cached config:
```bash
php artisan config:clear
php artisan cache:clear
```

### Error: "SQLSTATE[42S02]: Base table or missing: work_orders"

**Cause**: Migrations not run.

**Solution**:
```bash
# Publish migrations
php artisan vendor:publish --tag=work-manager-migrations

# Run migrations
php artisan migrate
```

### Error: "Syntax error near 'JSON'" (MySQL 5.6)

**Cause**: MySQL version too old (requires 8.0+).

**Solution**: Upgrade MySQL to 8.0+ or use PostgreSQL 13+. Laravel Work Manager requires native JSON column support.

### Error: "Class 'WorkManager' not found"

**Cause**: Using class name instead of facade.

**Solution**: Import the facade:
```php
use GregPriday\WorkManager\Facades\WorkManager;

// Not: use WorkManager;
```

---

## Order Type Errors

### Error: "Order type 'my.type' is not registered"

**Cause**: Order type not registered in service provider.

**Solution**: Register your order type in `AppServiceProvider::boot()`:
```php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\MyType;

public function boot()
{
    WorkManager::registry()->register(new MyType());
}
```

**Common mistake**: Registering in `register()` instead of `boot()`.

### Error: "Call to undefined method type()"

**Cause**: Order type doesn't extend `AbstractOrderType` or implement `OrderType` interface.

**Solution**: Ensure your class extends the base class:
```php
use GregPriday\WorkManager\Support\AbstractOrderType;

class MyType extends AbstractOrderType
{
    public function type(): string
    {
        return 'my.type';
    }

    // ... other methods
}
```

### Error: "Payload validation failed: The field is required"

**Cause**: Proposal payload doesn't match the type's schema.

**Solution**: Check your `schema()` method and ensure the payload matches:
```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['field_name'],
        'properties' => [
            'field_name' => ['type' => 'string'],
        ],
    ];
}

// Payload must include 'field_name'
```

### Error: "Method apply() must return an instance of Diff"

**Cause**: `apply()` method not returning a Diff object.

**Solution**: Always return a Diff:
```php
public function apply(WorkOrder $order): Diff
{
    // Your logic here

    return $this->makeDiff($before, $after, 'Summary');
    // Or: return $this->emptyDiff('No changes needed');
}
```

---

## Lease & Concurrency Errors

### Error: "Work item is already leased by another agent"

**Cause**: Another agent has an active lease on the work item.

**Solution**:
- Wait for the lease to expire (default 600s)
- Or, have the current agent release the lease first
- Check for stuck leases: `php artisan work-manager:maintain`

**Prevention**: Ensure agents call `release` when done:
```bash
curl -X POST /api/agent/work/items/{item}/release \
  -H "X-Agent-ID: my-agent"
```

### Error: "The lease on this work item has expired"

**Cause**: Agent didn't heartbeat within the required interval (default 120s).

**Solution**:
- Increase heartbeat frequency
- Ensure long-running agents send heartbeats:
```bash
# Every 100 seconds (before 120s expires)
curl -X POST /api/agent/work/items/{item}/heartbeat \
  -H "X-Agent-ID: my-agent"
```

**Configuration**: Adjust in `config/work-manager.php`:
```php
'lease' => [
    'ttl_seconds' => 600,           // 10 minutes
    'heartbeat_every_seconds' => 120, // 2 minutes
],
```

### Error: "Max attempts exceeded for work item"

**Cause**: Work item failed multiple times and hit retry limit.

**Solution**:
1. Check why it's failing: `curl /api/agent/work/items/{item}/logs`
2. Increase max attempts in your order type:
```php
public function plan(WorkOrder $order): array
{
    return [[
        'type' => $this->type(),
        'input' => [...],
        'max_attempts' => 5, // Default is 3
    ]];
}
```
3. Or adjust globally: `config/work-manager.php`:
```php
'retry' => [
    'default_max_attempts' => 5,
],
```

### Error: "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found"

**Cause**: Database lock contention when acquiring leases.

**Solution**: Use Redis lease backend for better performance:
```php
// config/work-manager.php
'lease' => [
    'backend' => 'redis',
    'redis_connection' => 'default',
],
```

Ensure Redis is configured in `config/database.php`.

---

## Validation Errors

### Error: "Validation failed: The result field is required"

**Cause**: Agent submission doesn't match `submissionValidationRules()`.

**Solution**: Check your validation rules and ensure agent submits correct structure:
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'result' => 'required|array',
        'result.success' => 'required|boolean',
        'result.data' => 'required|array',
    ];
}
```

Agent must submit:
```json
{
  "result": {
    "success": true,
    "data": [...]
  }
}
```

### Error: "The verified field must be accepted"

**Cause**: Using `accepted` rule but agent sent `false` or omitted field.

**Solution**: The `accepted` rule requires `true`, `1`, `"yes"`, or `"on"`. Use `boolean` if you want to allow both:
```php
// Requires true
'verified' => 'required|boolean|accepted',

// Allows true/false
'verified' => 'required|boolean',
```

### Error: "Call to undefined method afterValidateSubmission()"

**Cause**: Not extending `AbstractOrderType`.

**Solution**: If implementing `AcceptancePolicy` directly:
```php
use GregPriday\WorkManager\Contracts\AcceptancePolicy;

class MyPolicy implements AcceptancePolicy
{
    public function validateSubmission(WorkItem $item, array $result): void
    {
        // Your validation logic
    }

    public function readyForApproval(WorkOrder $order): bool
    {
        // Your approval logic
    }
}
```

Or extend `AbstractOrderType` for automatic Laravel validation integration.

---

## State Transition Errors

### Error: "Illegal state transition: cannot transition from 'submitted' to 'leased'"

**Cause**: Attempting an invalid state transition.

**Solution**: Check allowed transitions in `config/work-manager.php`. You can only transition along valid paths:

**Work Order States**:
```
queued → checked_out → in_progress → submitted → approved → applied → completed
```

**Work Item States**:
```
queued → leased → in_progress → submitted → accepted → completed
```

**Common mistakes**:
- Trying to checkout an already-submitted order
- Trying to submit an item that's not leased
- Trying to approve an order that's not submitted

**Solution**: Check current state before operations:
```bash
curl /api/agent/work/orders/{order}
```

### Error: "Cannot approve order: not all items are submitted"

**Cause**: Trying to approve an order with incomplete items.

**Solution**: Ensure all work items are submitted before approving:
```bash
# Check order status
curl /api/agent/work/orders/{order}

# Look for items still in 'leased' or 'in_progress'
```

### Error: "Cannot transition from 'completed' to 'queued'"

**Cause**: Trying to rerun a completed order.

**Solution**: Completed orders are final. Create a new order instead:
```bash
curl -X POST /api/agent/work/propose \
  -H "Content-Type: application/json" \
  -d '{
    "type": "your.type",
    "payload": {...}
  }'
```

---

## Idempotency Errors

### Error: "Idempotency key conflict detected"

**Cause**: Reusing an idempotency key for a different operation.

**Solution**: Use unique keys per operation:
```bash
# Good: unique key per request
-H "X-Idempotency-Key: propose-order-123-attempt-1"

# Bad: reusing same key
-H "X-Idempotency-Key: my-key"  # for proposal
-H "X-Idempotency-Key: my-key"  # for submission (different op!)
```

**Pattern**: `{operation}-{entity-id}-{attempt-number}`

### Error: "X-Idempotency-Key header is required"

**Cause**: Missing idempotency key on a protected endpoint.

**Solution**: Always include idempotency key for these operations:
- `propose`
- `submit`
- `submit-part`
- `finalize`
- `approve`
- `reject`

```bash
curl -X POST /api/agent/work/propose \
  -H "X-Idempotency-Key: unique-key-here" \
  ...
```

**Configuration**: Can customize in `config/work-manager.php`:
```php
'idempotency' => [
    'header' => 'X-Idempotency-Key',
    'enforce_on' => ['submit', 'propose', 'approve', 'reject'],
],
```

### Error: "Idempotency key already used with different payload"

**Cause**: Same key used for requests with different payloads.

**Solution**: Either:
1. Use a new idempotency key for the new payload
2. Or send exact same payload (returns cached response)

Idempotency keys are tied to the request payload hash.

---

## Authentication & Authorization Errors

### Error: "Unauthenticated"

**Cause**: Missing or invalid authentication token.

**Solution**: Ensure you're sending a valid Bearer token:
```bash
curl -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" ...
```

Check your auth guard configuration:
```php
// config/work-manager.php
'routes' => [
    'guard' => 'sanctum', // or 'api', etc.
],
```

### Error: "This action is unauthorized"

**Cause**: User doesn't have permission for the operation.

**Solution**: Check your policies in `config/work-manager.php`:
```php
'policies' => [
    'propose' => 'work.propose',
    'approve' => 'work.approve',
],
```

Grant permissions in your authorization system:
```php
// Using Laravel's Gate
Gate::define('work.propose', function ($user) {
    return $user->can_propose_work;
});

// Or use WorkOrderPolicy
```

### Error: "Direct mutations must go through the work order system"

**Cause**: Hit a route protected by `EnforceWorkOrderOnly` middleware.

**Solution**: This is intentional. You must create a work order to perform this mutation:
```bash
# Instead of:
POST /api/users  # Blocked

# Do:
POST /api/agent/work/propose
{
  "type": "user.create",
  "payload": {...}
}
```

To allow direct access temporarily (not recommended):
```php
// Remove middleware
Route::post('/users', [UserController::class, 'store']);
  // ->middleware(EnforceWorkOrderOnly::class); // Remove this
```

---

## MCP Server Errors

### Error: "Failed to start MCP server: Address already in use"

**Cause**: Port already bound by another process.

**Solution**:
```bash
# Find process using port
lsof -i :8090

# Kill it or use a different port
php artisan work-manager:mcp --transport=http --port=8091
```

### Error: "JSON parse error in MCP communication"

**Cause**: Debug output interfering with STDIO transport.

**Solution**: Remove all debug output in your handlers:
```php
// Remove these:
dd($data);
dump($data);
echo "Debug: ...";
var_dump($data);

// Use logging instead:
Log::debug('Debug info', $data);
```

### Error: "MCP tools not discovered by AI IDE"

**Cause**: MCP server not configured correctly in IDE.

**Solution**: Check your IDE configuration:

**Cursor** (`.cursorrules`):
```json
{
  "mcp": {
    "servers": {
      "work-manager": {
        "command": "php",
        "args": ["artisan", "work-manager:mcp", "--transport=stdio"],
        "cwd": "/absolute/path/to/laravel/app"
      }
    }
  }
}
```

**Claude Desktop** (`~/Library/Application Support/Claude/claude_desktop_config.json`):
```json
{
  "mcpServers": {
    "work-manager": {
      "command": "php",
      "args": ["/absolute/path/to/artisan", "work-manager:mcp"],
      "env": {
        "APP_ENV": "local"
      }
    }
  }
}
```

Restart your IDE after configuration changes.

### Error: "Connection timeout in HTTP transport"

**Cause**: MCP server not responding or firewall blocking.

**Solution**:
1. Check server is running: `ps aux | grep work-manager:mcp`
2. Test connectivity: `curl http://localhost:8090/mcp/sse`
3. Check firewall rules
4. Increase proxy timeouts if using Nginx/Apache

---

## Configuration Errors

### Error: "Undefined array key 'base_path' in config/work-manager.php"

**Cause**: Configuration file outdated or corrupted.

**Solution**: Republish configuration:
```bash
# Backup your config
cp config/work-manager.php config/work-manager.backup.php

# Republish
php artisan vendor:publish --tag=work-manager-config --force

# Merge your custom changes back
```

### Error: "Redis connection refused"

**Cause**: Redis not running or misconfigured.

**Solution**:
```bash
# Start Redis
redis-server

# Or use database backend instead
# config/work-manager.php
'lease' => [
    'backend' => 'database', // Instead of 'redis'
],
```

### Error: "Queue connection [redis] not configured"

**Cause**: Queue configuration missing.

**Solution**: Configure queue in `config/queue.php`:
```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

Or change to sync for local development:
```php
// config/work-manager.php
'queues' => [
    'connection' => 'sync', // For local dev
],
```

---

## General Debugging Tips

### Enable Debug Logging

Add to your order type:
```php
protected function beforeApply(WorkOrder $order): void
{
    Log::debug('Starting apply', [
        'order_id' => $order->id,
        'payload' => $order->payload,
    ]);
}
```

### Check Event Logs

View full history of an order:
```bash
curl /api/agent/work/items/{item}/logs?limit=100
```

### Run Maintenance

Reclaim stuck leases and dead-letter failed items:
```bash
php artisan work-manager:maintain
```

### Clear Caches

When configuration changes aren't taking effect:
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Database Queries

Check state directly in database:
```sql
-- Check order state
SELECT id, type, state, created_at FROM work_orders WHERE id = 'uuid';

-- Check work items
SELECT id, state, leased_by, lease_expires_at FROM work_items WHERE order_id = 'uuid';

-- Check for stuck leases
SELECT * FROM work_items WHERE state = 'leased' AND lease_expires_at < NOW();

-- Check events
SELECT event, actor, created_at, message FROM work_events WHERE order_id = 'uuid' ORDER BY created_at DESC;
```

---

## Getting More Help

If you're still stuck:

1. Check [FAQ](faq.md) for common questions
2. Review [Known Limitations](known-limitations.md)
3. Search [GitHub issues](https://github.com/gregpriday/laravel-work-manager/issues)
4. Create a new issue with:
   - Laravel version
   - PHP version
   - Database type and version
   - Full error message and stack trace
   - Minimal reproduction steps
