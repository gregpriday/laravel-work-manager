# Laravel Work Manager Documentation

**Production-ready, comprehensive documentation for Laravel Work Manager**

## Welcome

This is the complete documentation for Laravel Work Manager, an AI-agent oriented work order control plane for Laravel applications.

The documentation has been professionally restructured (January 2025) to provide:
- ✅ **Clear learning paths** from beginner to advanced
- ✅ **Comprehensive coverage** of all features
- ✅ **Practical examples** with working code
- ✅ **Complete reference** documentation
- ✅ **Modern organization** with kebab-case filenames
- ✅ **Full cross-referencing** between all sections

## Quick Navigation

### 🚀 Getting Started
**New to Laravel Work Manager? Start here:**

1. [Introduction](getting-started/introduction.md) - What it is and why you need it
2. [Requirements](getting-started/requirements.md) - System requirements
3. [Installation](getting-started/installation.md) - Install and configure
4. [Quickstart](getting-started/quickstart.md) - Build your first order type in 5 minutes

### 📚 Main Documentation Sections

- **[Concepts](concepts/what-it-does.md)** - Understand the architecture and design
- **[Guides](guides/creating-order-types.md)** - Practical how-to guides
- **[Examples](examples/overview.md)** - Real-world implementations
- **[Reference](reference/api-surface.md)** - Complete technical specifications
- **[Troubleshooting](troubleshooting/common-errors.md)** - Common errors and FAQ
- **[Contributing](meta/contributing.md)** - How to contribute

### 📖 Most Popular Pages

- [Creating Order Types](guides/creating-order-types.md) - Complete guide to building custom types
- [MCP Server Integration](guides/mcp-server-integration.md) - Connect AI agents
- [HTTP API Reference](guides/http-api.md) - REST API documentation
- [Partial Submissions](guides/partial-submissions.md) - Incremental work submission
- [Database Schema](reference/database-schema.md) - Complete schema reference

## Documentation Statistics

- **Total Files**: 54 markdown files
- **Sections**: 6 major sections + indexes
- **Code Examples**: 100+ working examples
- **Cross-References**: Fully linked
- **Format**: Modern kebab-case
- **Status**: Production-ready ✅

## File Organization

```
docs/
├── index.md                    # Documentation homepage
├── README.md                   # This file
├── DOCUMENTATION_INDEX.md      # Complete file index
├── MIGRATION_GUIDE.md          # Migration from old structure
│
├── getting-started/            # Essential guides (4 files)
├── concepts/                   # Core architecture (6 files)
├── guides/                     # How-to guides (16 files)
├── examples/                   # Real implementations (7 files)
├── reference/                  # Technical specs (7 files)
├── troubleshooting/            # Help & FAQ (3 files)
├── meta/                       # Project info (6 files)
│
├── assets/                     # Images and diagrams
└── partials/                   # Reusable content snippets
```

## For Different Audiences

### For Developers New to the Package

**Recommended path**:
1. Read [Introduction](getting-started/introduction.md)
2. Check [Requirements](getting-started/requirements.md)
3. Follow [Installation](getting-started/installation.md)
4. Complete [Quickstart](getting-started/quickstart.md)
5. Explore [Basic Usage Example](examples/basic-usage.md)
6. Review [Creating Order Types Guide](guides/creating-order-types.md)

### For System Architects

**Recommended path**:
1. Review [What It Does](concepts/what-it-does.md)
2. Study [Architecture Overview](concepts/architecture-overview.md)
3. Understand [Lifecycle & Flow](concepts/lifecycle-and-flow.md)
4. Review [State Management](concepts/state-management.md)
5. Check [Security & Permissions](concepts/security-and-permissions.md)
6. Read [Deployment Guide](guides/deployment-and-production.md)

### For AI Agent Integrators

**Recommended path**:
1. Review [Introduction](getting-started/introduction.md)
2. Set up [MCP Server](guides/mcp-server-integration.md)
3. Study [HTTP API](guides/http-api.md)
4. Learn [Partial Submissions](guides/partial-submissions.md)
5. Explore [Customer Research Example](examples/customer-research-partial.md)
6. Review [Events Reference](reference/events-reference.md)

