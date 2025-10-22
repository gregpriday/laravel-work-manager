# Environment Variables Guide

**By the end of this guide, you'll be able to:** Configure Laravel Work Manager using environment variables, understand all available variables and their defaults, and know when environment variables are needed.

---

## Overview

Laravel Work Manager can be configured entirely through `config/work-manager.php`, but for deployment flexibility, many settings support environment variable overrides.

---

## Complete Environment Variable Reference

### Lease Backend Configuration

```bash
# Lease backend type: 'database' or 'redis'
WORK_MANAGER_LEASE_BACKEND=database

# Redis connection name (from config/database.php)
WORK_MANAGER_REDIS_CONNECTION=default
```

**When to use**:
- Use `database` for local development and small deployments
- Use `redis` for production and high-throughput scenarios

### Concurrency Limits

```bash
# Maximum concurrent leases per agent (null = unlimited)
WORK_MANAGER_MAX_LEASES_PER_AGENT=5

# Maximum concurrent leases per order type (null = unlimited)
WORK_MANAGER_MAX_LEASES_PER_TYPE=10
```

**When to use**:
- Set limits to prevent agents from monopolizing resources
- Use type limits for rate-limiting expensive operations

### Partial Submissions

```bash
# Maximum parts per work item
WORK_MANAGER_MAX_PARTS_PER_ITEM=100

# Maximum payload size per part (in bytes)
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=1048576
```

**When to use**:
- Increase for large research or data collection tasks
- Decrease to save database space

### Queue Configuration

```bash
# Queue connection to use
WORK_MANAGER_QUEUE_CONNECTION=redis
```

**When to use**:
- Match your application's queue driver
- Use dedicated connection for work manager jobs

### Idempotency

```bash
# Custom idempotency header name
WORK_MANAGER_IDEMPOTENCY_HEADER=X-Idempotency-Key
```

**When to use**:
- Align with existing API standards
- Avoid header name conflicts

---

## Configuration in .env Files

### Local Development (.env.local)

```bash
# Lease Settings
WORK_MANAGER_LEASE_BACKEND=database
WORK_MANAGER_MAX_LEASES_PER_AGENT=

# Queue
WORK_MANAGER_QUEUE_CONNECTION=sync

# Partials
WORK_MANAGER_MAX_PARTS_PER_ITEM=50
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=524288
```

### Staging Environment (.env.staging)

```bash
# Lease Settings
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=default
WORK_MANAGER_MAX_LEASES_PER_AGENT=10
WORK_MANAGER_MAX_LEASES_PER_TYPE=20

# Queue
WORK_MANAGER_QUEUE_CONNECTION=redis

# Partials
WORK_MANAGER_MAX_PARTS_PER_ITEM=100
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=1048576
```

### Production Environment (.env.production)

```bash
# Lease Settings
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=work
WORK_MANAGER_MAX_LEASES_PER_AGENT=5
WORK_MANAGER_MAX_LEASES_PER_TYPE=50

# Queue
WORK_MANAGER_QUEUE_CONNECTION=sqs

# Partials
WORK_MANAGER_MAX_PARTS_PER_ITEM=500
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=2097152

# Custom header
WORK_MANAGER_IDEMPOTENCY_HEADER=X-Request-ID
```

---

## Connecting to config/work-manager.php

Environment variables are referenced in the config file using `env()`:

```php
// config/work-manager.php
return [
    'lease' => [
        'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'database'),
        'max_leases_per_agent' => env('WORK_MANAGER_MAX_LEASES_PER_AGENT', null),
    ],
    'partials' => [
        'max_parts_per_item' => env('WORK_MANAGER_MAX_PARTS_PER_ITEM', 100),
        'max_payload_bytes' => env('WORK_MANAGER_MAX_PART_PAYLOAD_BYTES', 1048576),
    ],
];
```

The second parameter to `env()` is the default value used when the environment variable is not set.

---

## Default Values Reference

If no environment variables are set, these defaults are used:

| Setting | Default Value | Description |
|---------|---------------|-------------|
| `WORK_MANAGER_LEASE_BACKEND` | `database` | Lease storage backend |
| `WORK_MANAGER_REDIS_CONNECTION` | `default` | Redis connection name |
| `WORK_MANAGER_MAX_LEASES_PER_AGENT` | `null` (unlimited) | Concurrent leases per agent |
| `WORK_MANAGER_MAX_LEASES_PER_TYPE` | `null` (unlimited) | Concurrent leases per type |
| `WORK_MANAGER_MAX_PARTS_PER_ITEM` | `100` | Maximum parts per work item |
| `WORK_MANAGER_MAX_PART_PAYLOAD_BYTES` | `1048576` (1MB) | Maximum part payload size |
| `WORK_MANAGER_QUEUE_CONNECTION` | `redis` | Queue connection |
| `WORK_MANAGER_IDEMPOTENCY_HEADER` | `X-Idempotency-Key` | Idempotency header name |

---

## When Environment Variables Are Required

### Required: Never

Work Manager has sensible defaults for all settings. Environment variables are **optional**.

### Recommended Scenarios

1. **Different backends per environment**:
   ```bash
   # .env.local
   WORK_MANAGER_LEASE_BACKEND=database

   # .env.production
   WORK_MANAGER_LEASE_BACKEND=redis
   ```

