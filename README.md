# Laravel Work Manager

**AI-agent oriented work order control plane for Laravel.**

This package gives you a framework-native way to create, lease, validate, approve, and apply **typed work orders**—with strong guarantees around **state**, **idempotency**, **auditability**, and **agent ergonomics**. It ships with a built-in **MCP (Model Context Protocol) server** for AI agents, a thin HTTP API you can mount under any namespace, scheduled commands for auto-generating work, and clean extension points for custom types, validation, and execution.

> **Built-in MCP server:** Connect AI agents (Claude/Claude Code, Cursor, etc.) via the MCP protocol to automatically discover tools, check out work, submit results, and poll decisions. The HTTP API remains available for non-MCP clients and custom integrations.

---

## Why this exists

Modern AI systems do non-trivial backend work—research, enrichment, migrations, data syncs—often performed by external agents. You need:

* A single, auditable path for **all mutations** (no side doors).
* Safe, concurrent **leasing** with TTL + heartbeat.
* **Typed** work with per-type schemas, validators, and idempotent **apply()** logic.
* **Idempotency** and clear retry semantics.
* Easy agent UX via **checkout → heartbeat → submit → approve/apply**.
* Strong **events/provenance/diffs** for observability and compliance.

Laravel Work Manager provides exactly that.

---

## What you get

* **Typed Work Orders**: per-type schema + planning + acceptance policy + apply hooks. (`Contracts\OrderType`, `Support\AbstractOrderType`)
* **State machine**: enforced order/item lifecycle + events. (`Services\StateMachine`, `Support\Enums`)
* **Leasing & Concurrency**: single checkout, TTL, heartbeat, reclaim, max attempts. (`Services\LeaseService`)
* **Idempotency**: header-based dedupe + stored responses. (`Services\IdempotencyService`)
* **HTTP API**: mountable controller with propose/checkout/heartbeat/submit/approve/reject/logs. (`Http\Controllers\WorkOrderApiController`)
* **Scheduled commands**: generator & maintenance. (`work-manager:generate`, `work-manager:maintain`)
* **"Work-order-only" enforcement**: middleware to block direct mutations. (`Http\Middleware\EnforceWorkOrderOnly`)
* **Auditability**: `WorkEvent`, `WorkProvenance`, and structured `Diff`.
* **Examples & docs**: full examples for DB inserts and user data sync; architecture + lifecycle docs. (`examples/`, `ARCHITECTURE.md`)

---

## Installation

```bash
composer require gregpriday/laravel-work-manager
php artisan vendor:publish --tag=work-manager-config
php artisan vendor:publish --tag=work-manager-migrations
php artisan migrate
```

**Requirements:** PHP 8.2+, Laravel 11 or 12, MySQL 8+ or Postgres 13+.

---

## Quick start (5 steps)

### 1) Register routes (choose your namespace)

```php
// routes/api.php
use GregPriday\WorkManager\Facades\WorkManager;

// Mount all endpoints under /ai/work with your own middleware/guard.
// Note: Config default is 'agent/work', but you can override with basePath parameter.
WorkManager::routes(basePath: 'ai/work', middleware: ['api', 'auth:sanctum']);
```

Or wire them manually to pick endpoints individually.

### 2) Define an order type

```php
// app/WorkTypes/UserDataSyncType.php
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\Diff;

final class UserDataSyncType extends AbstractOrderType
{
    public function type(): string
    {
        return 'user.data.sync';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source', 'user_ids'],
            'properties' => [
                'source' => ['type' => 'string', 'enum' => ['crm', 'analytics']],
                'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ];
    }

    // Laravel validation for agent submissions
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'synced_users' => 'required|array',
            'synced_users.*.user_id' => 'required|integer',
            'synced_users.*.verified' => 'required|boolean|accepted',
        ];
    }

    // Custom verification logic
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify all users in batch were processed
        $expectedIds = $item->input['user_ids'];
        $syncedIds = array_column($result['synced_users'], 'user_id');

        if (count(array_diff($expectedIds, $syncedIds)) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'synced_users' => ['Not all users in batch were synced'],
            ]);
        }
    }

    // Idempotent execution with database operations
    public function apply(WorkOrder $order): Diff
    {
        $updatedCount = 0;

        DB::transaction(function () use ($order, &$updatedCount) {
            foreach ($order->items as $item) {
                foreach ($item->result['synced_users'] as $syncedUser) {
                    $user = User::find($syncedUser['user_id']);
                    if ($user) {
                        $user->update($syncedUser['data']);
                        $updatedCount++;
                    }
                }
            }
        });

        return $this->makeDiff(
            ['updated_count' => 0],
            ['updated_count' => $updatedCount],
            "Synced data for {$updatedCount} users"
        );
    }

    // Post-execution cleanup
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        Cache::tags(['users'])->flush();
    }
}
```

