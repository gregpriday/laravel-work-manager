# Versioning Policy

This document describes Laravel Work Manager's approach to semantic versioning, backwards compatibility, and deprecation.

## Semantic Versioning

Laravel Work Manager follows [Semantic Versioning 2.0.0](https://semver.org/) (semver).

### Version Format: MAJOR.MINOR.PATCH

Given a version number MAJOR.MINOR.PATCH (e.g., 1.2.3):

- **MAJOR**: Incremented for incompatible API changes
- **MINOR**: Incremented for backwards-compatible new features
- **PATCH**: Incremented for backwards-compatible bug fixes

### What Each Version Means

#### MAJOR Version (Breaking Changes)

Increment MAJOR version when you make incompatible changes:

**Examples:**
- Removing public methods or classes
- Changing method signatures (parameters, return types)
- Renaming methods/classes/interfaces
- Changing behavior of existing features (non-bug fixes)
- Removing configuration options
- Database schema changes without migration path
- Minimum PHP/Laravel version increase (major bump)

**Release Frequency:** Annually or as needed

**Example:** `1.5.2 → 2.0.0`

#### MINOR Version (New Features)

Increment MINOR version when you add functionality in a backwards-compatible manner:

**Examples:**
- Adding new public methods
- Adding optional parameters (with defaults)
- Adding new configuration options
- Adding new events
- Adding new order type hooks
- Performance improvements
- Deprecating features (not removing)
- Internal refactoring

**Release Frequency:** Every 2-3 months

**Example:** `1.2.3 → 1.3.0`

#### PATCH Version (Bug Fixes)

Increment PATCH version for backwards-compatible bug fixes:

**Examples:**
- Fixing bugs
- Security patches
- Documentation corrections
- Typo fixes
- Test improvements

**Release Frequency:** As needed (weekly to monthly)

**Example:** `1.2.3 → 1.2.4`

---

## Backwards Compatibility Promise

### What We Guarantee

Within the same MAJOR version (e.g., all 1.x releases), we guarantee:

1. **API Compatibility**: Public methods and classes remain compatible
2. **Configuration Compatibility**: Existing config files continue to work
3. **Database Compatibility**: Migrations are always forward-compatible
4. **Behavior Compatibility**: Existing functionality continues to work (except bug fixes)

### What We Consider Public API

**Public API includes:**
- Public methods on classes in `src/`
- Facades (e.g., `WorkManager`)
- Configuration files (`config/work-manager.php`)
- Database schema (migrations)
- Events
- Exceptions
- Contracts (interfaces)

**Not part of public API:**
- Internal methods (prefixed with `_` or marked `@internal`)
- Private/protected methods
- Implementation details
- Test code
- Documentation structure

### Example of Backwards Compatible Change

**Version 1.2.0 → 1.3.0:**

**Before:**
```php
public function apply(WorkOrder $order): Diff
{
    // Implementation
}
```

**After (MINOR - Adding Optional Parameter):**
```php
public function apply(WorkOrder $order, ?array $options = null): Diff
{
    // Implementation with optional $options
}
```

This is backwards compatible because existing code still works.

### Example of Breaking Change

**Version 1.x → 2.0:**

**Before:**
```php
public function apply(WorkOrder $order): Diff
{
    // Implementation
}
```

**After (MAJOR - Required Parameter):**
```php
public function apply(WorkOrder $order, array $context): Diff
{
    // Implementation requiring $context
}
```

This is a breaking change requiring MAJOR version bump.

---

## Deprecation Policy

### Deprecation Process

We follow a gradual deprecation process:

1. **Announce** deprecation in MINOR release
2. **Wait** minimum 3 months
3. **Remove** in next MAJOR release

### Deprecation Timeline

```
Version 1.2.0 (Jan 2025)
├─ Feature X deprecated
├─ Documentation updated
└─ Runtime warning added

Version 1.3.0 (Mar 2025)
├─ Feature X still works
└─ Deprecation warning continues

Version 1.4.0 (Jun 2025)
├─ Feature X still works
└─ "Will be removed in 2.0" notice

Version 2.0.0 (Jul 2025)
└─ Feature X removed
```

### How We Deprecate

**1. Runtime Warning:**
```php
/**
 * @deprecated since 1.2.0, use newMethod() instead
 */
public function oldMethod(): void
{
    trigger_error(
        'oldMethod() is deprecated, use newMethod() instead. ' .
        'Will be removed in 2.0.0',
        E_USER_DEPRECATED
    );

    return $this->newMethod();
}
```

**2. Documentation:**
```markdown
## [1.2.0] - 2025-01-15

### Deprecated
- `oldMethod()` in favor of `newMethod()` (#123)
  Will be removed in 2.0.0

### Migration Path
Replace:
```php
$service->oldMethod();
```

With:
```php
$service->newMethod();
```
```

**3. Changelog Entry:**
Always documented in CHANGELOG.md with migration instructions.

---

## Version Support Lifecycle

### Support Tiers

| Tier               | Bug Fixes | Security Fixes | New Features |
|--------------------|-----------|----------------|--------------|
| Active Support     | ✓         | ✓              | ✓            |
| Security Fixes Only| ✗         | ✓              | ✗            |
| End of Life        | ✗         | ✗              | ✗            |

### Current Support Status

| Version | Status              | Released   | End of Support | Security Fixes Until |
|---------|---------------------|------------|----------------|---------------------|
| 1.x     | Active Support      | 2025-01-15 | TBD            | TBD                 |
| 2.x     | Planned (Q3 2025)   | N/A        | N/A            | N/A                 |

### Support Duration

- **Active Support**: Minimum 12 months after MINOR release
- **Security Fixes**: Additional 6 months after active support ends
- **Major Versions**: Minimum 18 months total support

**Example:**
```
Version 1.0.0 released: Jan 2025
Active support until: Jan 2026 (minimum)
Security fixes until: Jul 2026 (minimum)
```

---

## PHP and Laravel Version Support

### Current Requirements

**Laravel Work Manager 1.x:**
- PHP: 8.2 or higher
- Laravel: 10.x or 11.x

### Version Upgrade Policy

**PHP Version:**
- New MINOR versions may require newer PHP versions
- PATCH versions never change PHP requirements
- Announced 3+ months in advance

**Laravel Version:**
- Support for new Laravel versions added in MINOR releases
- Support for old Laravel versions dropped in MAJOR releases
- Minimum 6 months notice before dropping support

**Example:**
```
Laravel Work Manager 1.0: PHP 8.2+, Laravel 10-11
Laravel Work Manager 1.5: PHP 8.3+, Laravel 10-11
Laravel Work Manager 2.0: PHP 8.3+, Laravel 11-12
```

---

## Database Migration Compatibility

### Forward Compatibility

All migrations are forward-compatible within the same MAJOR version:

```bash
# Can always upgrade within same MAJOR
1.0.0 → 1.5.0  # Safe, run new migrations
1.5.0 → 1.10.0 # Safe, run new migrations
```

### Downgrade Policy

**Not officially supported.** However:
- Database migrations are reversible where possible
- Rolling back code requires rolling back migrations
- Test rollback in staging environment

### Breaking Schema Changes

Only in MAJOR releases:

**Example: Version 1.x → 2.0**
```bash
# Migration adds required column
php artisan migrate

# Update code to use new column
composer require gregpriday/laravel-work-manager:^2.0
```

Migration guide provided for all breaking schema changes.

---

## Configuration File Compatibility

### Backwards Compatibility

Configuration files remain compatible within same MAJOR version:

**Version 1.x:**
```php
// config/work-manager.php (1.0)
'lease_ttl' => 600,

// Still works in 1.5
'lease_ttl' => 600,
```

### Adding Configuration Options

New options added in MINOR releases with sensible defaults:

**Version 1.2.0:**
```php
// New option with default
'partials' => [
    'enabled' => true, // New in 1.2.0
],
```

Existing configs continue to work without updates.

### Deprecating Configuration Options

**Version 1.3.0 (deprecate):**
```php
'lease_ttl' => 600, // Deprecated, use lease.ttl_seconds
'lease' => [
    'ttl_seconds' => 600, // New preferred format
],
```

Both formats work until 2.0.

**Version 2.0.0 (remove):**
```php
// Old format no longer supported
'lease' => [
    'ttl_seconds' => 600, // Only this format
],
```

---

## API Stability Guarantees

### Stable API

**Guaranteed stable:**
- Public methods in core classes
- Facades
- Configuration structure
- Database schema
- Events
- Exceptions

**Example:**
```php
// This API is stable within 1.x
WorkManager::propose(['type' => 'my.type', ...]);
WorkManager::registry()->register(new MyType());
```

### Experimental API

Marked with `@experimental` tag:

```php
/**
 * @experimental This API may change in minor releases
 */
public function experimentalFeature(): void
{
    // Implementation
}
```

Experimental APIs:
- May change in MINOR releases
- Documented as experimental
- Graduated to stable in future MAJOR release

### Internal API

Marked with `@internal` tag:

```php
/**
 * @internal
 */
protected function internalHelper(): void
{
    // Not part of public API
    // May change without notice
}
```

Internal APIs:
- Not considered breaking changes
- Should not be used by consumers
- No backwards compatibility guarantee

---

## Event Compatibility

### Event Structure

Event structure remains stable within MAJOR version:

**Version 1.x:**
```php
class WorkOrderApplied
{
    public function __construct(
        public readonly WorkOrder $order,
        public readonly Diff $diff,
    ) {}
}
```

### Adding Event Properties

New properties may be added in MINOR releases (at end):

**Version 1.3.0:**
```php
class WorkOrderApplied
{
    public function __construct(
        public readonly WorkOrder $order,
        public readonly Diff $diff,
        public readonly ?array $context = null, // New in 1.3.0
    ) {}
}
```

Existing event listeners continue to work.

### New Events

New events added in MINOR releases without breaking existing code.

---

## Upgrade Path Guarantees

### Incremental Upgrades

You can upgrade incrementally within same MAJOR:

```bash
# Supported upgrade paths
1.0.0 → 1.1.0 → 1.2.0 → 1.3.0
1.0.0 → 1.3.0 (direct, also supported)
```

### MAJOR Version Upgrades

Provide clear migration guide:

**1.x → 2.0 Upgrade:**
1. Read migration guide
2. Update deprecation warnings in 1.x
3. Update code for breaking changes
4. Run new migrations
5. Update config (if needed)
6. Test thoroughly

**Estimated time:**
- Small apps: 15-30 minutes
- Medium apps: 1-2 hours
- Large apps: 2-4 hours

---

## Pre-release Versions

### Alpha Releases

**Format:** `1.0.0-alpha.1`

- **Stability**: Unstable, breaking changes expected
- **Purpose**: Early testing, gather feedback
- **Support**: None

### Beta Releases

**Format:** `1.0.0-beta.1`

- **Stability**: Feature complete, may have bugs
- **Purpose**: Final testing before release
- **Support**: Bug fixes only

### Release Candidates

**Format:** `1.0.0-rc.1`

- **Stability**: Production-ready, final testing
- **Purpose**: Last chance to find critical issues
- **Support**: Critical bug fixes only

### Using Pre-releases

```bash
# Opt-in to pre-releases
composer require gregpriday/laravel-work-manager:^1.0@beta

# Production: only use stable releases
composer require gregpriday/laravel-work-manager:^1.0
```

---

## Questions About Versioning?

If you're unsure about version compatibility:

1. Check [CHANGELOG.md](../../CHANGELOG.md)
2. Read migration guides for MAJOR releases
3. Search [GitHub issues](https://github.com/gregpriday/laravel-work-manager/issues)
4. Ask in GitHub Discussions

---

## Summary

**Simple Rules:**
- **MAJOR**: Breaking changes, plan for migration
- **MINOR**: New features, safe to upgrade
- **PATCH**: Bug fixes, always safe to upgrade

**Within same MAJOR version:**
- ✓ Backwards compatible
- ✓ Safe to upgrade
- ✓ Config files work
- ✓ Database schema compatible

**Upgrading MAJOR versions:**
- ✗ May have breaking changes
- ✓ Migration guide provided
- ✓ Deprecation warnings given in advance
- ✓ Minimum 3 months notice
