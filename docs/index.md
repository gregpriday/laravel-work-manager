# Laravel Work Manager Documentation

**AI-agent oriented work order control plane for Laravel.**

Laravel Work Manager is a production-ready Laravel package that provides a framework-native way to create, lease, validate, approve, and apply typed work orders—with strong guarantees around state management, idempotency, auditability, and agent ergonomics.

## Quick Links

### 🚀 Get Started
- [Introduction](getting-started/introduction.md) - What Laravel Work Manager is and why it exists
- [Requirements](getting-started/requirements.md) - System requirements and dependencies
- [Installation](getting-started/installation.md) - Install and configure the package
- [Quickstart](getting-started/quickstart.md) - Build your first work order type in 5 minutes

### 📚 Learn the Concepts
- [What It Does](concepts/what-it-does.md) - Core problem and solution overview
- [Architecture Overview](concepts/architecture-overview.md) - System design and components
- [Lifecycle & Flow](concepts/lifecycle-and-flow.md) - Work order and item lifecycle
- [Configuration Model](concepts/configuration-model.md) - How configuration works
- [State Management](concepts/state-management.md) - State machine and transitions
- [Security & Permissions](concepts/security-and-permissions.md) - Authorization and security model

### 📖 Guides & How-Tos
- [Service Provider & Bootstrapping](guides/service-provider-and-bootstrapping.md)
- [Configuration](guides/configuration.md)
- [Creating Order Types](guides/creating-order-types.md)
- [Validation & Acceptance Policies](guides/validation-and-acceptance-policies.md)
- [HTTP API](guides/http-api.md)
- [MCP Server Integration](guides/mcp-server-integration.md)
- [Partial Submissions](guides/partial-submissions.md)
- [Events & Listeners](guides/events-and-listeners.md)
- [Console Commands](guides/console-commands.md)
- [Testing](guides/testing.md)
- [Deployment & Production](guides/deployment-and-production.md)

### 💡 Examples
- [Examples Overview](examples/overview.md) - How to use the examples
- [Basic Usage](examples/basic-usage.md) - Simple work order example
- [Database Record Insert](examples/database-record-insert.md) - Batch database operations
- [User Data Sync](examples/user-data-sync.md) - External API synchronization
- [Customer Research with Partials](examples/customer-research-partial.md) - Incremental research tasks
- [Content Fact Check](examples/content-fact-check.md) - Content verification workflow
- [City Tier Generation](examples/city-tier-generation.md) - Data classification task

### 📋 Reference
- [API Surface](reference/api-surface.md) - Complete API index
- [Configuration Reference](reference/config-reference.md) - All config options
- [Routes Reference](reference/routes-reference.md) - HTTP endpoints
- [Commands Reference](reference/commands-reference.md) - Artisan commands
- [Events Reference](reference/events-reference.md) - All lifecycle events
- [Exceptions Reference](reference/exceptions-reference.md) - Exception types
- [Database Schema](reference/database-schema.md) - Tables and columns

### 🔧 Troubleshooting
- [Common Errors](troubleshooting/common-errors.md) - Error messages and solutions
- [FAQ](troubleshooting/faq.md) - Frequently asked questions
- [Known Limitations](troubleshooting/known-limitations.md) - Current limitations

### 📄 Meta
- [Contributing](meta/contributing.md) - How to contribute
- [Security Policy](meta/security-policy.md) - Reporting security issues
- [Release Process](meta/release-process.md) - How releases are managed
- [Versioning Policy](meta/versioning-policy.md) - Semantic versioning approach
- [Support & Community](meta/support-and-community.md) - Getting help
- [Glossary](meta/glossary.md) - Terms and definitions

## Feature Highlights

### For AI Agents
- **MCP Server**: Built-in Model Context Protocol server for seamless AI agent integration
- **Strong Typing**: JSON schema validation for payloads
- **Leasing**: TTL-based work item leasing with heartbeat support
- **Idempotency**: Header-based request deduplication
- **Partial Submissions**: Incremental result submission for long-running tasks

### For Laravel Developers
- **Framework Integration**: Full Laravel validation, events, jobs, policies
- **Type System**: Custom order types with lifecycle hooks
- **State Machine**: Strict state transitions with automatic event recording
- **Auditability**: Complete audit trail with provenance tracking
- **Production Ready**: Redis lease backend, metrics, observability hooks

### Key Components

```
┌─────────────────────────────────────────────────────────────┐
│                    AI Agent / Client                         │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP/MCP
┌──────────────────────▼──────────────────────────────────────┐
│                   HTTP API Layer                             │
│  propose · checkout · heartbeat · submit · approve          │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                   Service Layer                              │
│  WorkAllocator · WorkExecutor · LeaseService                 │
│  StateMachine · IdempotencyService · Registry                │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│               Your Custom Order Types                        │
│  schema() · plan() · validate() · apply()                    │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                  Data/Model Layer                            │
│  WorkOrder · WorkItem · WorkEvent · WorkProvenance           │
└──────────────────────────────────────────────────────────────┘
```

## Next Steps

New to Laravel Work Manager? Start with the [Introduction](getting-started/introduction.md) to understand what problems it solves, then follow the [Installation Guide](getting-started/installation.md) and [Quickstart](getting-started/quickstart.md) to build your first work order type.

Already familiar with the basics? Jump into the [Guides](guides/service-provider-and-bootstrapping.md) section for in-depth how-tos, or browse the [Examples](examples/overview.md) to see real-world implementations.

---

**Version**: 1.0
**License**: MIT
**Repository**: [github.com/gregpriday/laravel-work-manager](https://github.com/gregpriday/laravel-work-manager)