This uses `AbstractOrderType` which provides:
- Default acceptance policy using Laravel validation
- Lifecycle hooks: `beforeApply()`, `afterApply()`
- Verification hooks: `submissionValidationRules()`, `afterValidateSubmission()`, `canApprove()`
- Helper methods: `makeDiff()`, `emptyDiff()`

### 3) Register your type

```php
// app/Providers/AppServiceProvider.php
use GregPriday\WorkManager\Facades\WorkManager;
use App\WorkTypes\UserDataSyncType;

public function boot()
{
    WorkManager::registry()->register(new UserDataSyncType());
}
```

### 4) Schedule jobs

```php
// app/Console/Kernel.php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
$schedule->command('work-manager:maintain')->everyMinute();
```

### 5) Call the API (as an agent)

```bash
# Propose
curl -X POST /api/ai/work/propose \
  -H "Authorization: Bearer <token>" \
  -H "X-Idempotency-Key: propose-1" \
  -d '{"type":"user.data.sync","payload":{"source":"crm","user_ids":[1,2,3]}}'

# Checkout → heartbeat → submit → approve
curl -X POST /api/ai/work/orders/{order}/checkout -H "X-Agent-ID: agent-1"
curl -X POST /api/ai/work/items/{item}/heartbeat -H "X-Agent-ID: agent-1"
curl -X POST /api/ai/work/items/{item}/submit \
  -H "X-Idempotency-Key: submit-1" \
  -d '{"result":{"success":true,"synced_users":[...],"verified":true}}'
curl -X POST /api/ai/work/orders/{order}/approve -H "X-Idempotency-Key: approve-1"
```

---

## Core concepts

### Work Order & Work Item

* `WorkOrder`: the high-level contract (type, payload, state, provenance).
* `WorkItem`: the unit an agent leases, heartbeats, and submits.

Eloquent models live under `src/Models` with enum casts for state.

### Types & Lifecycle Hooks

**OrderType** defines the complete lifecycle of a work type:

```php
// What is this work?
public function type(): string

// What data is required?
public function schema(): array

// How to break into items?
public function plan(WorkOrder $order): array

// Verification hooks (using AbstractOrderType):
protected function submissionValidationRules(WorkItem $item): array
protected function afterValidateSubmission(WorkItem $item, array $result): void
protected function canApprove(WorkOrder $order): bool

// Execution hooks:
protected function beforeApply(WorkOrder $order): void
public function apply(WorkOrder $order): Diff  // Idempotent!
protected function afterApply(WorkOrder $order, Diff $diff): void
```

**AbstractOrderType** provides default implementations and hooks for:
- Laravel validation integration
- Custom verification logic
- Approval readiness checks
- Before/after execution hooks

**AbstractAcceptancePolicy** for teams that prefer validation separate from the type class.

See `examples/LIFECYCLE.md` for complete hook documentation.

### State machine

Strict transitions are enforced for orders and items:

```
queued → checked_out → in_progress → submitted → approved → applied → completed
```

Failed/rejected paths also supported. Events are written for every transition.

### Leasing

Single checkout per item, TTL + heartbeat. Expired leases are reclaimed by maintenance; items either re-queue or fail on max attempts.

### Idempotency

Provide `X-Idempotency-Key` for propose/submit/approve/reject. The package stores key hashes and cached responses to make agent retries safe.

### Verification (Two-Phase)

1. **Agent Submission**: Laravel validation rules + custom business logic
2. **Approval Readiness**: Cross-item validation before execution (implemented by `canApprove()` in your acceptance policy)

### "Work-order-only" enforcement

