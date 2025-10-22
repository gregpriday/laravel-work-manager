# Configuration Model

## Introduction

Laravel Work Manager is highly configurable to adapt to different deployment scenarios, scaling requirements, and security policies. This document explains the complete configuration model, precedence rules, and key decision points.

---

## Configuration File Structure

The main configuration file is published to `config/work-manager.php`:

```bash
php artisan vendor:publish --tag=work-manager-config
```

### Configuration Sections

```php
return [
    'routes' => [...],              // HTTP API routing
    'lease' => [...],               // Leasing system
    'retry' => [...],               // Failure handling
    'idempotency' => [...],         // Idempotency enforcement
    'partials' => [...],            // Partial submissions
    'state_machine' => [...],       // State transitions
    'queues' => [...],              // Laravel queue integration
    'metrics' => [...],             // Observability
    'policies' => [...],            // Authorization
    'maintenance' => [...],         // Scheduled maintenance
];
```

---

## Routes Configuration

### Purpose
Control how Work Manager API endpoints are registered and protected.

### Configuration

```php
'routes' => [
    'enabled' => false,                  // Auto-register routes?
    'base_path' => 'agent/work',         // Base path if auto-registered
    'middleware' => ['api'],             // Applied middleware stack
    'guard' => 'sanctum',                // Auth guard name
],
```

### Decision Points

#### Q: Should routes be auto-registered?

**Option A: Auto-Registration (Enabled)**
```php
'routes' => [
    'enabled' => true,
    'base_path' => 'agent/work',
    'middleware' => ['api', 'auth:sanctum'],
],
```

**Pros**:
- Quick setup
- Consistent configuration

**Cons**:
- Less flexibility
- All endpoints use same middleware

**When to use**: Prototypes, simple deployments

---

**Option B: Manual Registration (Disabled, Recommended)**
```php
// config/work-manager.php
'routes' => [
    'enabled' => false,
],

// routes/api.php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum', 'throttle:60,1']
);
```

**Pros**:
- Full control over middleware
- Custom rate limiting per deployment
- Can mount under different paths

**Cons**:
- Requires manual setup

**When to use**: Production deployments

---

#### Q: Which auth guard should be used?

**Option A: Sanctum (Recommended for APIs)**
```php
'guard' => 'sanctum',
```

**Best for**: AI agents, mobile apps, SPAs

---

**Option B: Session (Web Applications)**
```php
'guard' => 'web',
```

**Best for**: Backend users, admin interfaces

---

**Option C: Passport (OAuth2)**
```php
'guard' => 'passport',
```

**Best for**: Third-party integrations, federated systems

---

### Environment Variables

```env
WORK_MANAGER_GUARD=sanctum
```

---

## Lease Configuration

### Purpose
Control work item leasing behavior, TTL, heartbeat requirements, and concurrency.

### Configuration

```php
'lease' => [
    'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'database'),
    'ttl_seconds' => 600,                    // 10 minutes
    'heartbeat_every_seconds' => 120,        // 2 minutes

    // Redis backend (if backend = 'redis')
    'redis_connection' => env('WORK_MANAGER_REDIS_CONNECTION', 'default'),
    'redis_prefix' => 'work:lease:',

    // Concurrency limits (optional)
    'max_leases_per_agent' => env('WORK_MANAGER_MAX_LEASES_PER_AGENT', null),
    'max_leases_per_type' => env('WORK_MANAGER_MAX_LEASES_PER_TYPE', null),
],
```

### Decision Points

#### Q: Which lease backend should be used?

**Option A: Database (Default)**
```php
'backend' => 'database',
```

**How it works**:
- Uses `SELECT FOR UPDATE` row-level locks
- Stores lease info in `work_items` table
- No additional infrastructure required

**Pros**:
- Simple setup (no Redis)
- Transactional consistency
- Works out of the box

**Cons**:
- Database becomes bottleneck at scale
- More database load

