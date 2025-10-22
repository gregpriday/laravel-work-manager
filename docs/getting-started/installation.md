# Installation

This guide walks you through installing and configuring Laravel Work Manager in your Laravel application.

## Step 1: Install via Composer

Install the package using Composer:

```bash
composer require gregpriday/laravel-work-manager
```

The package will automatically register its service provider via Laravel's package auto-discovery.

## Step 2: Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=work-manager-config
```

This creates `config/work-manager.php` with all available configuration options.

## Step 3: Publish and Run Migrations

Publish the migration files:

```bash
php artisan vendor:publish --tag=work-manager-migrations
```

This copies migration files to your `database/migrations` directory. Review them if needed, then run:

```bash
php artisan migrate
```

This creates the following tables:
- `work_orders` - High-level work contracts
- `work_items` - Individual units of work
- `work_events` - Audit trail of all state changes
- `work_provenance` - Agent metadata and request fingerprints
- `work_item_parts` - Partial submissions for incremental work
- `work_idempotency_keys` - Stored idempotency keys with cached responses

## Step 4: Configure Routes

Choose how to register routes in your application.

### Option A: Manual Registration (Recommended)

In `routes/api.php`, mount the routes under your preferred path:

```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum']
);
```

**Note**: Routes registered in `routes/api.php` are automatically prefixed with `/api`, so the above will be accessible at `/api/agent/work/*`.

### Option B: Auto-Registration via Config

Alternatively, enable auto-registration in `config/work-manager.php`:

```php
'routes' => [
    'enabled' => true,
    'base_path' => 'agent/work',
    'middleware' => ['api', 'auth:sanctum'],
    'guard' => 'sanctum',
],
```

## Step 5: Review Configuration

Open `config/work-manager.php` and review key settings:

### Authentication

```php
'routes' => [
    'guard' => 'sanctum',  // Or 'api', 'web', or your custom guard
],
```

### Lease Backend

Choose between database (default) or Redis:

```php
'lease' => [
    'backend' => env('WORK_MANAGER_LEASE_BACKEND', 'database'),
    // For Redis:
    'redis_connection' => env('WORK_MANAGER_REDIS_CONNECTION', 'default'),

    'ttl_seconds' => 600,  // 10 minutes
    'heartbeat_every_seconds' => 120,  // 2 minutes
],
```

### Partial Submissions

Enable or disable partial submissions:

```php
'partials' => [
    'enabled' => true,
    'max_parts_per_item' => 100,
    'max_payload_bytes' => 1048576,  // 1MB
],
```

### State Machine

The default state transitions rarely need modification, but can be customized:

```php
'state_machine' => [
    'order_transitions' => [
        'queued' => ['checked_out', 'submitted', 'rejected', 'failed'],
        // ... full configuration in the file
    ],
],
```

## Step 6: Set Environment Variables (Optional)

Add to `.env` if customizing defaults:

```bash
# Lease backend
WORK_MANAGER_LEASE_BACKEND=database  # or 'redis'
WORK_MANAGER_REDIS_CONNECTION=default

# Concurrency limits
WORK_MANAGER_MAX_LEASES_PER_AGENT=10
WORK_MANAGER_MAX_LEASES_PER_TYPE=50

# Partial submissions
WORK_MANAGER_MAX_PARTS_PER_ITEM=100
WORK_MANAGER_MAX_PART_PAYLOAD_BYTES=1048576

# Queue configuration
WORK_MANAGER_QUEUE_CONNECTION=redis
```

## Step 7: Schedule Commands (Optional)

If you plan to use automated work generation or need maintenance tasks, add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Generate new work orders (if using AllocatorStrategy implementations)
    $schedule->command('work-manager:generate')
             ->everyFifteenMinutes();

    // Reclaim expired leases, dead-letter stuck work
    $schedule->command('work-manager:maintain')
             ->everyMinute();
}
```

## Verification

### Check Installation

Verify the package is installed:

```bash
php artisan about
```

Look for "Work Manager" in the output.

### List Available Commands

```bash
php artisan list work-manager
```

You should see:
- `work-manager:generate` - Generate new work orders
- `work-manager:maintain` - Maintenance tasks (lease reclaim, dead-letter)
- `work-manager:mcp` - Start MCP server

### Test Database Connection

Create a simple test to verify table creation:

```bash
php artisan tinker
```

```php
use GregPriday\WorkManager\Models\WorkOrder;

WorkOrder::count();  // Should return 0 (or existing count)
```

### Test Route Registration

Check that routes are registered:

```bash
php artisan route:list | grep work
```

You should see routes like:
- `POST api/agent/work/propose`
- `POST api/agent/work/orders/{order}/checkout`
- `POST api/agent/work/items/{item}/submit`
- etc.

## Redis Setup (If Using Redis Backend)

### Install Redis Extension

If not already installed:

```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS (via PECL)
pecl install redis

# Or via Homebrew
brew install php-redis
```

### Configure Laravel Redis

In `config/database.php`, ensure Redis is configured:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],
],
```

### Test Redis Connection

```bash
php artisan tinker
```

```php
use Illuminate\Support\Facades\Redis;

Redis::ping();  // Should return "+PONG"
```

## Troubleshooting

### Migration Errors

**Error**: Column not found / Unknown column

**Solution**: Ensure you're running Laravel 11+ and your database supports JSON columns.

### Route Not Found

**Error**: 404 when accessing `/api/agent/work/propose`

**Solution**:
1. Check that routes are registered (see "Test Route Registration" above)
2. Verify middleware allows unauthenticated access for testing, or provide valid auth token
3. Ensure `config/work-manager.php` has correct settings if using auto-registration

### Class Not Found

**Error**: `Class 'GregPriday\WorkManager\...' not found`

**Solution**:
```bash
composer dump-autoload
php artisan clear-compiled
php artisan config:clear
```

### Redis Connection Failed

**Error**: Connection refused when using Redis backend

**Solution**:
1. Verify Redis is running: `redis-cli ping`
2. Check `.env` for correct Redis host/port
3. Ensure `php-redis` extension is installed and enabled

## Next Steps

Now that Laravel Work Manager is installed, you're ready to:

1. **Create Your First Order Type**: Follow the [Quickstart Guide](quickstart.md)
2. **Configure Authentication**: Set up Sanctum or your auth guard
3. **Define Work Types**: Create custom order types for your use cases
4. **Set Up MCP Server**: Enable AI agent integration via MCP
5. **Schedule Commands**: Enable automated work generation and maintenance

---

## See Also

- [Quickstart](quickstart.md) - Build your first work order type
- [Configuration](../guides/configuration.md) - Detailed configuration guide
- [Creating Order Types](../guides/creating-order-types.md) - Order type development
- [MCP Server Integration](../guides/mcp-server-integration.md) - Set up MCP for agents
- [Common Errors](../troubleshooting/common-errors.md) - Installation troubleshooting