Attach `EnforceWorkOrderOnly` middleware to any mutating endpoint in your app to ensure all writes flow through a valid work order (e.g., state `approved|applied`).

### Partial Submissions

For complex work items (e.g., research tasks, multi-step processes), agents can submit results incrementally rather than all at once:

* `POST /items/{item}/submit-part` — Submit an incremental part (validated independently)
* `POST /items/{item}/finalize` — Assemble all validated parts into final result

Enable in config: `'partials.enabled' => true` (enabled by default).

**Benefits:**
- Handle large/complex work without timeout issues
- Validate results incrementally as they're produced
- Resume work across sessions
- Track progress for long-running tasks

Each part is independently validated and stored. Once all parts are submitted, call `finalize` to assemble them into the final work item result. See `examples/CustomerResearchPartialType.php` for implementation details.

---

## HTTP API overview

Mount under any prefix (e.g., `/ai/work`), then:

**Note on route prefixes**: If you mount routes in `routes/api.php`, Laravel automatically prefixes them with `/api`, so `/ai/work/*` becomes `/api/ai/work/*`.

* `POST /propose` — create a work order (requires `type`, `payload`)
* `GET /orders` / `GET /orders/{id}` — list/show orders
* `POST /orders/{order}/checkout` — lease next available item
* `POST /items/{item}/heartbeat` — extend lease
* `POST /items/{item}/submit` — submit complete results (validated)
* `POST /items/{item}/submit-part` — submit partial result (for incremental work)
* `POST /items/{item}/finalize` — finalize work item by assembling all parts
* `POST /orders/{order}/approve` — approve & apply (writes diffs/events)
* `POST /orders/{order}/reject` — reject (optionally re-queue for rework)
* `POST /items/{item}/release` — explicitly release lease
* `GET /items/{item}/logs` — recent events/diffs

All implemented in `WorkOrderApiController`.

**Auth/guard**: configure in `config/work-manager.php` (`routes.guard`, default `sanctum`).

**Idempotency header**: `X-Idempotency-Key` (configurable).

---

## Scheduled automation

* `work-manager:generate` — runs your registered **AllocatorStrategy/PlannerPort** implementations to create new orders (e.g., "scan for stale data → create sync orders")
* `work-manager:maintain` — reclaims expired leases, dead-letters stuck work, and alerts on stale orders

Wire them in your scheduler; see `Console/*` for options.

---

## MCP Server (Recommended for AI Agents)

The package includes a **built-in MCP (Model Context Protocol) server** for AI agents to interact with the work order system. This is the **recommended integration method** for AI IDEs and agents.

### Quick Start

**Local mode (for Cursor, Claude Desktop, etc.):**
```bash
php artisan work-manager:mcp --transport=stdio
```

**HTTP mode (for remote agents/production):**
```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

### Available MCP Tools

The server exposes 13 tools that map 1:1 to Work Manager operations:

* `work.propose` — Create new work orders
* `work.list` — List orders with filtering
* `work.get` — Get order details
* `work.checkout` — Lease work items
* `work.heartbeat` — Maintain leases
* `work.submit` — Submit complete results
* `work.submit_part` — Submit partial results (for incremental work)
* `work.list_parts` — List all parts for a work item
* `work.finalize` — Finalize work item by assembling parts
* `work.approve` — Approve and apply orders
* `work.reject` — Reject orders
* `work.release` — Release leases
* `work.logs` — View event history

### Integration Examples

**Cursor IDE** - Add to `.cursorrules`:
```json
{
  "mcp": {
    "servers": {
      "work-manager": {
        "command": "php",
        "args": ["artisan", "work-manager:mcp", "--transport=stdio"],
        "cwd": "/path/to/your/laravel/app"
      }
    }
  }
}
```

**Claude Desktop** - Add to config:
```json
{
  "mcpServers": {
    "work-manager": {
      "command": "php",
      "args": ["/path/to/app/artisan", "work-manager:mcp"],
      "env": { "APP_ENV": "local" }
    }
  }
}
```

See [MCP_SERVER.md](docs/MCP_SERVER.md) for complete documentation including production deployment, security, and troubleshooting.

---

## Configuration

Publish and edit `config/work-manager.php`. Key sections:

- **Routes**: base path, middleware, guard
- **Lease**: TTL, heartbeat intervals, backend (database or redis)
- **Retry**: max attempts, backoff, jitter
- **Idempotency**: header name & enforced endpoints
- **Partials**: enable/disable partial submissions, max parts per item, payload size limits
- **State Machine**: allowed transitions
- **Queues**: queue connections and names
- **Metrics**: driver (log, prometheus, statsd) and namespace
- **Policies**: map abilities to gates/permissions
- **Maintenance**: thresholds for dead-lettering and alerts

---

## Laravel Integration

### Laravel Validation

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|exists:users,id',
        'email' => 'required|email|unique:users',
        'data' => 'required|array',
    ];
}
```