**When to use**: Small to medium deployments, development

---

**Option B: Redis (Recommended for Scale)**
```php
'backend' => 'redis',
'redis_connection' => 'default',
'redis_prefix' => 'work:lease:',
```

**How it works**:
- Uses Redis `SET NX EX` pattern
- Distributed locking
- TTL handled by Redis natively

**Pros**:
- Better performance at scale
- Reduced database load
- Native TTL support
- Horizontal scaling

**Cons**:
- Requires Redis infrastructure
- Additional operational complexity

**When to use**: Production deployments with high concurrency

**Environment Variables**:
```env
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=work
```

---

#### Q: What TTL and heartbeat interval should be used?

**Default Values**:
```php
'ttl_seconds' => 600,              // 10 minutes
'heartbeat_every_seconds' => 120,  // 2 minutes
```

**Rule of Thumb**: `heartbeat_interval` should be **3-5x smaller** than `ttl`.

**Tuning Guidelines**:

| Workload Type | TTL | Heartbeat | Reasoning |
|---------------|-----|-----------|-----------|
| Fast (< 1 min) | 180s | 30s | Short leases, quick reclaim |
| Normal (5-10 min) | 600s | 120s | Default, balanced |
| Long (30+ min) | 1800s | 300s | Research tasks, large processing |

**Example: Fast Workload**
```php
'ttl_seconds' => 180,
'heartbeat_every_seconds' => 30,
```

**Example: Long Workload**
```php
'ttl_seconds' => 1800,
'heartbeat_every_seconds' => 300,
```

---

#### Q: Should concurrency limits be enforced?

**Option A: No Limits (Default)**
```php
'max_leases_per_agent' => null,
'max_leases_per_type' => null,
```

**When to use**: Trusted agents, controlled environments

---

**Option B: Per-Agent Limits**
```php
'max_leases_per_agent' => 10,
```

**Purpose**: Prevent a single agent from monopolizing work

**When to use**: Multi-tenant systems, untrusted agents

---

**Option C: Per-Type Limits**
```php
'max_leases_per_type' => 50,
```

**Purpose**: Control system load for specific work types

**When to use**: Rate-sensitive types (e.g., external API calls)

---

**Environment Variables**:
```env
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_MAX_LEASES_PER_AGENT=10
WORK_MANAGER_MAX_LEASES_PER_TYPE=50
```

---

## Retry Configuration

### Purpose
Control failure handling, retry attempts, and backoff.

### Configuration

```php
'retry' => [
    'default_max_attempts' => 3,
    'backoff_seconds' => 60,
    'jitter_seconds' => 20,
],
```

### Retry Behavior

**When item fails**:
1. Check `attempt_count < max_attempts`
2. If true: re-queue with backoff
3. If false: dead-letter the item

**Backoff Calculation**:
```
retry_delay = backoff_seconds + random(0, jitter_seconds)
```

Example with defaults:
- Attempt 1 fails → retry in 60-80 seconds
- Attempt 2 fails → retry in 60-80 seconds
- Attempt 3 fails → dead-lettered

### Tuning Guidelines

| Scenario | max_attempts | backoff | jitter | Reasoning |
|----------|--------------|---------|--------|-----------|
| Transient failures (network) | 5 | 60 | 20 | Give time to recover |
| Expensive operations | 2 | 300 | 60 | Avoid wasting resources |
| Critical operations | 10 | 30 | 10 | Aggressive retry |

**Example: Network-Heavy Work**
```php
'retry' => [
    'default_max_attempts' => 5,
    'backoff_seconds' => 120,
    'jitter_seconds' => 30,
],
```

---

## Idempotency Configuration

### Purpose
Control idempotency enforcement for mutating operations.

### Configuration

```php
'idempotency' => [
    'header' => 'X-Idempotency-Key',
    'enforce_on' => [
        'submit',
        'propose',
        'approve',
        'reject',
        'submit-part',
        'finalize',
    ],
],
```

