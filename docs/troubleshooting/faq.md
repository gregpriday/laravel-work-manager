# Frequently Asked Questions

Common questions about Laravel Work Manager, organized by topic.

## Table of Contents

- [General](#general)
- [Configuration & Setup](#configuration--setup)
- [Order Types](#order-types)
- [Leasing & Concurrency](#leasing--concurrency)
- [Validation & Verification](#validation--verification)
- [MCP Server](#mcp-server)
- [Production & Performance](#production--performance)
- [Integration](#integration)

---

## General

### What is Laravel Work Manager?

Laravel Work Manager is an AI-agent oriented work order control plane for Laravel applications. It provides a framework for creating, leasing, validating, approving, and applying typed work orders with strong guarantees around state, idempotency, auditability, and agent ergonomics.

### Why should I use this instead of regular API endpoints?

Work Manager provides:
- **State machine enforcement**: Prevents invalid operations
- **Leasing with TTL**: Prevents concurrent processing of the same work
- **Idempotency**: Safe retries for agent operations
- **Auditability**: Complete event history and provenance
- **Two-phase verification**: Validate agent work before applying changes
- **Type safety**: Per-type schemas, validation, and execution logic

This is especially valuable when AI agents are performing non-trivial backend operations.

### Is this only for AI agents?

No. While designed with AI agents in mind, Work Manager works with any client that needs:
- Reliable work distribution
- Lease-based concurrency control
- Comprehensive auditability
- Idempotent mutations

It's useful for distributed systems, background workers, and human-approved workflows.

### What's the difference between the HTTP API and MCP server?

- **HTTP API**: RESTful JSON endpoints for custom integrations, web applications, or any HTTP client
- **MCP Server**: Model Context Protocol server for AI IDEs (Cursor, Claude Desktop) with automatic tool discovery

Both provide the same functionality. MCP is recommended for AI agents, HTTP for custom integrations.

---

## Configuration & Setup

### Do I need Redis?

**No, but it's recommended for production.**

Database backend (default):
- Uses row-level locks on `work_items` table
- Works with MySQL/PostgreSQL
- Good for low-medium traffic

Redis backend:
- Better performance and scalability
- Recommended for high traffic
- Native TTL support

Configure in `config/work-manager.php`:
```php
'lease' => [
    'backend' => 'redis', // or 'database'
],
```

### Which Laravel versions are supported?

Laravel 11 and Laravel 12.

### Which PHP versions are supported?

PHP 8.2 and above.

### Which databases are supported?

- **MySQL 8.0+** (requires native JSON support)
- **PostgreSQL 13+**
- **MariaDB 10.5+** (with JSON support)

MySQL 5.7 and below are NOT supported due to JSON column limitations.

### Do I need to run scheduled commands?

**Recommended for production:**

```php
// app/Console/Kernel.php
$schedule->command('work-manager:generate')->everyFifteenMinutes(); // Optional
$schedule->command('work-manager:maintain')->everyMinute();        // Recommended
```

- `work-manager:generate`: Runs your custom AllocatorStrategy implementations to create new work orders (optional)
- `work-manager:maintain`: Reclaims expired leases, dead-letters stuck work, alerts on stale orders (recommended)

### Can I customize the route prefix?

Yes, specify when registering routes:

```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'my/custom/path',  // Will be /api/my/custom/path
    middleware: ['api', 'auth:sanctum']
);
```

Or disable auto-registration and register routes manually.

### How do I customize authentication?

Configure the guard in `config/work-manager.php`:

```php
'routes' => [
    'guard' => 'sanctum', // or 'api', 'web', custom guard
],
```

Or apply custom middleware:
```php
WorkManager::routes(
    middleware: ['api', 'custom-auth']
);
```

---

## Order Types

### How many order types can I register?

No limit. Register as many types as you need:

```php
WorkManager::registry()->register(new UserDataSyncType());
WorkManager::registry()->register(new ContentFactCheckType());
WorkManager::registry()->register(new DatabaseInsertType());
// ... etc
```

### Can one order type have multiple work items?

Yes. Override `plan()` to create multiple items:

```php
public function plan(WorkOrder $order): array
{
    $items = [];

    foreach ($order->payload['batch'] as $record) {
        $items[] = [
            'type' => $this->type(),
            'input' => ['record' => $record],
            'max_attempts' => 3,
        ];
    }

    return $items;
}
```

Each item can be processed independently by different agents.

### Do I have to extend AbstractOrderType?

**No, but it's recommended.**

You can implement the `OrderType` interface directly:
```php
use GregPriday\WorkManager\Contracts\OrderType;

class MyType implements OrderType
{
    public function type(): string { ... }
    public function schema(): array { ... }
    public function plan(WorkOrder $order): array { ... }
    public function acceptancePolicy(): AcceptancePolicy { ... }
    public function apply(WorkOrder $order): Diff { ... }
}
```

But `AbstractOrderType` provides:
- Laravel validation integration
- Lifecycle hooks (beforeApply, afterApply)
- Helper methods (makeDiff, emptyDiff)
- Default planning logic

### Can I have a separate validation class?

Yes. Extend `AbstractAcceptancePolicy`:

```php
use GregPriday\WorkManager\Support\AbstractAcceptancePolicy;

class MyValidationPolicy extends AbstractAcceptancePolicy
{
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'result' => 'required|array',
        ];
    }
}
```

Then reference it in your order type:
```php
public function acceptancePolicy(): AcceptancePolicy
{
    return new MyValidationPolicy();
}
```

### What if my apply() logic is slow?

**Options:**

1. **Break into smaller items**: Override `plan()` to create smaller, faster work items
2. **Use background jobs**: Queue follow-up work in `afterApply()`
3. **Optimize queries**: Use transactions, eager loading, chunking
4. **Use streaming**: For large data sets, process in batches

Example:
```php
public function apply(WorkOrder $order): Diff
{
    $before = ['processed' => 0];

    // Quick database operation
    DB::transaction(function () use ($order) {
        // Fast operations only
    });

    $after = ['processed' => 100];

    // Queue slow operations
    ProcessDataJob::dispatch($order)->onQueue('work');

    return $this->makeDiff($before, $after, 'Summary');
}
```

---

## Leasing & Concurrency

### What happens if an agent crashes?

The lease will expire (default 600s). After expiration, `work-manager:maintain` will:
1. Reclaim the lease
2. Re-queue the item for retry (if attempts < max)
3. Or dead-letter it if max attempts reached

### Can multiple agents work on the same order?

**Yes, but not the same work item.**

One order can have multiple work items. Each item can be leased by a different agent:

```
Order A
├── Item 1 (Agent A) ← leased
├── Item 2 (Agent B) ← leased
└── Item 3 ← queued
```

But only one agent can lease a specific work item at a time.

### How do I limit concurrent work per agent?

Configure in `config/work-manager.php`:

```php
'lease' => [
    'max_leases_per_agent' => 10,  // Max items per agent
    'max_leases_per_type' => 50,   // Max items per type globally
],
```

### Can I customize lease duration per order type?

Yes, override in your item configuration:

```php
public function plan(WorkOrder $order): array
{
    return [[
        'type' => $this->type(),
        'input' => [...],
        'max_attempts' => 3,
        'lease_ttl_override' => 1800, // 30 minutes instead of default
    ]];
}
```

### What if I don't need heartbeats?

For very short tasks (< 2 minutes), you can skip heartbeats. Just ensure:
1. Task completes before lease expires
2. Submit results before expiration

But heartbeats are recommended for:
- Tasks > 2 minutes
- Network-dependent tasks
- External API calls

---

## Validation & Verification

### What's the difference between validation and approval?

**Two-phase verification:**

1. **Submission Validation** (per item, when agent submits):
   - Laravel validation rules (`submissionValidationRules`)
   - Custom business logic (`afterValidateSubmission`)
   - Validates individual work item results

2. **Approval Readiness** (per order, before applying):
   - Cross-item validation (`canApprove`)
   - Checks if entire order is ready for execution
   - Validates order-level consistency

### Can I auto-approve certain order types?

Yes. Set in your order type:

```php
protected bool $autoApprove = true;
```

When all items pass validation, the order will automatically approve and apply. Use only for:
- Deterministic operations
- Well-validated types
- Trusted agents

### How do I provide good error messages to agents?

Use Laravel validation messages:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'email' => 'required|email',
        'data.*.verified' => 'required|boolean|accepted',
    ];
}

protected function validationMessages(): array
{
    return [
        'email.required' => 'Email address is required for user sync',
        'email.email' => 'Please provide a valid email address',
        'data.*.verified.accepted' => 'All data records must be verified before submission',
    ];
}
```

### Can I reject a submission after it passes validation?

Yes, in `afterValidateSubmission`:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Additional checks after validation
    if (!$this->externalApi->verify($result['data'])) {
        throw ValidationException::withMessages([
            'data' => ['External API verification failed'],
        ]);
    }
}
```

---

## MCP Server

### Should I use STDIO or HTTP transport?

**STDIO (Local)**:
- For AI IDEs (Cursor, Claude Desktop)
- Direct process communication
- Lower latency
- Development and local use

**HTTP (Remote)**:
- For production deployments
- Remote agent access
- Multiple concurrent clients
- Behind reverse proxy/load balancer

### Can I run both transports simultaneously?

No. Choose one:
```bash
# Local development
php artisan work-manager:mcp --transport=stdio

# Production
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

### How do I secure the MCP HTTP server?

**Production checklist:**

1. **Use reverse proxy** (Nginx/Apache) with SSL/TLS
2. **Enable authentication**: Configure Laravel auth guard
3. **Firewall rules**: Restrict access to known IPs
4. **Rate limiting**: Prevent abuse
5. **Monitoring**: Log all MCP operations

See [MCP Server Integration](../guides/mcp-server-integration.md) for production deployment guide.

### Do MCP tools bypass the HTTP API?

No. MCP tools are a thin wrapper around the HTTP API. They:
- Use the same services and validation
- Respect the same policies and guards
- Generate the same events
- Have the same idempotency guarantees

---

## Production & Performance

### How many work orders can this handle?

**Depends on your infrastructure**, but tested at:

- **Database backend**: 100-500 operations/second per server
- **Redis backend**: 1,000-5,000 operations/second per server

With horizontal scaling (multiple servers + Redis), much higher.

### How do I monitor Work Manager in production?

**Built-in options:**

1. **Laravel Events**: Subscribe to all lifecycle events
2. **Database queries**: Query work_orders, work_items, work_events tables
3. **Logs**: Configure log driver in metrics config

**Recommended integrations:**

```php
// config/work-manager.php
'metrics' => [
    'enabled' => true,
    'driver' => 'log', // 'log' or 'null' available (prometheus/statsd planned)
    'namespace' => 'work_manager',
],
```

Monitor:
- Orders created per type
- Items leased/submitted/completed
- Lease expirations
- Approval/rejection rates
- Apply duration
- Queue depths

### What happens during deployment?

**Safe deployment:**

1. Active leases continue (agents hold leases)
2. Heartbeats keep leases alive
3. New version processes new checkouts
4. Expired leases reclaimed by new maintenance process

**No downtime required** for most deployments.

**Best practice**: Use `php artisan down` if making breaking changes to order types.

### How do I handle database migrations?

**Order types are versioned** through the `type()` identifier:

```php
// Old version (still processing)
public function type(): string
{
    return 'user.sync.v1';
}

// New version (new orders)
public function type(): string
{
    return 'user.sync.v2';
}
```

Both can coexist. Let v1 orders drain, then remove old type.

### Should I use queues for work-manager:maintain?

**No.** Run `work-manager:maintain` directly via cron/scheduler:

```php
$schedule->command('work-manager:maintain')->everyMinute();
```

It's designed to run frequently and complete quickly.

### How do I backup work order data?

**Important tables:**

```bash
# Full backup
mysqldump your_db work_orders work_items work_events work_provenance work_idempotency_keys > backup.sql

# Events only (for audit trail)
mysqldump your_db work_events > events_backup.sql
```

Retention strategy:
- Keep `work_orders`/`work_items` for operational period (30-90 days)
- Archive `work_events` for compliance (1-7 years)
- Prune `work_idempotency_keys` after expiration (7-30 days)

---

## Integration

### Can I use this with existing Laravel applications?

**Yes.** Work Manager is designed for gradual adoption:

1. **Start small**: Create one order type for new functionality
2. **Protect mutations**: Add `EnforceWorkOrderOnly` middleware to critical routes
3. **Migrate gradually**: Convert legacy operations to order types over time

### How do I integrate with my authorization system?

**Laravel Policies:**

```php
// app/Policies/WorkOrderPolicy.php
public function propose(User $user): bool
{
    return $user->hasPermission('work.propose');
}

public function approve(User $user, WorkOrder $order): bool
{
    return $user->hasRole('admin') || $user->id === $order->created_by;
}
```

**Configure in config:**
```php
'policies' => [
    'propose' => 'work.propose',
    'approve' => 'work.approve',
],
```

### Can I trigger Laravel events from order types?

**Yes.**

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    event(new UserDataSynced($order));
    event(new SystemUpdated($diff));
}
```

Or use built-in events:
```php
use GregPriday\WorkManager\Events\WorkOrderApplied;

Event::listen(WorkOrderApplied::class, function ($event) {
    // React to any order being applied
});
```

### Can I use this with Laravel Horizon?

**Yes.** Work Manager uses standard Laravel queues:

```php
// config/work-manager.php
'queues' => [
    'connection' => 'redis', // Horizon uses Redis
    'maintenance_queue' => 'work:maintenance',
],
```

View queues in Horizon dashboard.

### Does this work with Laravel Octane?

**Yes.** Work Manager is stateless and Octane-compatible. Considerations:

1. **Database leases**: Work fine with Octane
2. **Redis leases**: Recommended with Octane for best performance
3. **Memory**: Clear state in lifecycle hooks if needed
4. **Singletons**: Registry is a singleton (safe across requests)

---

## More Questions?

- Check [Common Errors](common-errors.md) for troubleshooting
- Review [Known Limitations](known-limitations.md) for edge cases
- Search [GitHub issues](https://github.com/gregpriday/laravel-work-manager/issues)
- Read [ARCHITECTURE.md](../concepts/architecture-overview.md) for system design
- Check examples in `examples/` directory