### Laravel Events

Subscribe to lifecycle events:

```php
use GregPriday\WorkManager\Events\WorkOrderApplied;

Event::listen(WorkOrderApplied::class, function($event) {
    Log::info('Order applied', [
        'order_id' => $event->order->id,
        'diff' => $event->diff->toArray(),
    ]);
});
```

Available events:
- `WorkOrderProposed`, `WorkOrderPlanned`, `WorkOrderCheckedOut`, `WorkOrderApproved`, `WorkOrderApplied`, `WorkOrderCompleted`, `WorkOrderRejected`
- `WorkItemLeased`, `WorkItemHeartbeat`, `WorkItemSubmitted`, `WorkItemFailed`, `WorkItemLeaseExpired`, `WorkItemFinalized`
- `WorkItemPartSubmitted`, `WorkItemPartValidated`, `WorkItemPartRejected`

### Laravel Jobs/Queues

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    ProcessData::dispatch($order)->onQueue('work');
    SendNotifications::dispatch($diff)->onQueue('notifications');
}
```

### Database Operations

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            // Insert/update records
            Model::create($item->result['data']);
        }

        return $this->makeDiff($before, $after);
    });
}
```

---

## Examples

* **Database inserts**: `examples/DatabaseRecordInsertType.php` — batch inserts + verification + idempotent apply
* **User data sync**: `examples/UserDataSyncType.php` — external API sync with per-batch items
* **Quick start**: [Quickstart Guide](docs/getting-started/quickstart.md) — 5-minute getting started guide
* **Lifecycle walk-through**: `examples/LIFECYCLE.md` — every hook and event documented
* **Architecture**: [Architecture Overview](docs/concepts/architecture-overview.md) — system design and data flows

---

## Security & compliance

* Require auth on all mounted routes (default `auth:sanctum`)
* Use **idempotency keys** for all mutating calls from agents
* Attach **EnforceWorkOrderOnly** to any legacy mutation routes to prevent side-door writes
* Record provenance: agent name/version, request fingerprints
* Emit/ship events/diffs to your SIEM/observability stack

---

## Testing

Pest/PHPUnit setup is included. Add feature tests for your custom types covering:
- Proposal → checkout → heartbeat → submit → approve/apply
- Rejection and resubmission paths
- Lease expiration and retry logic
- Idempotency behavior

```bash
composer test

# Run with coverage
vendor/bin/pest --coverage
```

**Note**: Some edge case tests are currently skipped pending investigation (e.g., lease conflict detection, order readiness checks). These are marked with `markTestSkipped` and do not affect core functionality.

---

## Roadmap

* ✅ Core models, leases, state machine, idempotency, controller & commands
* ✅ Abstract base classes (`AbstractOrderType`, `AbstractAcceptancePolicy`)
* ✅ Complete lifecycle hooks with Laravel validation integration
* ✅ Comprehensive examples and documentation
* ✅ **MCP server** with stdio and HTTP transports
* ✅ **Partial submissions** for incremental work item results
* ✅ **Optional Redis lease backend** (see config: `'lease.backend' => 'redis'`)
* 🔜 Prometheus metrics driver (log and null drivers available)
* 🔜 OpenAPI docs generator for mounted routes

---

## Documentation

Laravel Work Manager includes comprehensive, production-ready documentation covering all aspects of the package.

### 📚 Complete Documentation

**[→ View Complete Documentation](docs/index.md)** | **[→ Documentation Index](docs/documentation-index.md)**