### Decision Points

#### Q: Which operations require idempotency keys?

**Default (Recommended)**:
```php
'enforce_on' => [
    'submit',       // Agent submissions
    'propose',      // Work order creation
    'approve',      // Approval
    'reject',       // Rejection
    'submit-part',  // Partial submissions
    'finalize',     // Finalization
],
```

**Stricter (All Mutating Operations)**:
```php
'enforce_on' => [
    'submit', 'propose', 'approve', 'reject',
    'submit-part', 'finalize',
    'heartbeat',    // Even heartbeats
    'checkout',     // Even checkouts
],
```

**Looser (Critical Only)**:
```php
'enforce_on' => [
    'propose',      // Only order creation
    'approve',      // And approval
],
```

---

#### Q: Should the idempotency header name be customized?

**Default**:
```php
'header' => 'X-Idempotency-Key',
```

**Custom (for compatibility)**:
```php
'header' => 'Idempotency-Key',  // Stripe-style
```

---

## Partial Submissions Configuration

### Purpose
Control incremental submission features.

### Configuration

```php
'partials' => [
    'enabled' => true,
    'max_parts_per_item' => env('WORK_MANAGER_MAX_PARTS_PER_ITEM', 100),
    'max_payload_bytes' => env('WORK_MANAGER_MAX_PART_PAYLOAD_BYTES', 1048576), // 1MB
],
```

### Decision Points

#### Q: Should partial submissions be enabled?

**Enabled (Default)**:
```php
'enabled' => true,
```

**When to use**: Complex work items, research tasks, large results

---

**Disabled**:
```php
'enabled' => false,
```

**When to use**: Simple, fast work items only

---

#### Q: What limits should be set?

**Default (Balanced)**:
```php
'max_parts_per_item' => 100,
'max_payload_bytes' => 1048576,  // 1MB
```

**Large Tasks (Research, Scraping)**:
```php
'max_parts_per_item' => 500,
'max_payload_bytes' => 5242880,  // 5MB
```

**Small Tasks (Fast Processing)**:
```php
'max_parts_per_item' => 10,
'max_payload_bytes' => 262144,  // 256KB
```

**Environment Variables**:
```env
WORK_MANAGER_MAX_PARTS_PER_ITEM=100
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=1048576
```

---

## State Machine Configuration

### Purpose
Define allowed state transitions (rarely modified).

### Configuration

```php
'state_machine' => [
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
],
```

### When to Modify

**WARNING**: Only modify if you have a very specific workflow requirement.

**Example: Allow direct completion from submitted**
```php
'item_transitions' => [
    // ... existing transitions ...
    'submitted' => ['accepted', 'rejected', 'failed', 'completed'],
],
```

**Most users should not modify this section.**

---

## Queue Configuration

### Purpose
Configure Laravel queue integration for background processing.

### Configuration

```php
'queues' => [
    'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'redis'),
    'maintenance_queue' => 'work:maintenance',
    'planning_queue' => 'work:planning',
    'agent_job_queue_prefix' => 'agents:',
],
```

### Decision Points

#### Q: Which queue connection should be used?

**Redis (Recommended)**:
```php
'connection' => 'redis',
```

**Database**:
```php
'connection' => 'database',
```

**Sync (Development Only)**:
```php
'connection' => 'sync',
```

---

#### Q: Should queues be dedicated?

**Shared Queue (Simple)**:
```php
'maintenance_queue' => 'default',
'planning_queue' => 'default',
```

**Dedicated Queues (Production)**:
```php
'maintenance_queue' => 'work:maintenance',
'planning_queue' => 'work:planning',
```

Start dedicated workers:
```bash
php artisan queue:work --queue=work:maintenance
php artisan queue:work --queue=work:planning
```

**Environment Variables**:
```env
WORK_MANAGER_QUEUE_CONNECTION=redis
```

