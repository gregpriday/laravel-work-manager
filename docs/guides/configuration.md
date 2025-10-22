# Configuration Guide

**By the end of this guide, you'll be able to:** Edit the Work Manager config file, publish configuration assets, manage per-environment settings, and implement common configuration recipes.

---

## Publishing the Config File

### Initial Publication

```bash
php artisan vendor:publish --tag=work-manager-config
```

This creates `config/work-manager.php` in your application.

### Re-Publishing

To update the config file after a package upgrade:

```bash
php artisan vendor:publish --tag=work-manager-config --force
```

**Warning**: This overwrites your existing config. Back up customizations first.

---

## Configuration File Overview

The config file is organized into logical sections:

```php
return [
    'routes' => [...],           // HTTP API configuration
    'lease' => [...],            // Leasing and concurrency
    'retry' => [...],            // Retry behavior
    'idempotency' => [...],      // Idempotency settings
    'partials' => [...],         // Partial submissions
    'state_machine' => [...],    // State transitions
    'queues' => [...],           // Queue configuration
    'metrics' => [...],          // Metrics collection
    'policies' => [...],         // Authorization
    'maintenance' => [...],      // Maintenance tasks
];
```

---

## Routes Configuration

Control how the HTTP API is registered:

### Enable Auto-Registration

```php
'routes' => [
    'enabled' => true,              // Auto-register routes on boot
    'base_path' => 'agent/work',    // Base URL path
    'middleware' => ['api'],        // Middleware stack
    'guard' => 'sanctum',           // Auth guard
],
```

**Result**: Routes available at `/api/agent/work/*` (Laravel auto-prefixes with `/api`)

### Manual Registration

Set `enabled` to `false` and register manually in `routes/api.php`:

```php
'routes' => [
    'enabled' => false,  // Disable auto-registration
],
```

Then in `routes/api.php`:

```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'ai/work',
    middleware: ['api', 'auth:sanctum', 'throttle:60,1']
);
```

### Custom Middleware Stack

```php
'routes' => [
    'middleware' => [
        'api',
        'auth:sanctum',
        'throttle:work-api',     // Custom rate limiter
        'log.requests',           // Custom logging middleware
    ],
],
```

---

## Lease Configuration

Configure work item leasing and concurrency:

### Basic Settings

```php
'lease' => [
    'backend' => 'database',            // 'database' or 'redis'
    'ttl_seconds' => 600,               // Lease duration (10 min)
    'heartbeat_every_seconds' => 120,   // Heartbeat interval (2 min)
],
```

### Redis Backend

For better performance and scalability:

```php
'lease' => [
    'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'redis'),
    'redis_connection' => env('WORK_MANAGER_REDIS_CONNECTION', 'default'),
    'redis_prefix' => 'work:lease:',
],
```

Requires Redis configured in `config/database.php`:

```php
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
],
```

### Concurrency Limits

Limit concurrent leases per agent or per type:

```php
'lease' => [
    'max_leases_per_agent' => 5,    // Max items one agent can lease
    'max_leases_per_type' => 10,    // Max concurrent items per order type
],
```

Set to `null` for unlimited.

---

## Retry Configuration

Control retry behavior for failed work items:

```php
'retry' => [
    'default_max_attempts' => 3,    // Max retry attempts
    'backoff_seconds' => 60,        // Base backoff time
    'jitter_seconds' => 20,         // Random jitter
],
```

**Backoff calculation**: `backoff_seconds * attempts + random(0, jitter_seconds)`

### Per-Type Overrides

Override in your OrderType's `plan()` method:

```php
public function plan(WorkOrder $order): array
{
    return [[
        'type' => $this->type(),
        'input' => $order->payload,
        'max_attempts' => 5,  // Override default
    ]];
}
```

---

## Idempotency Configuration

Configure idempotency key handling:

```php
'idempotency' => [
    'header' => 'X-Idempotency-Key',
    'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
],
```

### Custom Header Name

```php
'idempotency' => [
    'header' => env('WORK_MANAGER_IDEMPOTENCY_HEADER', 'X-Request-ID'),
],
```

### Selective Enforcement

Only require keys for specific endpoints:

```php
'idempotency' => [
    'enforce_on' => ['submit', 'approve'],  // Only these endpoints
],
```

---

## Partial Submissions Configuration

Configure incremental work submission:

```php
'partials' => [
    'enabled' => true,                      // Enable partial submissions
    'max_parts_per_item' => 100,            // Max parts per work item
    'max_payload_bytes' => 1048576,         // Max part size (1MB)
],
```

### Disable Partials

```php
'partials' => [
    'enabled' => false,
],
```

### Increase Limits

For large research tasks:

```php
'partials' => [
    'max_parts_per_item' => 500,
    'max_payload_bytes' => 5242880,  // 5MB
],
```

---

## State Machine Configuration

Define allowed state transitions:

```php
'state_machine' => [
    'order_transitions' => [
        'queued' => ['checked_out', 'submitted', 'rejected', 'failed'],
        'checked_out' => ['in_progress', 'queued', 'failed'],
        // ... more transitions
    ],
    'item_transitions' => [
        'queued' => ['leased', 'failed'],
        'leased' => ['in_progress', 'queued', 'failed'],
        // ... more transitions
    ],
],
```

**Warning**: Only modify if you need custom workflows. Invalid transitions will cause exceptions.

---

## Queue Configuration

Configure background job processing:

```php
'queues' => [
    'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'redis'),
    'maintenance_queue' => 'work:maintenance',
    'planning_queue' => 'work:planning',
    'agent_job_queue_prefix' => 'agents:',
],
```

### Use Different Connection

```php
'queues' => [
    'connection' => 'sqs',  // Use AWS SQS
],
```

### Custom Queue Names

```php
'queues' => [
    'maintenance_queue' => 'high-priority',
    'planning_queue' => 'low-priority',
],
```

---

## Metrics Configuration

Configure metrics collection:

```php
'metrics' => [
    'enabled' => true,
    'driver' => 'log',              // 'log', 'prometheus', 'statsd'
    'namespace' => 'work_manager',
],
```

### Log Driver (Default)

Writes metrics to Laravel logs:

```php
'metrics' => [
    'driver' => 'log',
],
```

### Prometheus Driver

```php
'metrics' => [
    'driver' => 'prometheus',
    'namespace' => 'work_manager',
    'prometheus_gateway' => env('PROMETHEUS_GATEWAY', 'http://localhost:9091'),
],
```

### StatsD Driver

```php
'metrics' => [
    'driver' => 'statsd',
    'namespace' => 'work_manager',
    'statsd_host' => env('STATSD_HOST', 'localhost'),
    'statsd_port' => env('STATSD_PORT', 8125),
],
```

---

## Policies Configuration

Map abilities to your authorization system:

```php
'policies' => [
    'propose' => 'work.propose',
    'checkout' => 'work.checkout',
    'submit' => 'work.submit',
    'approve' => 'work.approve',
    'reject' => 'work.reject',
],
```

Then define gates in `AuthServiceProvider`:

```php
Gate::define('work.propose', function (User $user) {
    return $user->hasPermission('propose_work');
});

Gate::define('work.approve', function (User $user) {
    return $user->isAdmin();
});
```

---

## Maintenance Configuration

Configure automated maintenance tasks:

```php
'maintenance' => [
    'dead_letter_after_hours' => 48,        // Move to dead letter after 48h
    'stale_order_threshold_hours' => 24,    // Alert on orders older than 24h
    'enable_alerts' => true,                // Enable stale order alerts
],
```

### Adjust Thresholds

For faster cleanup:

```php
'maintenance' => [
    'dead_letter_after_hours' => 12,    // Faster dead lettering
    'stale_order_threshold_hours' => 6, // Earlier alerts
],
```

---

## Per-Environment Configuration

### Using Environment Variables

Reference `.env` values in config:

```php
'lease' => [
    'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'database'),
    'ttl_seconds' => env('WORK_MANAGER_LEASE_TTL', 600),
],
```

Then in `.env`:

```bash
# Production
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_LEASE_TTL=1200

# Local
WORK_MANAGER_LEASE_BACKEND=database
WORK_MANAGER_LEASE_TTL=600
```

### Environment-Specific Configs

Create environment-specific config files:

```php
// config/work-manager.php
return [
    'lease' => [
        'backend' => env('APP_ENV') === 'production' ? 'redis' : 'database',
    ],
];
```

Or use separate files:

```php
// config/work-manager.php
return app()->environment('production')
    ? require __DIR__ . '/work-manager-production.php'
    : require __DIR__ . '/work-manager-local.php';
```

---

## Common Configuration Recipes

### High-Throughput Setup

For maximum performance:

```php
return [
    'lease' => [
        'backend' => 'redis',
        'ttl_seconds' => 300,
        'heartbeat_every_seconds' => 60,
        'max_leases_per_agent' => null,  // Unlimited
    ],
    'queues' => [
        'connection' => 'redis',
    ],
    'metrics' => [
        'driver' => 'statsd',  // Fast, non-blocking
    ],
];
```

### Development Setup

For local development:

```php
return [
    'routes' => [
        'enabled' => true,
        'guard' => null,  // No auth in local
    ],
    'lease' => [
        'backend' => 'database',
        'ttl_seconds' => 3600,  // Longer leases for debugging
    ],
    'metrics' => [
        'driver' => 'log',
    ],
];
```

### Strict Security Setup

For regulated environments:

```php
return [
    'routes' => [
        'middleware' => ['api', 'auth:sanctum', 'verified', 'log.all'],
    ],
    'idempotency' => [
        'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
    ],
    'policies' => [
        'propose' => 'work.propose',
        'checkout' => 'work.checkout',
        'submit' => 'work.submit',
        'approve' => 'work.approve.admin',  // Admin-only
        'reject' => 'work.reject.admin',
    ],
];
```

---

## Troubleshooting

### Config Not Loading

**Problem**: Changes to config file not taking effect

**Solutions**:
1. Clear config cache: `php artisan config:clear`
2. In production, rebuild cache: `php artisan config:cache`

### Routes 404

**Problem**: Routes return 404 errors

**Solutions**:
1. Check `routes.enabled` is `true` OR routes manually registered
2. Clear route cache: `php artisan route:clear`
3. Verify middleware isn't blocking access

### Lease Conflicts

**Problem**: Constant lease conflicts or timeouts

**Solutions**:
1. Switch to Redis backend
2. Increase `ttl_seconds`
3. Adjust `heartbeat_every_seconds` to be ~20% of TTL

---

## See Also

- [Environment Variables Guide](environment-variables.md) - Complete env var reference
- [Service Provider Guide](service-provider-and-bootstrapping.md) - Customizing bindings
- [Leasing Guide](leasing-and-concurrency.md) - Lease system deep dive
- Main [README.md](../../README.md) - Package overview