The documentation is organized into the following sections:

- **[Getting Started](docs/getting-started/introduction.md)** - Introduction, requirements, installation, and quickstart guide
- **[Concepts](docs/concepts/what-it-does.md)** - Core architecture, lifecycle, state management, and security
- **[Guides](docs/guides/creating-order-types.md)** - Practical how-to guides for building and deploying
- **[Examples](docs/examples/overview.md)** - Real-world implementations with complete working code
- **[Reference](docs/reference/api-surface.md)** - Complete API, configuration, routes, events, and schema reference
- **[Troubleshooting](docs/troubleshooting/common-errors.md)** - Common errors, FAQ, and known limitations
- **[Contributing](docs/meta/contributing.md)** - How to contribute, security policy, and community support

### 🚀 Quick Start Path

New to Laravel Work Manager? Follow this learning path:

1. **[Introduction](docs/getting-started/introduction.md)** - Understand what it does and why you need it
2. **[Installation](docs/getting-started/installation.md)** - Install and configure the package
3. **[Quickstart Guide](docs/getting-started/quickstart.md)** - Build your first order type in 5 minutes
4. **[Basic Usage Example](docs/examples/basic-usage.md)** - See a complete working example

### 📖 Popular Topics

- **[Creating Order Types](docs/guides/creating-order-types.md)** - Complete guide to building custom order types
- **[MCP Server Integration](docs/guides/mcp-server-integration.md)** - Connect AI agents via Model Context Protocol
- **[HTTP API Reference](docs/guides/http-api.md)** - Complete REST API documentation
- **[Partial Submissions](docs/guides/partial-submissions.md)** - Incremental work submission for complex tasks
- **[Events & Listeners](docs/guides/events-and-listeners.md)** - React to lifecycle events
- **[Deployment Guide](docs/guides/deployment-and-production.md)** - Production deployment and scaling

### 📦 Legacy Documentation

The following files contain legacy documentation (will be archived):
- `ARCHITECTURE.md` → Migrated to [Architecture Overview](docs/concepts/architecture-overview.md)
- `docs/MCP_SERVER.md` → Migrated to [MCP Server Integration](docs/guides/mcp-server-integration.md)
- `docs/USE_CASES.md` → Content distributed across [Examples](docs/examples/overview.md)
- `examples/QUICK_START.md` → Migrated to [Quickstart Guide](docs/getting-started/quickstart.md)
- `examples/LIFECYCLE.md` → Migrated to [Lifecycle & Flow](docs/concepts/lifecycle-and-flow.md)

For questions, issues, or feature requests, visit the [GitHub issue tracker](https://github.com/gregpriday/laravel-work-manager/issues).

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](docs/meta/contributing.md) for:
- How to report bugs
- How to suggest features
- Development setup
- Running tests
- Coding standards
- Pull request process

**Key components** (see `ARCHITECTURE.md` for system design):
* `src/Http/Controllers/WorkOrderApiController.php` — API endpoints
* `src/Services/{WorkAllocator,WorkExecutor,LeaseService,IdempotencyService,StateMachine}` — core services
* `src/Support/{AbstractOrderType,AbstractAcceptancePolicy,Enums,Diff,Helpers}` — primitives & base classes
* `src/Models/{WorkOrder,WorkItem,WorkEvent,WorkProvenance,WorkIdempotencyKey}` — Eloquent models
* `src/Console/{GenerateCommand,MaintainCommand}` — scheduler commands

---

## License

MIT © Greg Priday. See `LICENSE.md`.

---

## Support

**Need help?**
- **Documentation**: Start with the [FAQ](docs/troubleshooting/faq.md) and [Common Errors](docs/troubleshooting/common-errors.md)
- **Issues**: Report bugs or request features on [GitHub](https://github.com/gregpriday/laravel-work-manager/issues)
- **Security**: Report vulnerabilities via email (see [Security Policy](docs/meta/security-policy.md))
- **Commercial Support**: Contact greg@siteorigin.com for consulting and priority support

See [Support and Community](docs/meta/support-and-community.md) for more resources.

---

*This README reflects the current package structure with 52+ PHP files implementing a complete work order control plane with lifecycle hooks, Laravel integration, and comprehensive documentation.*