---

## Metrics Configuration

### Purpose
Configure observability and monitoring.

### Configuration

```php
'metrics' => [
    'enabled' => true,
    'driver' => 'log',  // 'prometheus', 'statsd', 'log'
    'namespace' => 'work_manager',
],
```

### Decision Points

#### Q: Which metrics driver should be used?

**Log (Default, Development)**:
```php
'driver' => 'log',
```

Records metrics to Laravel log.

---

**Prometheus (Not Available)**:
```php
'driver' => 'prometheus',  // Not yet implemented
```

Use `'log'` or `'statsd'` instead.

---

**StatsD (Alternative)**:
```php
'driver' => 'statsd',
```

Sends metrics to StatsD/Graphite.

**Requirements**:
```bash
composer require league/statsd
```

---

**Disabled**:
```php
'enabled' => false,
```

No metrics collected.

---

## Policies Configuration

### Purpose
Map Work Manager abilities to your application's gates/permissions.

### Configuration

```php
'policies' => [
    'propose' => 'work.propose',
    'checkout' => 'work.checkout',
    'submit' => 'work.submit',
    'approve' => 'work.approve',
    'reject' => 'work.reject',
],
```

### Integration with Laravel Gates

```php
// app/Providers/AuthServiceProvider.php

Gate::define('work.propose', function (User $user) {
    return $user->hasPermission('work:propose');
});

Gate::define('work.approve', function (User $user) {
    return $user->hasRole('admin') || $user->hasRole('supervisor');
});
```

### Custom Policies

Override in `config/work-manager.php`:
```php
'policies' => [
    'propose' => 'custom.propose.permission',
    'approve' => 'custom.approve.permission',
],
```

---

## Maintenance Configuration

### Purpose
Configure thresholds for scheduled maintenance tasks.

### Configuration

```php
'maintenance' => [
    'dead_letter_after_hours' => 48,
    'stale_order_threshold_hours' => 24,
    'enable_alerts' => true,
],
```

### Decision Points

#### Q: When should stuck work be dead-lettered?

**Default (48 hours)**:
```php
'dead_letter_after_hours' => 48,
```

**Fast Turnaround (6 hours)**:
```php
'dead_letter_after_hours' => 6,
```

**Patient (1 week)**:
```php
'dead_letter_after_hours' => 168,
```

---

#### Q: When should stale order alerts fire?

**Default (24 hours)**:
```php
'stale_order_threshold_hours' => 24,
```

Alerts fire if order is in `in_progress` or `submitted` for more than 24 hours.

**Fast Workflows (4 hours)**:
```php
'stale_order_threshold_hours' => 4,
```

**Slow Workflows (1 week)**:
```php
'stale_order_threshold_hours' => 168,
```

---

## Precedence Rules

### Configuration Precedence Order

1. **Runtime Override** (highest priority)
   - Passed directly to methods
   ```php
   LeaseService::acquire($order, $agentId, ttl: 300);
   ```

2. **Environment Variables**
   ```env
   WORK_MANAGER_LEASE_BACKEND=redis
   WORK_MANAGER_MAX_LEASES_PER_AGENT=10
   ```

3. **Config File** (`config/work-manager.php`)
   ```php
   'lease' => ['ttl_seconds' => 600]
   ```

4. **Package Defaults** (lowest priority)
   - Defined in package's default configuration

---

### Example: TTL Resolution

```php
// config/work-manager.php
'lease' => ['ttl_seconds' => 600],

// .env
WORK_MANAGER_LEASE_TTL=300

// Runtime
$item = $leaseService->acquire($order, $agentId, ttl: 120);
```

**Resolution**: `120` (runtime override wins)

---

## Environment Variables Reference

### Complete List

