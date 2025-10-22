# Service Provider and Bootstrapping Guide

**By the end of this guide, you'll be able to:** Understand how Laravel Work Manager boots, customize container bindings, override default services, and manually register the package.

---

## How the Package Boots

Laravel Work Manager uses a standard Laravel service provider (`WorkManagerServiceProvider`) that handles registration and bootstrapping.

### Boot Sequence

1. **Registration Phase** (`register()` method):
   - Core services registered as singletons
   - Facades configured
   - Config merged

2. **Boot Phase** (`boot()` method):
   - Migrations published
   - Routes registered (if enabled)
   - Commands registered
   - Policies registered
   - MCP tools registered (if available)

### Automatic Discovery

The package uses Laravel's auto-discovery feature. After running `composer require`, Laravel automatically:

1. Discovers the service provider
2. Registers it in your application
3. Makes all services available via the container

---

## Container Bindings

### Core Service Bindings

The package registers these services as singletons:

```php
// In WorkManagerServiceProvider::register()

// Order type registry
$this->app->singleton(OrderTypeRegistry::class);

// State machine for transitions
$this->app->singleton(StateMachine::class);

// Idempotency service
$this->app->singleton(IdempotencyService::class);

// Lease management
$this->app->singleton(LeaseService::class);

// Work allocation
$this->app->singleton(WorkAllocator::class);

// Work execution
$this->app->singleton(WorkExecutor::class);

// Route registration
$this->app->singleton(RouteRegistrar::class);

// MCP tools
$this->app->singleton(WorkManagerTools::class);
```

### Facade Bindings

The `WorkManager` facade provides convenient access:

```php
use GregPriday\WorkManager\Facades\WorkManager;

// Access registry
WorkManager::registry()->register($orderType);

// Access allocator
WorkManager::allocator()->propose(...);

// Access executor
WorkManager::executor()->submit(...);
```

---

## Accessing Services

### Via Dependency Injection

The recommended approach is constructor injection:

```php
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;

class MyController extends Controller
{
    public function __construct(
        protected WorkAllocator $allocator,
        protected WorkExecutor $executor,
        protected OrderTypeRegistry $registry
    ) {}

    public function createOrder()
    {
        $order = $this->allocator->propose(
            type: 'user.sync',
            payload: ['user_ids' => [1, 2, 3]],
            requestedByType: ActorType::USER,
            requestedById: auth()->id()
        );

        return response()->json(['order' => $order]);
    }
}
```

### Via Facade

For quick access in closures or single-use scenarios:

```php
use GregPriday\WorkManager\Facades\WorkManager;

// In a route
Route::post('/custom-propose', function (Request $request) {
    $order = WorkManager::allocator()->propose(
        type: $request->input('type'),
        payload: $request->input('payload'),
        requestedByType: ActorType::USER,
        requestedById: auth()->id()
    );

    return response()->json(['order' => $order]);
});
```

### Via App Container

For maximum flexibility:

```php
$allocator = app(WorkAllocator::class);
$registry = app(OrderTypeRegistry::class);
```

---

## Overriding Default Bindings

You can override any service binding in your `AppServiceProvider`:

### Override Individual Services

```php
// app/Providers/AppServiceProvider.php
use GregPriday\WorkManager\Services\IdempotencyService;
use App\Services\CustomIdempotencyService;

public function register()
{
    // Override with custom implementation
    $this->app->singleton(IdempotencyService::class, function ($app) {
        return new CustomIdempotencyService();
    });
}
```

### Override Lease Backend

```php
use GregPriday\WorkManager\Contracts\LeaseBackend;
use App\Services\CustomLeaseBackend;

public function register()
{
    $this->app->singleton(LeaseBackend::class, function ($app) {
        return new CustomLeaseBackend();
    });
}
```

### Override Metrics Driver

```php
use GregPriday\WorkManager\Contracts\MetricsDriver;
use App\Services\PrometheusMetricsDriver;

public function register()
{
    $this->app->singleton(MetricsDriver::class, function ($app) {
        return new PrometheusMetricsDriver(
            config('work-manager.metrics.namespace')
        );
    });
}
```

---

## Manual Registration

If you disable auto-discovery, you can manually register the provider:

### Step 1: Disable Auto-Discovery

Add to `composer.json`:

```json
{
    "extra": {
        "laravel": {
            "dont-discover": [
                "gregpriday/laravel-work-manager"
            ]
        }
    }
}
```

### Step 2: Register Provider

Add to `config/app.php`:

```php
'providers' => [
    // ...
    GregPriday\WorkManager\WorkManagerServiceProvider::class,
],
```

### Step 3: Register Facade (Optional)

Add to `config/app.php`:

