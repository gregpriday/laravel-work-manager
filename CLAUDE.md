# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Environment Setup (AI Agents: Read This First!)

**If you're working in an environment that hasn't been set up for running tests**, you must first set up PHP, Composer, Xdebug, and other dependencies.

Before proceeding with any development work:

1. **Check if environment is ready**: Run `php --version` and `composer --version`
2. **If commands are not found or tests fail**: Follow the complete setup guide at **`docs/dev/setup.md`**
3. **Supported Platform**: Ubuntu/Debian-based systems (tested on Ubuntu 24.04)

The setup guide (`docs/dev/setup.md`) provides step-by-step instructions to install:
- PHP 8.3 with all required extensions (mbstring, xml, curl, zip, mysql, sqlite3, intl)
- Composer 2.7.x for dependency management
- Xdebug 3.2.x with code coverage enabled
- Project dependencies via `composer install`
- PHPUnit configuration

**Quick verification**: After setup, run `composer test` to ensure all 300+ tests pass.

## Project Overview

Laravel Work Manager is a Laravel package providing an AI-agent oriented work order control plane. It enables AI agents to propose work, lease work items with TTL-based concurrency control, submit results with validation, and apply changes idempotently.

**Work In Progres**: This project is still a work in progress. We can make changes without worrying about backwards compatibility or legacy support. Make sure to remove this note before shipping the final release.

**Key Architecture**: This is a stateful work order system with strict state machine transitions, two-phase verification (submission validation + approval readiness), and comprehensive auditability through events/provenance/diffs.

## Testing Commands

```bash
# Run all tests
composer test

# Run specific test
vendor/bin/pest tests/ExampleTest.php

# Run with coverage
vendor/bin/pest --coverage
```

## Package Structure

This is a Laravel package with the following namespace: `GregPriday\WorkManager`

**Core Service Layer** (`src/Services/`):
- `WorkAllocator` - Handles propose() and plan() operations
- `WorkExecutor` - Handles submit(), approve(), reject(), apply() operations
- `LeaseService` - Manages acquire(), extend(), release(), reclaim() for work item leases
- `StateMachine` - Enforces state transitions and records events
- `IdempotencyService` - Guards against duplicate operations via header-based deduplication
- `Registry/OrderTypeRegistry` - Manages registered order type implementations

**Data Models** (`src/Models/`):
- `WorkOrder` - High-level contract (type, payload, state, priority, meta)
- `WorkItem` - Unit of work that agents lease and process (state, input, result, lease info)
- `WorkItemPart` - Partial submission for incremental work item results
- `WorkEvent` - Audit trail of all state changes and actions
- `WorkProvenance` - Agent metadata and request fingerprints
- `WorkIdempotencyKey` - Stored idempotency keys with cached responses

**Order Type System** (`src/Support/`, `src/Contracts/`):
- `AbstractOrderType` - Base class for custom order types with validation hooks
- `AbstractAcceptancePolicy` - Optional separate validation class
- `OrderType` interface - Defines schema(), plan(), apply()
- `AcceptancePolicy` interface - Defines validateSubmission(), readyForApproval()

**HTTP Layer** (`src/Http/`):
- `Controllers/WorkOrderApiController` - All REST endpoints (propose, checkout, heartbeat, submit, submit-part, finalize, approve, reject, release, logs)
- `Middleware/EnforceWorkOrderOnly` - Prevents direct mutations outside work orders

**MCP Integration** (`src/Mcp/`, `src/Console/McpCommand.php`):
- `WorkManagerTools` - Exposes 13 MCP tools for AI agent integration (including partial submission support)
- Supports both STDIO (local AI IDEs) and HTTP (remote agents) transports
- See `docs/guides/mcp-server-integration.md` for complete MCP documentation

**Query & Filtering** (`src/Support/WorkOrderQuery.php`):
- Spatie Query Builder integration for powerful filtering, sorting, and field selection
- Supports both HTTP API and MCP tool queries with unified interface
- See `docs/guides/filtering-orders.md` and `docs/examples/orders-filtering.md` for details

## State Machine

**WorkOrder states**: `queued → checked_out → in_progress → submitted → approved → applied → completed`

**WorkItem states**: `queued → leased → in_progress → submitted → accepted → completed`

Failed/rejected states: Both can transition to `rejected`, `failed`, or `dead_lettered` based on circumstances.

**Critical**: State transitions are strictly enforced in `config/work-manager.php`. The `StateMachine` service validates all transitions and records events automatically.

## Working with Order Types

Order types define the complete lifecycle of a work category. When creating or modifying order types:

1. **Extend `AbstractOrderType`** (provides Laravel validation integration and lifecycle hooks)
2. **Required methods**:
   - `type()` - Returns string identifier (e.g., "user.data.sync")
   - `schema()` - Returns JSON schema array for payload validation
   - `apply(WorkOrder $order): Diff` - Idempotent execution that performs actual changes