```env
# Lease Configuration
WORK_MANAGER_LEASE_BACKEND=database          # or 'redis'
WORK_MANAGER_REDIS_CONNECTION=default
WORK_MANAGER_MAX_LEASES_PER_AGENT=null       # or integer
WORK_MANAGER_MAX_LEASES_PER_TYPE=null        # or integer

# Partial Submissions
WORK_MANAGER_MAX_PARTS_PER_ITEM=100
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=1048576

# Queue Configuration
WORK_MANAGER_QUEUE_CONNECTION=redis
```

---

## Configuration Recipes

### Recipe: High-Performance Production Setup

```php
'routes' => [
    'enabled' => false,  // Manual registration with custom middleware
],

'lease' => [
    'backend' => 'redis',
    'ttl_seconds' => 600,
    'heartbeat_every_seconds' => 120,
    'max_leases_per_agent' => 10,
    'max_leases_per_type' => 100,
],

'retry' => [
    'default_max_attempts' => 3,
    'backoff_seconds' => 120,
    'jitter_seconds' => 30,
],

'queues' => [
    'connection' => 'redis',
    'maintenance_queue' => 'work:maintenance',
    'planning_queue' => 'work:planning',
],

'metrics' => [
    'enabled' => true,
    'driver' => 'prometheus',
],
```

```env
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=work
WORK_MANAGER_MAX_LEASES_PER_AGENT=10
WORK_MANAGER_QUEUE_CONNECTION=redis
```

---

### Recipe: Simple Development Setup

```php
'routes' => [
    'enabled' => true,
    'base_path' => 'work',
    'middleware' => ['api'],
    'guard' => 'sanctum',
],

'lease' => [
    'backend' => 'database',
    'ttl_seconds' => 300,
    'heartbeat_every_seconds' => 60,
],

'retry' => [
    'default_max_attempts' => 2,
    'backoff_seconds' => 30,
    'jitter_seconds' => 10,
],

'queues' => [
    'connection' => 'sync',  // Synchronous for debugging
],

'metrics' => [
    'driver' => 'log',
],
```

---

### Recipe: Multi-Tenant SaaS Setup

```php
'lease' => [
    'backend' => 'redis',
    'ttl_seconds' => 600,
    'heartbeat_every_seconds' => 120,
    'max_leases_per_agent' => 5,    // Per-tenant limit
    'max_leases_per_type' => 20,    // Per-tenant limit
],

'retry' => [
    'default_max_attempts' => 3,
    'backoff_seconds' => 60,
    'jitter_seconds' => 20,
],

'maintenance' => [
    'dead_letter_after_hours' => 24,
    'stale_order_threshold_hours' => 6,
    'enable_alerts' => true,
],
```

Add tenant isolation in your order types:
```php
protected function beforeApply(WorkOrder $order): void
{
    // Verify tenant context
    if ($order->tenant_id !== auth()->user()->tenant_id) {
        throw new \Exception('Tenant mismatch');
    }
}
```

---

## Validation and Testing

### Validate Configuration

```php
// config/work-manager.php validation helper
php artisan config:cache

// Check for errors
php artisan tinker
>>> config('work-manager.lease.backend')
=> "redis"
```

### Test Configuration Changes

```php
// Test lease backend
use GregPriday\WorkManager\Services\LeaseService;

$leaseService = app(LeaseService::class);
$item = $leaseService->acquire($order, 'test-agent');

// Test metrics
use GregPriday\WorkManager\Services\MetricsService;

$metrics = app(MetricsService::class);
$metrics->recordOrderCreated($order);
```

---

## See Also

- [What It Does](what-it-does.md) - Core concepts
- [Architecture Overview](architecture-overview.md) - System design
- [Lifecycle and Flow](lifecycle-and-flow.md) - Work order lifecycle
- [State Management](state-management.md) - State machine configuration
- [Security and Permissions](security-and-permissions.md) - Auth and policies
- [ARCHITECTURE.md](../concepts/architecture-overview.md) - Scaling and performance considerations
