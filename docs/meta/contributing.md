# Contributing to Laravel Work Manager

Thank you for considering contributing to Laravel Work Manager! This document provides guidelines for contributing code, documentation, and bug reports.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Running Tests](#running-tests)
- [Coding Standards](#coding-standards)
- [Pull Request Process](#pull-request-process)
- [Documentation](#documentation)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Features](#suggesting-features)

---

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inspiring community for everyone. Please be respectful and constructive in all interactions.

### Our Standards

**Examples of encouraged behavior:**
- Using welcoming and inclusive language
- Being respectful of differing viewpoints
- Gracefully accepting constructive criticism
- Focusing on what is best for the community
- Showing empathy towards other community members

**Examples of unacceptable behavior:**
- Harassment, trolling, or discriminatory comments
- Publishing others' private information without permission
- Other conduct which could reasonably be considered inappropriate

---

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates.

**Good bug reports include:**
- Clear, descriptive title
- Exact steps to reproduce the problem
- Expected behavior vs actual behavior
- Code samples or test cases
- Environment details:
  - Laravel version
  - PHP version
  - Database type and version
  - Work Manager version
- Full error messages and stack traces

**Use this template:**
```markdown
## Bug Description
Brief description of the issue

## Steps to Reproduce
1. Create an order type with...
2. Propose a work order...
3. Checkout and submit...
4. See error

## Expected Behavior
What you expected to happen

## Actual Behavior
What actually happened

## Environment
- Laravel: 11.0
- PHP: 8.2.0
- Database: MySQL 8.0.32
- Work Manager: 1.0.0

## Error Message
```
[Full error message and stack trace]
```

## Code Sample
```php
// Minimal code to reproduce
```
```

### Suggesting Features

Feature suggestions are welcome! Please:
1. Check if the feature already exists or is planned
2. Explain the use case and why it's valuable
3. Consider how it fits with the package's goals
4. Provide examples of how it would be used

**Use this template:**
```markdown
## Feature Summary
Brief description of the feature

## Use Case
Why is this feature needed? What problem does it solve?

## Proposed Implementation
How might this feature work?

## Example Usage
```php
// Example code showing how the feature would be used
```

## Alternatives Considered
What alternatives exist? Why is this approach better?
```

### Contributing Code

We welcome code contributions! Types of contributions:
- Bug fixes
- New features (please open an issue first to discuss)
- Performance improvements
- Documentation improvements
- Test coverage improvements

---

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer
- MySQL 8.0+ or PostgreSQL 13+
- Redis (optional, for lease backend testing)
- Git

### Clone and Install

```bash
# Clone the repository
git clone https://github.com/gregpriday/laravel-work-manager.git
cd laravel-work-manager

# Install dependencies
composer install
```

### Configure Test Environment

```bash
# Copy the test environment file
cp .env.testing.example .env.testing

# Configure database connection
# Edit .env.testing with your database credentials
```

**Example `.env.testing`:**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=work_manager_test
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Create Test Database

```bash
# MySQL
mysql -u root -p -e "CREATE DATABASE work_manager_test;"

# PostgreSQL
createdb work_manager_test
```

---

## Running Tests

### Full Test Suite

```bash
# Run all tests
composer test

# Or directly with Pest
vendor/bin/pest
```

### Specific Tests

```bash
# Run a specific test file
vendor/bin/pest tests/Feature/WorkOrderTest.php

# Run tests matching a pattern
vendor/bin/pest --filter="lease"

# Run a specific test method
vendor/bin/pest --filter="test_agent_can_checkout_work_item"
```

### With Coverage

```bash
# Generate coverage report
vendor/bin/pest --coverage

# Generate HTML coverage report
vendor/bin/pest --coverage-html coverage

# Enforce minimum coverage
vendor/bin/pest --coverage --min=80
```

### Test Database

Tests use database transactions and are automatically rolled back. No cleanup needed.

### Continuous Integration

All pull requests run tests automatically via GitHub Actions. Ensure tests pass locally before submitting.

---

## Coding Standards

### PSR-12 Compliance

This package follows [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.

**Check code style:**
```bash
composer check-style

# Or manually
vendor/bin/phpcs --standard=PSR12 src/
```

**Fix code style automatically:**
```bash
composer fix-style

# Or manually
vendor/bin/phpcbf --standard=PSR12 src/
```

### PHP Standards

- Use type declarations for all parameters and return types
- Use strict types: `declare(strict_types=1);`
- Use readonly properties where appropriate (PHP 8.1+)
- Use enums for fixed sets of values
- Avoid `mixed` types when possible

**Good:**
```php
declare(strict_types=1);

public function acquire(int $itemId, string $agentId): WorkItem
{
    // Implementation
}
```

**Avoid:**
```php
// No type declarations
public function acquire($itemId, $agentId)
{
    // Implementation
}
```

### Laravel Conventions

- Use Laravel's helpers and facades where appropriate
- Follow Eloquent conventions for models
- Use Laravel validation for input validation
- Use dependency injection over service location

### Naming Conventions

- Classes: `PascalCase`
- Methods: `camelCase`
- Variables: `camelCase`
- Constants: `SCREAMING_SNAKE_CASE`
- Database tables: `snake_case`
- Configuration keys: `snake_case`

### Documentation

- Add PHPDoc blocks for all public methods
- Include `@param` and `@return` annotations
- Document complex logic with inline comments
- Update relevant markdown docs

**Example:**
```php
/**
 * Acquire a lease on a work item for an agent.
 *
 * @param int $itemId The ID of the work item to lease
 * @param string $agentId The agent identifier
 * @return WorkItem The leased work item
 * @throws LeaseConflictException If item is already leased
 * @throws LeaseExpiredException If attempting to extend an expired lease
 */
public function acquire(int $itemId, string $agentId): WorkItem
{
    // Implementation
}
```

### Testing Standards

- Write tests for all new features
- Write tests for all bug fixes
- Use descriptive test names: `test_agent_can_checkout_work_item`
- Use Pest's expectation API
- Keep tests focused (one concept per test)
- Use factories for test data

**Good test:**
```php
test('agent can checkout work item', function () {
    $order = WorkOrder::factory()->create(['state' => OrderState::QUEUED]);
    $item = WorkItem::factory()->create([
        'order_id' => $order->id,
        'state' => ItemState::QUEUED,
    ]);

    $leased = $this->leaseService->acquire($item->id, 'agent-1');

    expect($leased->state)->toBe(ItemState::LEASED)
        ->and($leased->leased_by)->toBe('agent-1');
});
```

---

## Pull Request Process

### Before Submitting

1. **Create an issue first** for new features (so we can discuss approach)
2. **Fork the repository** and create a branch from `main`
3. **Write tests** for your changes
4. **Run the test suite** and ensure all tests pass
5. **Check code style** and fix any issues
6. **Update documentation** if needed
7. **Write clear commit messages**

### Branch Naming

Use descriptive branch names:
- `feature/add-workflow-support`
- `fix/lease-expiration-bug`
- `docs/improve-quickstart`
- `refactor/simplify-state-machine`

### Commit Messages

Write clear, descriptive commit messages:

**Good:**
```
Add support for workflow dependencies

- Implement DAG-based dependency resolution
- Add WorkItemDependency model
- Update state machine to check dependencies
- Add tests for dependency validation
```

**Avoid:**
```
fix bug
update code
changes
```

### Pull Request Template

**Use this template:**
```markdown
## Description
Brief description of what this PR does

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Related Issue
Fixes #123

## Changes Made
- Specific change 1
- Specific change 2
- Specific change 3

## Testing
- [ ] All existing tests pass
- [ ] New tests added for changes
- [ ] Manual testing completed

## Documentation
- [ ] Code comments updated
- [ ] README.md updated (if needed)
- [ ] Other docs updated (if needed)

## Checklist
- [ ] Code follows PSR-12 style guidelines
- [ ] Self-review completed
- [ ] Tests pass locally
- [ ] No new warnings or errors
```

### Review Process

1. Maintainer reviews code
2. Automated tests run via GitHub Actions
3. Feedback provided if changes needed
4. Once approved, maintainer merges PR

**Please be patient.** We review PRs as quickly as possible, but may take a few days.

---

## Documentation

### Where Documentation Lives

- **README.md**: Main package documentation, installation, quick start
- **CLAUDE.md**: AI assistant guidance (Claude Code)
- **docs/concepts/architecture-overview.md**: System design, data flows, integration
- **docs/guides/mcp-server-integration.md**: MCP server setup and usage
- **docs/USE_CASES.md**: Real-world use cases and patterns
- **docs/troubleshooting/**: Troubleshooting guides
- **docs/meta/**: Project meta information
- **examples/**: Example implementations

### Documentation Guidelines

- Use clear, simple language
- Include code examples
- Provide real-world use cases
- Link to related documentation
- Keep formatting consistent
- Update docs with code changes

### Building Documentation Locally

Documentation is in Markdown and can be viewed directly on GitHub or with any Markdown viewer.

---

## Release Process

Maintainers handle releases. See [release-process.md](release-process.md) for details.

---

## Getting Help

### Questions?

- Check [FAQ](../troubleshooting/faq.md)
- Search [existing issues](https://github.com/gregpriday/laravel-work-manager/issues)
- Create a new issue with the "question" label

### Discussion

For general discussion about Laravel Work Manager:
- GitHub Discussions (coming soon)
- Laravel community channels

---

## Recognition

Contributors are recognized in:
- GitHub contributors page
- Release notes
- Package changelog

Thank you for contributing to Laravel Work Manager!