3. **Auto-Approval** (optional):
   - Set `protected bool $autoApprove = true` to enable automatic approval when ready
   - Default is `false` (requires manual approval)
   - Useful for deterministic, safe operations that don't require human review

4. **Validation hooks** (for agent submissions):
   - `submissionValidationRules(WorkItem $item): array` - Laravel validation rules
   - `afterValidateSubmission(WorkItem $item, array $result): void` - Custom business logic checks
   - `canApprove(WorkOrder $order): bool` - Cross-item validation before approval

5. **Lifecycle hooks**:
   - `beforeApply(WorkOrder $order): void` - Pre-execution setup
   - `afterApply(WorkOrder $order, Diff $diff): void` - Post-execution cleanup (e.g., dispatch jobs, clear caches)

6. **Planning**: Override `plan(WorkOrder $order): array` to customize how orders are broken into work items

7. **Registration**: Register in `AppServiceProvider::boot()` via `WorkManager::registry()->register(new YourType())`

**Important**: The `apply()` method MUST be idempotent. It may be called multiple times for the same order. Always use database transactions and check for existing state.

## Database Operations in apply()

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        // Your mutations here
        foreach ($order->items as $item) {
            Model::updateOrCreate(
                ['id' => $item->result['id']],
                $item->result['data']
            );
        }

        return $this->makeDiff($before, $after, 'Description');
    });
}
```

## Leasing System

- Leases are TTL-based (default 600s, configurable in `config/work-manager.php`)
- Agents must heartbeat every 120s (configurable)
- **Backend options**:
  - `'database'` (default) — Row-level locks on work_items table
  - `'redis'` — Redis SET NX EX pattern for better performance and scalability
- Expired leases are automatically reclaimed by `work-manager:maintain` command
- Single agent per work item (prevents concurrent processing)
- Max retry attempts configurable per item
- Optional concurrency limits per agent and per type

## Idempotency

Always use `X-Idempotency-Key` header (configurable) for:
- propose
- submit
- submit-part
- finalize
- approve
- reject

The system stores key hashes and caches responses. Retries with same key return cached response.

## Partial Submissions

For complex work items (e.g., research tasks, multi-step processes), agents can submit results incrementally:

**Key endpoints**:
- `POST /items/{item}/submit-part` — Submit an incremental part with validation
- `GET /items/{item}/parts` — List all submitted parts
- `POST /items/{item}/finalize` — Assemble all validated parts into final result

**Configuration**: Enable/disable and set limits in `config/work-manager.php` under `'partials'`:
- `'enabled' => true` — Enable partial submissions (default)
- `'max_parts_per_item' => 100` — Maximum parts per work item
- `'max_payload_bytes' => 1048576` — Maximum payload size per part (1MB default)

**Models**: `WorkItemPart` stores each partial submission with status (`submitted`, `validated`, `rejected`), checksum, and agent metadata.

**Events**: `WorkItemPartSubmitted`, `WorkItemPartValidated`, `WorkItemPartRejected`, `WorkItemFinalized`

**Use cases**: Large research tasks, multi-step data collection, resumable work across sessions.

## Configuration

Key configuration in `config/work-manager.php`:
- `routes.*` - Route registration, middleware, auth guard
- `lease.*` - TTL, heartbeat intervals, backend ('database' or 'redis'), concurrency limits
- `retry.*` - Max attempts, backoff, jitter
- `idempotency.*` - Header name, enforced endpoints (includes 'submit-part' and 'finalize')
- `partials.*` - Enable/disable, max parts per item, payload size limits
- `state_machine.*` - Allowed transitions (rarely modified)
- `queues.*` - Queue connections for background jobs
- `metrics.*` - Driver ('log', 'prometheus', 'statsd'), namespace
- `maintenance.*` - Dead-letter thresholds, alerts
- `query.*` - Pagination defaults and limits for HTTP/MCP queries
- `mcp.*` - MCP server HTTP authentication and CORS settings

See `docs/guides/configuration.md` and `docs/reference/config-reference.md` for complete configuration documentation.

## Scheduled Commands

```bash
# Generate new work orders (runs your custom AllocatorStrategy implementations)
php artisan work-manager:generate

# Reclaim expired leases, dead-letter stuck work, alert on stale orders
php artisan work-manager:maintain
```

Schedule in `app/Console/Kernel.php`:
```php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
$schedule->command('work-manager:maintain')->everyMinute();
```

## MCP Server Usage

**Start MCP server for AI agents**:
```bash
# Local mode (for Cursor, Claude Desktop, etc.)
php artisan work-manager:mcp --transport=stdio

# HTTP mode (for remote agents/production)
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

**HTTP Mode Authentication** (optional, recommended for production):

Enable Bearer token authentication:
```env
WORK_MANAGER_MCP_HTTP_AUTH=true
WORK_MANAGER_MCP_AUTH_GUARD=sanctum  # Or any Laravel guard
WORK_MANAGER_MCP_STATIC_TOKENS=token1,token2  # For dev/testing
```