```php
'aliases' => [
    // ...
    'WorkManager' => GregPriday\WorkManager\Facades\WorkManager::class,
],
```

---

## Conditional Registration

Register services only in specific environments:

### Development Only

```php
// app/Providers/AppServiceProvider.php

public function boot()
{
    if ($this->app->environment('local', 'development')) {
        // Register development-only order types
        WorkManager::registry()->register(new TestOrderType());
    }
}
```

### Production Only

```php
public function boot()
{
    if ($this->app->environment('production')) {
        // Register production-specific metrics driver
        $this->app->singleton(MetricsDriver::class, function () {
            return new PrometheusMetricsDriver();
        });
    }
}
```

---

## Registering Order Types

Order types must be registered during the boot phase:

### In AppServiceProvider

```php
// app/Providers/AppServiceProvider.php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\UserDataSyncType;
use App\WorkTypes\DatabaseRecordInsertType;

public function boot()
{
    // Register your order types
    WorkManager::registry()->register(new UserDataSyncType());
    WorkManager::registry()->register(new DatabaseRecordInsertType());
}
```

### In Dedicated Provider

For better organization, create a dedicated provider:

```php
// app/Providers/WorkOrderTypeProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\UserDataSyncType;
use App\WorkTypes\ReportGenerationType;

class WorkOrderTypeProvider extends ServiceProvider
{
    public function boot()
    {
        WorkManager::registry()->register(new UserDataSyncType());
        WorkManager::registry()->register(new ReportGenerationType());

        // Register more types...
    }
}
```

Then register this provider in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\WorkOrderTypeProvider::class,
],
```

---

## Registering Allocator Strategies

For automatic work generation via `work-manager:generate`:

```php
// app/Providers/AppServiceProvider.php

public function boot()
{
    // Tag strategies for discovery
    $this->app->tag([
        StaleDataAllocatorStrategy::class,
        DailyReportAllocatorStrategy::class,
    ], 'work-manager.strategies');
}
```

---

## Custom Service Initialization

### Initialize Services with Custom Config

```php
public function register()
{
    $this->app->singleton(LeaseService::class, function ($app) {
        return new LeaseService(
            stateMachine: $app->make(StateMachine::class),
            ttl: 1200, // Custom TTL: 20 minutes
            heartbeatInterval: 180 // Custom heartbeat: 3 minutes
        );
    });
}
```

### Initialize with Environment Variables

```php
public function register()
{
    $this->app->singleton(IdempotencyService::class, function () {
        return new IdempotencyService(
            headerName: env('WORK_MANAGER_IDEMPOTENCY_HEADER', 'X-Idempotency-Key'),
            ttl: env('WORK_MANAGER_IDEMPOTENCY_TTL', 86400)
        );
    });
}
```

---

## Deferred Providers

Work Manager services are **not** deferred by default. If you need lazy loading:

```php
// Create a custom deferred provider
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use GregPriday\WorkManager\Services\WorkAllocator;

class DeferredWorkManagerProvider extends ServiceProvider
{
    protected $defer = true;

    public function provides()
    {
        return [WorkAllocator::class];
    }

    public function register()
    {
        $this->app->singleton(WorkAllocator::class, function ($app) {
            return new WorkAllocator(
                $app->make(OrderTypeRegistry::class),
                $app->make(StateMachine::class)
            );
        });
    }
}
```

---

## Troubleshooting

### Services Not Resolving

**Problem**: `Class not found` or `Target class does not exist`

**Solutions**:
1. Run `composer dump-autoload`
2. Clear config cache: `php artisan config:clear`
3. Verify package is in `composer.json`

### Routes Not Registering

**Problem**: Routes return 404

**Solutions**:
1. Check `config/work-manager.php`: `routes.enabled` should be `true`
2. Clear route cache: `php artisan route:clear`
3. Manually register routes (see Configuration guide)

### Order Types Not Found

**Problem**: `OrderTypeNotFoundException`

**Solutions**:
1. Ensure types are registered in `AppServiceProvider::boot()`
2. Check registration happens **after** service provider boots
3. Verify type string matches exactly

---

## Best Practices

1. **Use Dependency Injection**: Prefer constructor injection over facades for better testing
2. **Register Early**: Register order types in service provider `boot()`, not in controllers
3. **Tag Strategies**: Use container tags for discoverable collections
4. **Environment-Specific**: Use environment checks for conditional registration
5. **Single Responsibility**: Create dedicated providers for different concerns

---

## See Also

- [Configuration Guide](configuration.md) - Detailed configuration options
- [Creating Order Types Guide](creating-order-types.md) - Building custom order types
- [Console Commands Guide](console-commands.md) - Running commands
- Main [README.md](../../README.md) - Package overview
