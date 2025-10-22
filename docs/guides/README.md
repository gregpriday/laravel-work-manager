# Laravel Work Manager - Guides Index

Comprehensive how-to guides for Laravel Work Manager. Each guide is a practical, step-by-step resource with code examples.

## Available Guides

### Getting Started
1. **[Service Provider and Bootstrapping](service-provider-and-bootstrapping.md)** - How the package boots, container bindings, overriding bindings, manual registration
2. **[Configuration](configuration.md)** - Editing config file, publish tags, per-environment overrides, common recipes
3. **[Environment Variables](environment-variables.md)** - All env vars, defaults, when needed

### Core Concepts
4. **[Creating Order Types](creating-order-types.md)** - Complete guide to building custom order types, extending AbstractOrderType, implementing methods, registration
5. **[Validation and Acceptance Policies](validation-and-acceptance-policies.md)** - Laravel validation integration, custom verification, acceptance policies, two-phase validation
6. **[HTTP API](http-api.md)** - All HTTP endpoints, request/response formats, authentication, error handling

### Advanced Features
7. **MCP Server Integration** - Setting up MCP server (stdio and HTTP modes), AI IDE integration (Cursor, Claude Desktop), production deployment
8. **Partial Submissions** - How to use partial submissions, when to use them, implementing partialRules(), assemble(), examples
9. **Leasing and Concurrency** - How leasing works, heartbeats, backend options (database vs Redis), concurrency limits

### Integration
10. **Events and Listeners** - All available events, subscribing to events, event payloads, common patterns
11. **Console Commands** - work-manager:generate, work-manager:maintain, work-manager:mcp, scheduling
12. **Database and Migrations** - Tables created, schema overview, relationships, indexes
13. **Queues and Jobs** - Using queues with work manager, dispatching jobs in hooks, queue configuration

### Operations
14. **Testing** - Testing order types, test helpers, fakes, example test cases
15. **Deployment and Production** - Production configuration, supervisor setup, scaling, monitoring
16. **Upgrading Between Versions** - Version upgrade guide, breaking changes, migration steps

## Quick Links

- **Main Documentation**: [README.md](../../README.md)
- **Architecture**: [ARCHITECTURE.md](../../docs/ARCHITECTURE.md)
- **MCP Server**: [MCP_SERVER.md](../../docs/MCP_SERVER.md)
- **Examples**: [examples/](../../examples/)

## Guide Format

Each guide follows this structure:

1. **Objective** - What you'll learn
2. **Step-by-Step Instructions** - Clear, actionable steps
3. **Code Examples** - Copy-paste ready code
4. **Expected Results** - What to expect
5. **Troubleshooting** - Common issues and solutions
6. **See Also** - Related guides and resources

## Contributing

To add or improve guides:
1. Follow the existing format
2. Include working code examples
3. Test all examples before committing
4. Add to this index

## Support

For questions about these guides:
- Check the [main README](../../README.md)
- Review [examples/](../../examples/)
- Open an issue on GitHub
