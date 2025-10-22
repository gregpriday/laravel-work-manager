# Leasing and Concurrency Guide

**By the end of this guide, you'll be able to:** Understand how leasing works, maintain leases with heartbeats, choose between database and Redis backends, and configure concurrency limits.

---

## How Leasing Works

Work Manager uses **TTL-based leasing** to ensure single-agent processing of work items:

1. Agent **checkouts** an item → Lease acquired with TTL
2. Agent **heartbeats** periodically → Lease extended
3. Agent **submits** results → Lease released
4. If TTL expires → Lease automatically reclaimed

---

## Configuration

```php
// config/work-manager.php
'lease' => [
    'backend' => 'database',        // 'database' or 'redis'
    'ttl_seconds' => 600,           // 10 minutes
    'heartbeat_every_seconds' => 120, // 2 minutes
],
```

---

## Lease Lifecycle

### 1. Checkout (Acquire Lease)

```bash
POST /api/ai/work/orders/{order}/checkout
X-Agent-ID: my-agent-123
```

Response:
```json
{
  "item": {
    "id": "item-uuid",
    "lease_expires_at": "2025-10-22T12:10:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

### 2. Heartbeat (Extend Lease)

```bash
POST /api/ai/work/items/{item}/heartbeat
X-Agent-ID: my-agent-123
```

**Send every 120 seconds** to maintain lease.

### 3. Submit or Release

Lease automatically released when:
- Agent submits results
- Agent explicitly releases

---

## Backend Options

### Database Backend (Default)

**How it works**: Row-level locks on `work_items` table

**Pros**:
- No additional infrastructure
- Works out of the box
- Suitable for small-medium deployments

**Cons**:
- Higher contention under load
- Slower than Redis

**Configuration**:
```php
'lease' => [
    'backend' => 'database',
],
```

### Redis Backend

**How it works**: Redis SET NX EX pattern

**Pros**:
- Much faster
- Better for high concurrency
- Atomic operations

**Cons**:
- Requires Redis server
- Additional infrastructure

**Configuration**:
```php
// config/work-manager.php
'lease' => [
    'backend' => 'redis',
    'redis_connection' => 'default', // Connection name from config/database.php
    'redis_prefix' => 'work:lease:', // Key prefix for lease keys
    'ttl_seconds' => 600,            // Lease duration
],
```

**Redis Connection Setup** in `config/database.php`:
```php
'redis' => [
    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_DB', 0),

        // Important for lease operations
        'read_timeout' => 60,
        'retry_interval' => 100,
    ],
],
```

**Environment Variables** (`.env`):
```bash
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0

# For Work Manager
WORK_MANAGER_LEASE_BACKEND=redis
```

### Redis Production Considerations

**Clock Synchronization**:
- Ensure all application servers have synchronized clocks (use NTP)
- Redis TTL is based on server time, not client time
- Clock skew > 1 second can cause lease conflicts

**TTL Resolution**:
- Redis TTL is in seconds (not milliseconds)
- `ttl_seconds` must be at least 60 (1 minute)
- Recommended minimum: 300 seconds (5 minutes) for production

**Connection Pooling**:
- Use persistent connections when possible
- Configure `max_connections` based on worker count
- Monitor Redis connection count in production

**Failover Strategy**:
- Configure Redis Sentinel or Cluster for high availability
- Application will fall back to database backend if Redis is unavailable (requires implementation)
- Test failure scenarios in staging

**Performance Tuning**:
```php
// config/database.php
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST'),
        'port' => env('REDIS_PORT'),
        'database' => 0,

        // Performance tuning
        'read_timeout' => 2,        // Fast timeout for failures
        'timeout' => 2,              // Connection timeout
        'persistent' => true,        // Reuse connections
        'retry_interval' => 100,    // Retry delay in ms
    ],
],
```

**Monitoring**:
Monitor these Redis metrics:
- `work:lease:*` key count (current active leases)
- Key expiration rate (lease reclamation)
- Connection errors
- Operation latency (SET/DEL commands)

---

## Concurrency Limits

### Per-Agent Limits

Limit how many items one agent can lease:

```php
'lease' => [
    'max_leases_per_agent' => 5,
],
```

Agent trying to lease 6th item receives `409 Conflict`.

### Per-Type Limits

Limit concurrent processing for expensive operations:

```php
'lease' => [
    'max_leases_per_type' => 10,
],
```

Useful for:
- Rate-limiting external API calls
- Preventing database overload
- Managing resource consumption

---

## Lease Expiration & Recovery

### Automatic Reclaim

Run maintenance command (should be scheduled):

```bash
php artisan work-manager:maintain
```

This:
1. Finds expired leases
2. Releases them
3. Re-queues items or fails them (based on max_attempts)

### Schedule in app/Console/Kernel.php

```php
$schedule->command('work-manager:maintain')->everyMinute();
```

---

## Best Practices

1. **Heartbeat regularly**: Every ~20% of TTL
2. **Choose TTL wisely**: 2-3x expected processing time
3. **Use Redis in production**: Better performance
4. **Monitor expired leases**: Track in logs
5. **Set appropriate limits**: Based on resource capacity

---

## See Also

- [Configuration Guide](configuration.md)
- [Console Commands Guide](console-commands.md)
- [HTTP API Guide](http-api.md)
