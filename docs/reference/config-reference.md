# Configuration Reference

Complete documentation of all configuration options in `config/work-manager.php`.

## Table of Contents

- [Routes Configuration](#routes-configuration)
- [Lease Configuration](#lease-configuration)
- [Retry Configuration](#retry-configuration)
- [Idempotency Configuration](#idempotency-configuration)
- [Partial Submissions Configuration](#partial-submissions-configuration)
- [State Machine Configuration](#state-machine-configuration)
- [Queue Configuration](#queue-configuration)
- [Metrics Configuration](#metrics-configuration)
- [Policies Configuration](#policies-configuration)
- [Maintenance Configuration](#maintenance-configuration)

---

## Routes Configuration

Controls how package routes are registered and accessed.

### `routes.enabled`

**Type:** `bool`
**Default:** `false`
**Environment Variable:** None

Whether to auto-register routes at boot time. If `false`, you must manually register routes using `WorkManager::routes()` in your `routes/api.php` or service provider.

**Example:**
```php
'enabled' => true,
```

### `routes.base_path`

**Type:** `string`
**Default:** `'agent/work'`
**Environment Variable:** None

Base path prefix for all package routes. Only used if `routes.enabled` is `true`.

**Example:**
```php
'base_path' => 'ai/work',
```

**Result:** Routes will be available at `/ai/work/propose`, `/ai/work/orders`, etc.

### `routes.middleware`

**Type:** `array`
**Default:** `['api']`
**Environment Variable:** None

Middleware stack applied to all package routes. Only used if `routes.enabled` is `true`.

**Example:**
```php
'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
```

### `routes.guard`

**Type:** `string`
**Default:** `'sanctum'`
**Environment Variable:** None

Authentication guard name used for authorization checks in agent endpoints.

**Example:**
```php
'guard' => 'api',
```

---

## Lease Configuration

Controls work item lease behavior and concurrency.

### `lease.backend`

**Type:** `string`
**Default:** `'database'`
**Environment Variable:** `WORK_MANAGER_LEASE_BACKEND`

Lease storage backend. Determines how leases are acquired and tracked.

**Options:**
- `'database'` - Uses `work_items` table with row-level locks (default)
- `'redis'` - Uses Redis SET NX EX pattern for better performance and scalability

**Example:**
```php
'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'redis'),
```

### `lease.ttl_seconds`

**Type:** `int`
**Default:** `600` (10 minutes)
**Environment Variable:** None

Default lease time-to-live in seconds. Agents must heartbeat before the lease expires to maintain control of a work item.

**Example:**
```php
'ttl_seconds' => 900, // 15 minutes
```

### `lease.heartbeat_every_seconds`

**Type:** `int`
**Default:** `120` (2 minutes)
**Environment Variable:** None

Recommended heartbeat interval in seconds. This value is returned to agents in checkout responses as guidance for how often to send heartbeat requests.

**Recommendation:** Set to approximately 1/5 of `ttl_seconds` for safety margin.

**Example:**
```php
'heartbeat_every_seconds' => 180, // 3 minutes
```

### `lease.redis_connection`

**Type:** `string`
**Default:** `'default'`
**Environment Variable:** `WORK_MANAGER_REDIS_CONNECTION`

Redis connection name to use for lease storage. Only applicable if `backend` is `'redis'`.

**Example:**
```php
'redis_connection' => env('WORK_MANAGER_REDIS_CONNECTION', 'cache'),
```

### `lease.redis_prefix`

**Type:** `string`
**Default:** `'work:lease:'`
**Environment Variable:** None

Key prefix for Redis lease keys. Only applicable if `backend` is `'redis'`.

**Example:**
```php
'redis_prefix' => 'app:work:lease:',
```

**Result:** Lease keys will be stored as `app:work:lease:{item_id}`

### `lease.max_leases_per_agent`

**Type:** `int|null`
**Default:** `null` (unlimited)
**Environment Variable:** `WORK_MANAGER_MAX_LEASES_PER_AGENT`

Maximum number of concurrent leases a single agent can hold. When set, prevents a single agent from monopolizing work items.

**Example:**
```php
'max_leases_per_agent' => env('WORK_MANAGER_MAX_LEASES_PER_AGENT', 5),
```

### `lease.max_leases_per_type`

**Type:** `int|null`
**Default:** `null` (unlimited)
**Environment Variable:** `WORK_MANAGER_MAX_LEASES_PER_TYPE`

Maximum number of concurrent leases for a specific work item type across all agents. Useful for rate-limiting resource-intensive operations.

**Example:**
```php
'max_leases_per_type' => env('WORK_MANAGER_MAX_LEASES_PER_TYPE', 10),
```

---

## Retry Configuration

Controls retry behavior for failed work items.

### `retry.default_max_attempts`

**Type:** `int`
**Default:** `3`
**Environment Variable:** None

Default maximum number of retry attempts for a work item before it transitions to `failed` state.

**Example:**
```php
'default_max_attempts' => 5,
```

**Note:** Individual order types can override this on a per-item basis in their `plan()` method.

### `retry.backoff_seconds`

**Type:** `int`
**Default:** `60` (1 minute)
**Environment Variable:** None

Base backoff delay in seconds before a failed item becomes available for retry.

**Example:**
```php
'backoff_seconds' => 120, // 2 minutes
```

### `retry.jitter_seconds`

**Type:** `int`
**Default:** `20`
**Environment Variable:** None

Random jitter added to backoff to prevent thundering herd. Actual delay will be `backoff_seconds ± jitter_seconds`.

**Example:**
```php
'jitter_seconds' => 30,
```

---

## Idempotency Configuration

Controls idempotency key enforcement and behavior.

### `idempotency.header`

**Type:** `string`
**Default:** `'X-Idempotency-Key'`
**Environment Variable:** None

HTTP header name for idempotency keys. Clients must send this header with a unique value for idempotent operations.

**Example:**
```php
'header' => 'X-Request-Id',
```

### `idempotency.enforce_on`

**Type:** `array`
**Default:** `['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize']`
**Environment Variable:** None

List of endpoint names that require idempotency keys. Requests to these endpoints without a valid key will receive a 428 (Precondition Required) response.

**Available Endpoints:**
- `'propose'` - Creating new work orders
- `'submit'` - Submitting work item results
- `'submit-part'` - Submitting partial results
- `'finalize'` - Finalizing assembled results
- `'approve'` - Approving work orders
- `'reject'` - Rejecting work orders

**Example:**
```php
'enforce_on' => ['submit', 'propose'], // Only enforce on submit and propose
```

---

## Partial Submissions Configuration

Controls partial submission feature settings.

### `partials.enabled`

**Type:** `bool`
**Default:** `true`
**Environment Variable:** None

Enable or disable the partial submissions feature globally. When disabled, agents cannot use `submit-part` or `finalize` endpoints.

**Example:**
```php
'enabled' => false, // Disable partial submissions
```

### `partials.max_parts_per_item`

**Type:** `int`
**Default:** `100`
**Environment Variable:** `WORK_MANAGER_MAX_PARTS_PER_ITEM`

Maximum number of parts that can be submitted for a single work item. Prevents unbounded growth of partial data.

**Example:**
```php
'max_parts_per_item' => env('WORK_MANAGER_MAX_PARTS_PER_ITEM', 50),
```

### `partials.max_payload_bytes`

**Type:** `int`
**Default:** `1048576` (1 MB)
**Environment Variable:** `WORK_MANAGER_MAX_PART_PAYLOAD_BYTES`

Maximum size in bytes for a single part's payload. Prevents excessively large submissions.

**Example:**
```php
'max_payload_bytes' => env('WORK_MANAGER_MAX_PART_PAYLOAD_BYTES', 5242880), // 5 MB
```

---

## State Machine Configuration

Defines allowed state transitions for work orders and items.

### `state_machine.order_transitions`

**Type:** `array`
**Default:** See below
**Environment Variable:** None

Map of allowed state transitions for work orders. Each key is a current state, and the value is an array of states it can transition to.

**Default Configuration:**
```php
'order_transitions' => [
    'queued' => ['checked_out', 'submitted', 'rejected', 'failed'],
    'checked_out' => ['in_progress', 'queued', 'failed'],
    'in_progress' => ['submitted', 'failed', 'queued'],
    'submitted' => ['approved', 'rejected', 'failed'],
    'approved' => ['applied', 'failed'],
    'applied' => ['completed', 'failed'],
    'rejected' => ['queued', 'dead_lettered'],
    'failed' => ['queued', 'dead_lettered'],
    'completed' => [],
    'dead_lettered' => [],
],
```

**Warning:** Modifying this configuration can break the state machine. Only change if you understand the implications.

### `state_machine.item_transitions`

**Type:** `array`
**Default:** See below
**Environment Variable:** None

Map of allowed state transitions for work items. Each key is a current state, and the value is an array of states it can transition to.

**Default Configuration:**
```php
'item_transitions' => [
    'queued' => ['leased', 'failed'],
    'leased' => ['in_progress', 'queued', 'failed'],
    'in_progress' => ['submitted', 'failed', 'queued'],
    'submitted' => ['accepted', 'rejected', 'failed'],
    'accepted' => ['completed'],
    'rejected' => ['queued', 'failed'],
    'completed' => [],
    'failed' => ['queued', 'dead_lettered'],
    'dead_lettered' => [],
],
```

**Warning:** Modifying this configuration can break the state machine. Only change if you understand the implications.

---

## Queue Configuration

Controls queue connections and names for background jobs.

### `queues.connection`

**Type:** `string`
**Default:** `'redis'`
**Environment Variable:** `WORK_MANAGER_QUEUE_CONNECTION`

Queue connection name to use for all work manager background jobs.

**Example:**
```php
'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'database'),
```

### `queues.maintenance_queue`

**Type:** `string`
**Default:** `'work:maintenance'`
**Environment Variable:** None

Queue name for maintenance jobs (lease reclamation, dead-lettering, etc.).

**Example:**
```php
'maintenance_queue' => 'system:maintenance',
```

### `queues.planning_queue`

**Type:** `string`
**Default:** `'work:planning'`
**Environment Variable:** None

Queue name for work order planning jobs.

**Example:**
```php
'planning_queue' => 'work:planning',
```

### `queues.agent_job_queue_prefix`

**Type:** `string`
**Default:** `'agents:'`
**Environment Variable:** None

Prefix for agent-specific job queues. Individual order types can dispatch jobs to queues like `agents:research`, `agents:data-sync`, etc.

**Example:**
```php
'agent_job_queue_prefix' => 'ai:',
```

**Result:** Order types can dispatch to queues like `ai:research`, `ai:data-sync`

---

## Metrics Configuration

Controls metrics collection and reporting.

### `metrics.enabled`

**Type:** `bool`
**Default:** `true`
**Environment Variable:** None

Enable or disable metrics collection globally.

**Example:**
```php
'enabled' => false,
```

### `metrics.driver`

**Type:** `string`
**Default:** `'log'`
**Environment Variable:** None

Metrics driver to use for collecting and reporting metrics.

**Options:**
- `'log'` - Logs metrics to Laravel log (default)
- `'prometheus'` - Exports metrics in Prometheus format (requires additional setup)
- `'statsd'` - Sends metrics to StatsD server (requires additional setup)
- `'null'` - Disables metrics collection

**Example:**
```php
'driver' => 'prometheus',
```

### `metrics.namespace`

**Type:** `string`
**Default:** `'work_manager'`
**Environment Variable:** None

Namespace prefix for all metrics. Used to prevent naming conflicts with other metrics in your system.

**Example:**
```php
'namespace' => 'app_work',
```

**Result:** Metrics will be named like `app_work.orders.proposed`, `app_work.items.leased`, etc.

---

## Policies Configuration

Maps package abilities to your application's gates/permissions.

### `policies.propose`

**Type:** `string`
**Default:** `'work.propose'`
**Environment Variable:** None

Gate/ability name for proposing new work orders.

**Example:**
```php
'propose' => 'can-propose-work',
```

### `policies.checkout`

**Type:** `string`
**Default:** `'work.checkout'`
**Environment Variable:** None

Gate/ability name for checking out (leasing) work items.

**Example:**
```php
'checkout' => 'can-checkout-work',
```

### `policies.submit`

**Type:** `string`
**Default:** `'work.submit'`
**Environment Variable:** None

Gate/ability name for submitting work item results.

**Example:**
```php
'submit' => 'can-submit-work',
```

### `policies.approve`

**Type:** `string`
**Default:** `'work.approve'`
**Environment Variable:** None

Gate/ability name for approving work orders.

**Example:**
```php
'approve' => 'can-approve-work',
```

### `policies.reject`

**Type:** `string`
**Default:** `'work.reject'`
**Environment Variable:** None

Gate/ability name for rejecting work orders.

**Example:**
```php
'reject' => 'can-reject-work',
```

**Usage Example:**

Define gates in your `AuthServiceProvider`:

```php
Gate::define('work.approve', function ($user) {
    return $user->hasRole('admin');
});
```

---

## Maintenance Configuration

Controls maintenance task behavior and thresholds.

### `maintenance.dead_letter_after_hours`

**Type:** `int`
**Default:** `48` (2 days)
**Environment Variable:** None

Number of hours after which failed work orders/items are automatically moved to dead letter queue by the `work-manager:maintain` command.

**Example:**
```php
'dead_letter_after_hours' => 72, // 3 days
```

### `maintenance.stale_order_threshold_hours`

**Type:** `int`
**Default:** `24` (1 day)
**Environment Variable:** None

Number of hours after which non-terminal work orders are considered "stale" and generate alerts during maintenance checks.

**Example:**
```php
'stale_order_threshold_hours' => 12, // 12 hours
```

### `maintenance.enable_alerts`

**Type:** `bool`
**Default:** `true`
**Environment Variable:** None

Whether to log warnings and emit events for stale orders detected during maintenance.

**Example:**
```php
'enable_alerts' => false,
```

---

## Environment Variables Summary

Quick reference for all environment variables used in configuration:

| Variable | Config Key | Type | Default | Description |
|----------|------------|------|---------|-------------|
| `WORK_MANAGER_LEASE_BACKEND` | `lease.backend` | string | `'database'` | Lease storage backend (database/redis) |
| `WORK_MANAGER_REDIS_CONNECTION` | `lease.redis_connection` | string | `'default'` | Redis connection for leases |
| `WORK_MANAGER_MAX_LEASES_PER_AGENT` | `lease.max_leases_per_agent` | int\|null | `null` | Max leases per agent |
| `WORK_MANAGER_MAX_LEASES_PER_TYPE` | `lease.max_leases_per_type` | int\|null | `null` | Max leases per work type |
| `WORK_MANAGER_MAX_PARTS_PER_ITEM` | `partials.max_parts_per_item` | int | `100` | Max parts per work item |
| `WORK_MANAGER_MAX_PART_PAYLOAD_BYTES` | `partials.max_payload_bytes` | int | `1048576` | Max bytes per part payload |
| `WORK_MANAGER_QUEUE_CONNECTION` | `queues.connection` | string | `'redis'` | Queue connection name |

**.env Example:**
```env
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=cache
WORK_MANAGER_MAX_LEASES_PER_AGENT=5
WORK_MANAGER_MAX_PARTS_PER_ITEM=50
WORK_MANAGER_QUEUE_CONNECTION=database
```

---

## Configuration Best Practices

### Production Recommendations

1. **Lease Backend:** Use Redis for better performance and scalability
   ```php
   'backend' => 'redis',
   ```

2. **TTL and Heartbeat:** Keep TTL at least 5x heartbeat interval
   ```php
   'ttl_seconds' => 600,          // 10 minutes
   'heartbeat_every_seconds' => 120,  // 2 minutes
   ```

3. **Concurrency Limits:** Set reasonable limits to prevent resource exhaustion
   ```php
   'max_leases_per_agent' => 10,
   'max_leases_per_type' => 20,
   ```

4. **Idempotency:** Always enforce on critical endpoints
   ```php
   'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
   ```

5. **Metrics:** Use Prometheus or StatsD for production monitoring
   ```php
   'driver' => 'prometheus',
   ```

### Development Recommendations

1. **Routes:** Enable auto-registration for convenience
   ```php
   'enabled' => true,
   'base_path' => 'dev/work',
   ```

2. **Lease Backend:** Use database for simplicity
   ```php
   'backend' => 'database',
   ```

3. **Shorter TTLs:** Faster feedback during development
   ```php
   'ttl_seconds' => 300,  // 5 minutes
   ```

4. **Metrics:** Use log driver for simplicity
   ```php
   'driver' => 'log',
   ```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Routes Reference](./routes-reference.md) - HTTP endpoint documentation
- [Commands Reference](./commands-reference.md) - Artisan command documentation
