# Release Process

This document describes how Laravel Work Manager releases are managed, including versioning strategy, release cadence, and changelog maintenance.

## Versioning Strategy

Laravel Work Manager follows [Semantic Versioning 2.0.0](https://semver.org/).

### Version Format: MAJOR.MINOR.PATCH

- **MAJOR** (1.x.x): Breaking changes, major architectural changes
- **MINOR** (x.1.x): New features, non-breaking changes, significant improvements
- **PATCH** (x.x.1): Bug fixes, security patches, documentation updates

### Examples

```
1.0.0 → 1.0.1  # Bug fix (compatible)
1.0.1 → 1.1.0  # New feature (compatible)
1.1.0 → 2.0.0  # Breaking change (incompatible)
```

---

## Release Cadence

### Major Releases (1.x → 2.x)

- **Frequency**: Annually or as needed for breaking changes
- **Notice**: 3+ months advance notice
- **Deprecation**: Features deprecated in MINOR releases before removal in MAJOR
- **Migration Guide**: Comprehensive upgrade guide provided

### Minor Releases (1.0 → 1.1)

- **Frequency**: Every 2-3 months
- **Content**: New features, improvements, non-breaking changes
- **Backward Compatibility**: Fully compatible with same MAJOR version

### Patch Releases (1.0.0 → 1.0.1)

- **Frequency**: As needed (typically weekly to monthly)
- **Content**: Bug fixes, security patches, documentation
- **Urgency**: Security patches released immediately

### Pre-releases

- **Alpha**: Early testing, unstable (1.0.0-alpha.1)
- **Beta**: Feature complete, testing period (1.0.0-beta.1)
- **RC**: Release candidate, final testing (1.0.0-rc.1)

---

## Supported Versions

| Version | Status      | Release Date | End of Support | Security Fixes Until |
|---------|-------------|--------------|----------------|---------------------|
| 1.x     | Active      | 2025-01-15   | TBD            | TBD                 |
| < 1.0   | Unsupported | N/A          | N/A            | N/A                 |

### Support Policy

- **Active support**: Bug fixes, security patches, new features
- **Security fixes only**: Security patches only, no bug fixes
- **Unsupported**: No updates

---

## Release Workflow

### 1. Planning Phase

**Milestone Creation:**
- Create GitHub milestone for target version (e.g., v1.2.0)
- Add planned issues/features to milestone
- Prioritize based on community feedback

**Feature Discussion:**
- Discuss major features in GitHub issues
- Solicit community feedback
- Create RFCs for significant changes

### 2. Development Phase

**Branch Strategy:**
- `main` branch: stable releases
- `develop` branch: next minor/major release
- Feature branches: specific features (`feature/workflow-support`)
- Hotfix branches: urgent fixes (`hotfix/lease-expiration-bug`)

**Development Process:**
1. Create feature branch from `develop`
2. Implement feature with tests
3. Submit PR to `develop`
4. Code review and approval
5. Merge to `develop`

### 3. Testing Phase

**Pre-release Testing:**
```bash
# Run full test suite
composer test

# Check code style
composer check-style

# Static analysis
composer analyse

# Manual testing
# - Install in fresh Laravel app
# - Test all examples
# - Test MCP server integration
# - Test production scenarios
```

**Beta Release:**
```bash
# Create beta tag
git tag -a v1.2.0-beta.1 -m "Release v1.2.0-beta.1"
git push origin v1.2.0-beta.1
```

**Community Testing:**
- Announce beta in GitHub discussions
- Request testing from community
- Collect feedback and issues
- Fix critical issues

### 4. Release Phase

**Pre-release Checklist:**
- [ ] All milestone issues closed
- [ ] All tests passing
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] Migration guide written (for MAJOR/MINOR)
- [ ] README.md version references updated
- [ ] Examples tested and updated
- [ ] No outstanding security issues

**Release Steps:**

1. **Update Version References:**
```bash
# Update version in relevant files
# composer.json, README badges, etc.
```

2. **Update CHANGELOG.md:**
```markdown
## [1.2.0] - 2025-03-15

### Added
- New workflow dependency system (#123)
- Multi-tenancy support (#145)

### Changed
- Improved Redis lease backend performance (#156)
- Updated validation error format (#167)

### Fixed
- Lease expiration race condition (#134)
- MCP server STDIO parsing issue (#178)

### Deprecated
- Legacy acceptancePolicy() method (use AbstractOrderType) (#189)

### Security
- Fixed potential SQL injection in order filtering (#198)
```

3. **Create Release Commit:**
```bash
git checkout develop
git pull origin develop

# Update files
vim CHANGELOG.md
vim README.md
# ... other files

git add .
git commit -m "Release v1.2.0"
git push origin develop
```

4. **Merge to Main:**
```bash
git checkout main
git merge develop
git push origin main
```

5. **Create Git Tag:**
```bash
git tag -a v1.2.0 -m "Release v1.2.0"
git push origin v1.2.0
```

6. **Create GitHub Release:**
- Go to https://github.com/gregpriday/laravel-work-manager/releases/new
- Select tag: v1.2.0
- Title: Version 1.2.0
- Description: Copy from CHANGELOG.md
- Attach any relevant files
- Publish release

7. **Packagist Update:**
- Packagist auto-detects new tags
- Verify package shows new version: https://packagist.org/packages/gregpriday/laravel-work-manager

### 5. Post-release

**Announcements:**
- GitHub Discussions post
- Twitter/X announcement
- Laravel News submission (for major releases)
- Update documentation site (if applicable)