2. **Environment-specific limits**:
   ```bash
   # .env.local - unlimited for testing
   WORK_MANAGER_MAX_LEASES_PER_AGENT=

   # .env.production - limited for stability
   WORK_MANAGER_MAX_LEASES_PER_AGENT=5
   ```

3. **Deployment-specific queue drivers**:
   ```bash
   # Local/staging
   WORK_MANAGER_QUEUE_CONNECTION=redis

   # Production on AWS
   WORK_MANAGER_QUEUE_CONNECTION=sqs
   ```

---

## Advanced: Custom Environment Variables

You can add your own environment variables for order type configuration:

### 1. Define in .env

```bash
# Custom variables for your order types
EXTERNAL_API_ENDPOINT=https://api.example.com
EXTERNAL_API_KEY=secret_key_here
RESEARCH_CONFIDENCE_THRESHOLD=0.7
```

### 2. Reference in Order Type

```php
// app/WorkTypes/ExternalSyncType.php
class ExternalSyncType extends AbstractOrderType
{
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        $threshold = env('RESEARCH_CONFIDENCE_THRESHOLD', 0.5);

        if ($result['confidence'] < $threshold) {
            throw ValidationException::withMessages([
                'confidence' => ["Confidence must be at least {$threshold}"],
            ]);
        }
    }

    public function apply(WorkOrder $order): Diff
    {
        $client = new Client([
            'base_uri' => env('EXTERNAL_API_ENDPOINT'),
            'headers' => [
                'Authorization' => 'Bearer ' . env('EXTERNAL_API_KEY'),
            ],
        ]);

        // Use client...
    }
}
```

### 3. Better: Use Config Files

For maintainability, add custom configs:

```php
// config/work-types.php
return [
    'external_sync' => [
        'api_endpoint' => env('EXTERNAL_API_ENDPOINT', 'https://api.example.com'),
        'api_key' => env('EXTERNAL_API_KEY'),
        'confidence_threshold' => env('RESEARCH_CONFIDENCE_THRESHOLD', 0.5),
    ],
];
```

Then reference in order type:

```php
$threshold = config('work-types.external_sync.confidence_threshold');
```

---

## Docker Deployment

When deploying with Docker, pass environment variables:

### docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    image: my-laravel-app
    environment:
      - WORK_MANAGER_LEASE_BACKEND=redis
      - WORK_MANAGER_REDIS_CONNECTION=default
      - WORK_MANAGER_QUEUE_CONNECTION=redis
      - WORK_MANAGER_MAX_LEASES_PER_AGENT=5
    depends_on:
      - redis
      - mysql

  redis:
    image: redis:7-alpine

  mysql:
    image: mysql:8
```

### Kubernetes ConfigMap

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: work-manager-config
data:
  WORK_MANAGER_LEASE_BACKEND: "redis"
  WORK_MANAGER_REDIS_CONNECTION: "default"
  WORK_MANAGER_QUEUE_CONNECTION: "redis"
  WORK_MANAGER_MAX_LEASES_PER_AGENT: "5"
  WORK_MANAGER_MAX_PARTS_PER_ITEM: "500"
```

Apply to deployment:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-app
spec:
  template:
    spec:
      containers:
      - name: app
        envFrom:
        - configMapRef:
            name: work-manager-config
```

---

## CI/CD Integration

### GitHub Actions

```yaml
# .github/workflows/test.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    env:
      WORK_MANAGER_LEASE_BACKEND: database
      WORK_MANAGER_QUEUE_CONNECTION: sync

    steps:
      - uses: actions/checkout@v3

      - name: Run tests
        run: vendor/bin/pest
```

### GitLab CI

```yaml
# .gitlab-ci.yml
test:
  variables:
    WORK_MANAGER_LEASE_BACKEND: database
    WORK_MANAGER_QUEUE_CONNECTION: sync
  script:
    - vendor/bin/pest
```

---

## Troubleshooting

### Environment Variables Not Loading

**Problem**: Changes to `.env` not taking effect

**Solutions**:
1. Restart your application server
2. Clear config cache: `php artisan config:clear`
3. Rebuild cache: `php artisan config:cache`
4. Check file permissions on `.env`

### Wrong Values in Production

**Problem**: Production using local values

**Solutions**:
1. Verify `.env` exists in production
2. Check deployment scripts copy `.env` correctly
3. Use `php artisan config:cache` to cache production config
4. Never commit `.env` to version control

### Redis Connection Errors

**Problem**: `WORK_MANAGER_REDIS_CONNECTION` causes errors

**Solutions**:
1. Verify connection name exists in `config/database.php`
2. Test Redis: `redis-cli ping`
3. Check Redis credentials in `.env`

---

## Best Practices

1. **Never commit `.env`**: Use `.env.example` as template
2. **Use defaults**: Only set env vars when you need to override
3. **Document custom vars**: Add comments in `.env.example`
4. **Validate in production**: Check critical env vars in `AppServiceProvider`
5. **Use config cache**: Run `config:cache` in production for performance

---

## See Also

- [Configuration Guide](configuration.md) - Detailed config file reference
- [Deployment Guide](deployment-and-production.md) - Production setup
- [Leasing Guide](leasing-and-concurrency.md) - Lease backend details
- Main [README.md](../../README.md) - Package overview