### For Contributors

**Recommended path**:
1. Read [Contributing Guidelines](meta/contributing.md)
2. Review [Architecture Overview](concepts/architecture-overview.md)
3. Check [Testing Guide](guides/testing.md)
4. See [Release Process](meta/release-process.md)
5. Read [Versioning Policy](meta/versioning-policy.md)

## Documentation Standards

All documentation follows these standards:

- **Filenames**: kebab-case (e.g., `my-new-guide.md`)
- **Structure**: Consistent heading hierarchy
- **Examples**: Working code that can be copy-pasted
- **Links**: Relative links between docs
- **Cross-references**: "See Also" sections
- **Code style**: Laravel conventions
- **Formatting**: GitHub-flavored markdown

## Finding What You Need

### By Topic
- **Installation**: [getting-started/installation.md](getting-started/installation.md)
- **Order Types**: [guides/creating-order-types.md](guides/creating-order-types.md)
- **Validation**: [guides/validation-and-acceptance-policies.md](guides/validation-and-acceptance-policies.md)
- **API Integration**: [guides/http-api.md](guides/http-api.md)
- **AI Agents**: [guides/mcp-server-integration.md](guides/mcp-server-integration.md)
- **Configuration**: [reference/config-reference.md](reference/config-reference.md)
- **Events**: [reference/events-reference.md](reference/events-reference.md)
- **Troubleshooting**: [troubleshooting/common-errors.md](troubleshooting/common-errors.md)

### By Task
- **"I want to build my first order type"**: [Quickstart](getting-started/quickstart.md)
- **"I need to integrate an AI agent"**: [MCP Server Integration](guides/mcp-server-integration.md)
- **"I'm getting an error"**: [Common Errors](troubleshooting/common-errors.md)
- **"I want to see real examples"**: [Examples Overview](examples/overview.md)
- **"I need API documentation"**: [Routes Reference](reference/routes-reference.md)
- **"I want to deploy to production"**: [Deployment Guide](guides/deployment-and-production.md)

### By Type of Content
- **Conceptual**: [concepts/](concepts/what-it-does.md)
- **Practical**: [guides/](guides/creating-order-types.md)
- **Reference**: [reference/](reference/api-surface.md)
- **Examples**: [examples/](examples/overview.md)

## Recent Updates

**January 22, 2025** - Complete documentation restructure:
- Rebuilt entire documentation structure
- Created 52 new comprehensive documentation files
- Migrated from ALL-CAPS to kebab-case filenames
- Added 6 new major sections
- Created complete reference documentation
- Added comprehensive examples
- Added troubleshooting and FAQ sections
- Fully cross-referenced all documentation

See [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) for complete details.

## Contributing to Documentation

We welcome documentation contributions! Please see:

- [Contributing Guidelines](meta/contributing.md) - How to contribute
- [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) - Documentation structure
- [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) - Complete file list

When adding or updating documentation:
1. Use kebab-case filenames
2. Place in appropriate section
3. Include code examples
4. Add cross-references
5. Update indexes if needed
6. Test all links

## Getting Help

- **Common Issues**: [troubleshooting/common-errors.md](troubleshooting/common-errors.md)
- **FAQ**: [troubleshooting/faq.md](troubleshooting/faq.md)
- **Known Limitations**: [troubleshooting/known-limitations.md](troubleshooting/known-limitations.md)
- **Community Support**: [meta/support-and-community.md](meta/support-and-community.md)
- **GitHub Issues**: [github.com/gregpriday/laravel-work-manager/issues](https://github.com/gregpriday/laravel-work-manager/issues)

## Quick Links

- [Main Documentation Homepage](index.md)
- [Complete Documentation Index](documentation-index.md)
- [Migration Guide](migration-guide.md)
- [Root README](../README.md)
- [License](../LICENSE.md)

---

**Documentation Version**: 1.0
**Last Updated**: January 22, 2025
**Status**: Production-ready ✅