Clients must send `Authorization: Bearer <token>` header when auth is enabled.

**Available MCP tools**: work.propose, work.list, work.get, work.checkout, work.heartbeat, work.submit, work.submit_part, work.list_parts, work.finalize, work.approve, work.reject, work.release, work.logs

See `docs/guides/mcp-server-integration.md` for integration examples and production deployment.

## Events

Subscribe to Laravel events for observability:
- `WorkOrderProposed`, `WorkOrderPlanned`, `WorkOrderApproved`, `WorkOrderApplied`, `WorkOrderCompleted`, `WorkOrderRejected`
- `WorkItemLeased`, `WorkItemHeartbeat`, `WorkItemSubmitted`, `WorkItemFailed`, `WorkItemLeaseExpired`, `WorkItemFinalized`
- `WorkItemPartSubmitted`, `WorkItemPartValidated`, `WorkItemPartRejected`

All events carry relevant model instances and metadata.

## Common Patterns

**Route registration** (in `routes/api.php` or service provider):
```php
// Config default is 'agent/work', but override with basePath as needed
WorkManager::routes(basePath: 'agent/work', middleware: ['api', 'auth:sanctum']);
```

**Enforce work-order-only mutations** (apply to legacy routes):
```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);
```

**Dispatch follow-up jobs after apply**:
```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    ProcessData::dispatch($order)->onQueue('work');
    Cache::tags(['users'])->flush();
}
```

## Examples & Practical Guides

**Quick Start**:
- `docs/getting-started/introduction.md` - What the package does and why it exists
- `docs/getting-started/quickstart.md` - Build your first order type in 5 minutes
- `docs/getting-started/installation.md` - Installation and setup

**Example Implementations** (all in `docs/examples/`):
- `docs/examples/overview.md` - Overview of all examples with common patterns
- `docs/examples/basic-usage.md` - Minimal viable order type
- `docs/examples/database-record-insert.md` - Batch database operations with verification
- `docs/examples/user-data-sync.md` - External API sync with per-batch items
- `docs/examples/customer-research-partial.md` - Advanced partial submissions workflow
- `docs/examples/content-fact-check.md` - Content verification with evidence tracking
- `docs/examples/city-tier-generation.md` - Multi-dimensional data classification
- `docs/examples/orders-filtering.md` - Query Builder filtering examples

**Lifecycle & Hooks**:
- `docs/concepts/lifecycle-and-flow.md` - Complete lifecycle documentation with all hooks and phases
- `docs/guides/creating-order-types.md` - Comprehensive guide to building custom order types
- `docs/guides/validation-and-acceptance-policies.md` - Validation patterns and custom policies

## Key Design Principles

1. **Idempotency First**: All mutations must be idempotent and replayable
2. **State Machine Enforcement**: Never bypass state transitions
3. **Two-Phase Verification**: Validate on submit (agent work) + approve (system readiness)
4. **Auditability**: Every action generates events with provenance
5. **Lease-Based Concurrency**: Prevent race conditions via TTL leases with heartbeats
6. **Type Safety**: JSON schemas for payloads, Laravel validation for submissions

## Documentation Structure

**Documentation Index**: `docs/index.md` - Complete navigation to all documentation

**Core Concepts**:
- `docs/concepts/what-it-does.md` - Problem overview and solution approach
- `docs/concepts/architecture-overview.md` - System design, data flows, integration points
- `docs/concepts/lifecycle-and-flow.md` - Complete lifecycle with all hooks and events
- `docs/concepts/state-management.md` - State machine and transition rules
- `docs/concepts/security-and-permissions.md` - Authorization and security model

**Essential Guides**:
- `docs/guides/http-api.md` - Complete HTTP API reference with all endpoints
- `docs/guides/mcp-server-integration.md` - MCP server setup and AI agent integration
- `docs/guides/creating-order-types.md` - Building custom order types
- `docs/guides/filtering-orders.md` - Query Builder filtering system
- `docs/guides/partial-submissions.md` - Incremental submission workflows
- `docs/guides/testing.md` - Testing patterns for order types
- `docs/guides/deployment-and-production.md` - Production deployment guide

**Reference**:
- `docs/reference/config-reference.md` - All configuration options explained
- `docs/reference/routes-reference.md` - All HTTP routes and parameters
- `docs/reference/events-reference.md` - All lifecycle events
- `docs/reference/commands-reference.md` - All artisan commands
- `docs/reference/database-schema.md` - Database tables and columns
- `docs/reference/api-surface.md` - Complete API index

**Other Resources**:
- `README.md` - Package overview and quick start
- `LICENSE.md` - MIT license details
- `docs/troubleshooting/faq.md` - Frequently asked questions
- `docs/troubleshooting/common-errors.md` - Common errors and solutions