**Monitoring:**
- Watch for bug reports
- Monitor GitHub issues
- Check community feedback

**Hotfixes:**
If critical issues discovered:
```bash
# Create hotfix branch from main
git checkout main
git checkout -b hotfix/critical-bug

# Fix issue
git commit -am "Fix critical bug"

# Merge to main
git checkout main
git merge hotfix/critical-bug

# Tag patch release
git tag -a v1.2.1 -m "Release v1.2.1"
git push origin v1.2.1

# Also merge back to develop
git checkout develop
git merge hotfix/critical-bug
git push origin develop
```

---

## Changelog Maintenance

### Format

We follow [Keep a Changelog](https://keepachangelog.com/) format.

### Categories

- **Added**: New features
- **Changed**: Changes in existing functionality
- **Deprecated**: Soon-to-be removed features
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes

### Guidelines

**Good changelog entries:**
```markdown
### Added
- Multi-tenancy support with automatic tenant isolation (#145)
- Workflow dependency system allowing DAG-based ordering (#123)

### Fixed
- Lease expiration race condition when multiple servers reclaim (#134)
- MCP server STDIO parsing issue with large payloads (#178)
```

**Poor changelog entries:**
```markdown
### Added
- New feature

### Fixed
- Fixed bug
```

**Reference Issues:**
Always link to GitHub issues/PRs: `(#123)`

### Unreleased Section

Keep an `[Unreleased]` section at top of CHANGELOG.md:

```markdown
## [Unreleased]

### Added
- Work in progress feature (#200)

### Fixed
- Bug fix pending release (#201)
```

This helps track changes between releases.

---

## Breaking Changes Policy

### What Constitutes a Breaking Change?

**Breaking changes include:**
- Removing public methods or classes
- Changing method signatures (parameters, return types)
- Changing database schema without migration path
- Changing configuration structure
- Changing behavior of existing features (without BC flag)

**Not breaking changes:**
- Adding new optional parameters
- Adding new methods
- Internal refactoring
- Performance improvements
- Bug fixes (even if behavior changes)
- Documentation updates

### Deprecation Process

**Before removing a feature:**

1. **Mark as deprecated** (in MINOR release):
```php
/**
 * @deprecated since 1.2.0, use newMethod() instead
 */
public function oldMethod(): void
{
    trigger_error(
        'oldMethod() is deprecated, use newMethod() instead',
        E_USER_DEPRECATED
    );

    return $this->newMethod();
}
```

2. **Document deprecation**:
```markdown
## [1.2.0] - 2025-03-15

### Deprecated
- `oldMethod()` is deprecated, use `newMethod()` instead (#156)
  Will be removed in 2.0.0
```

3. **Provide migration path**:
```markdown
### Migration Guide

Replace:
```php
$service->oldMethod();
```

With:
```php
$service->newMethod();
```

4. **Remove in next MAJOR** (minimum 3 months):
```markdown
## [2.0.0] - 2025-07-15

### Removed
- `oldMethod()` (deprecated since 1.2.0) (#234)
```

---

## Migration Guides

### Major Version Upgrades

Provide comprehensive migration guide for MAJOR releases.

**Example: 1.x to 2.x Migration Guide**

```markdown
# Migrating from 1.x to 2.0

## Breaking Changes

### 1. Removed Deprecated Methods

**`oldMethod()` removed**

Before:
```php
$service->oldMethod();
```

After:
```php
$service->newMethod();
```

### 2. Configuration Changes

**Lease configuration restructured**

Before:
```php
'lease_ttl' => 600,
```

After:
```php
'lease' => [
    'ttl_seconds' => 600,
],
```

### 3. Database Schema Changes

**Run new migrations:**
```bash
php artisan migrate
```

## Estimated Upgrade Time

- Small applications: 15-30 minutes
- Medium applications: 1-2 hours
- Large applications: 2-4 hours

## Support

If you encounter issues, please open a GitHub issue.
```

---

## Release Checklist Template

Copy this for each release:

```markdown
## Release v1.X.X Checklist

### Pre-release
- [ ] All milestone issues closed or moved
- [ ] All tests passing
- [ ] Code style checked
- [ ] Static analysis clean
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] Migration guide written (if needed)
- [ ] Version references updated
- [ ] Examples tested
- [ ] Beta testing completed (if MINOR/MAJOR)

### Release
- [ ] Created release commit
- [ ] Merged to main
- [ ] Created Git tag
- [ ] Created GitHub release
- [ ] Verified Packagist update
- [ ] Posted announcements

### Post-release
- [ ] Monitored for issues (24-48 hours)
- [ ] Addressed critical issues
- [ ] Updated documentation site (if applicable)
```

---

## Emergency Hotfix Process

For critical security issues or data-loss bugs:

1. **Assess severity** (within 1 hour)
2. **Create hotfix branch** from main
3. **Develop fix** with tests
4. **Fast-track review** (same day)
5. **Release immediately** (within 24 hours)
6. **Announce** with security advisory
7. **Backport** to supported versions

---

## Contributors and Credits

### Release Notes Format

Recognize contributors in release notes:

```markdown
## Contributors

Thanks to everyone who contributed to this release:

- @username - Feature X (#123)
- @another - Bug fix Y (#134)
- @someone - Documentation improvements (#145)

And to everyone who reported issues and provided feedback!
```

---

## Questions?

For questions about the release process:
- Create a GitHub issue with "release" label
- Email: greg@siteorigin.com
