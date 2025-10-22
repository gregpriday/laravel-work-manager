# Introduction

## What is Laravel Work Manager?

Laravel Work Manager is an AI-agent oriented work order control plane for Laravel applications. It provides a framework-native way to create, lease, validate, approve, and apply **typed work orders**—with strong guarantees around **state management**, **idempotency**, **auditability**, and **agent ergonomics**.

## The Problem

Modern AI systems perform non-trivial backend work—research, enrichment, migrations, data syncs—often performed by external agents. Traditional approaches lead to:

- **Side-door mutations**: Direct database writes that bypass validation and audit trails
- **Race conditions**: Multiple agents processing the same work simultaneously
- **Lost work**: Failed operations with no retry mechanism
- **No audit trail**: Changes appear in the database with no record of who, what, or why
- **Validation gaps**: Agent work accepted without proper verification
- **Retry chaos**: Failed work retried indefinitely or not at all

## The Solution

Laravel Work Manager provides a complete control plane for AI agent work with:

### Single Path for All Mutations
All changes flow through a validated work order system with:
- Type-safe payloads via JSON schema
- Multi-phase validation (submission + approval)
- Audit trail for every state change
- Optional enforcement middleware to block direct mutations

### Safe Concurrency Control
TTL-based leasing prevents race conditions:
- Single agent per work item
- Heartbeat requirement to maintain lease
- Automatic reclaim on expiry
- Configurable retry with backoff

### Strong Typing & Validation
Define custom order types with:
- JSON schema for payload validation
- Laravel validation for agent submissions
- Custom business logic verification
- Approval readiness checks

### Idempotency & Retry
Built-in safety for distributed systems:
- Header-based idempotency keys
- Cached responses for retries
- Configurable max attempts
- Dead-letter queue for failures

### Complete Auditability
Every action is tracked:
- State transitions recorded as events
- Agent provenance captured
- Before/after diffs for all changes
- Queryable event log

### First-Class Agent Support
Designed for AI agents from the ground up:
- Built-in MCP (Model Context Protocol) server
- RESTful HTTP API
- Partial submission support for long-running tasks
- Clear error messages and validation feedback

## Key Features

- **Typed Work Orders**: Define custom order types with schema, validation, and execution logic
- **State Machine**: Strict lifecycle enforcement with automatic event recording
- **Leasing System**: TTL-based work item checkout with heartbeat requirement
- **Idempotency**: Request deduplication via headers with cached responses
- **HTTP API**: Mountable controller with propose/checkout/submit/approve endpoints
- **MCP Server**: Built-in Model Context Protocol integration for AI agents
- **Partial Submissions**: Incremental result submission for complex tasks
- **Events**: Laravel events for every lifecycle transition
- **Commands**: Scheduled generation and maintenance tasks
- **Middleware**: Enforce work-order-only mutations on legacy routes
- **Policies**: Authorization via Laravel policies
- **Metrics**: Pluggable metrics drivers (log, Prometheus, StatsD)

## Architecture at a Glance

```
Agent proposes work
    ↓
System creates order & plans items
    ↓
Agent checks out (leases) item
    ↓
Agent processes & submits results
    ↓
System validates submission
    ↓
Backend approves order
    ↓
System applies changes (your logic)
    ↓
Order completed with audit trail
```

## When to Use Laravel Work Manager

**✅ Good Fit:**
- AI agents performing backend operations (data sync, enrichment, research)
- Batch processing that requires validation and approval
- Operations needing complete audit trails
- Systems with multiple agents or workers
- Workflows requiring idempotency and retry logic
- Applications transitioning from ad-hoc mutations to structured workflows

**❌ Not Ideal For:**
- Simple CRUD operations (use standard Laravel resources)
- Real-time user interactions (this is batch/async oriented)
- Operations that don't need validation or audit
- Single-threaded scripts (the leasing overhead isn't necessary)

## What You Need to Know

**Laravel Experience Required:**
- Service providers and dependency injection
- Eloquent models and relationships
- Validation and form requests
- Events and listeners
- Policies and authorization
- Database migrations and transactions

**Concepts You'll Learn:**
- Work order control planes
- State machines and lifecycle management
- Lease-based concurrency control
- Two-phase validation (submission + approval)
- Idempotency patterns
- Agent-oriented APIs

## Compatibility

- **PHP**: 8.2 or higher
- **Laravel**: 11.x, 12.x
- **Database**: MySQL 8+, PostgreSQL 13+, SQLite 3.8.8+
- **Redis** (optional): For lease backend and caching
- **MCP Support**: php-mcp/laravel ^1.0 (included)

## License

Laravel Work Manager is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## See Also

- [Requirements](requirements.md) - Detailed system requirements
- [Installation](installation.md) - Step-by-step installation guide
- [Quickstart](quickstart.md) - Build your first order type
- [What It Does](../concepts/what-it-does.md) - Deeper dive into core concepts
- [Architecture Overview](../concepts/architecture-overview.md) - System design details
