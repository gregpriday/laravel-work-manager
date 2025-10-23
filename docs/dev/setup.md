# Development Environment Setup

Quick setup guide for Laravel Work Manager development on **Ubuntu/Debian-based systems** (tested on Ubuntu 24.04).

## Prerequisites

- Ubuntu/Debian Linux with apt package manager
- Root or sudo access
- Project cloned to local directory

## Three-Step Installation

**Step 1:** Install PHP, Composer, and extensions:

```bash
apt-get update && apt-get install -y php php-cli php-mbstring php-xml php-curl php-zip php-mysql php-sqlite3 php-intl php8.3-xdebug composer unzip
```

**Step 2:** Configure Xdebug for code coverage:

```bash
echo "xdebug.mode=develop,coverage" >> /etc/php/8.3/mods-available/xdebug.ini
```

**Step 3:** Install project dependencies (run from project root):

```bash
composer install
```

## Verification

Run all verification commands:

```bash
php --version && composer --version && php -m | grep xdebug
```

**Expected output:**
```
PHP 8.3.6 (cli) ...
    with Xdebug v3.2.0 ...
---
Composer version 2.7.1 ...
---
xdebug
```

Run the test suite:

```bash
composer test
```

**Expected output:**
```
Tests: 521 passed (1452 assertions)
Duration: ~40s
```

✅ If you see **520+ tests passing**, your environment is ready!

## What Gets Installed

- **PHP 8.3.x** with extensions: mbstring, xml, curl, zip, mysql, sqlite3, intl
- **Composer 2.7.x** for dependency management
- **Xdebug 3.2.x** with coverage mode enabled
- **Pest 3.x** and **PHPUnit 11.x** testing frameworks (via composer)

## Common Test Commands

```bash
composer test                                     # Run all tests
vendor/bin/pest --coverage                        # Run with coverage report
vendor/bin/pest tests/Services/WorkAllocatorTest.php  # Run specific test
vendor/bin/pest tests/Feature/                    # Run feature tests only
```

## Troubleshooting

**apt-get fails with 403 Forbidden error:**
```bash
# Remove problematic PPA files and retry
rm /etc/apt/sources.list.d/*deadsnakes* 2>/dev/null || true
apt-get update
# Then re-run installation command
```

**PHP/Composer not found after install:**
```bash
dpkg -l | grep -E "(php|composer)"  # Verify packages installed
which php composer                   # Check if in PATH
```

**Xdebug not configured:**
```bash
php -i | grep "xdebug.mode"
# Should show: xdebug.mode => develop,coverage => develop,coverage
# If not, re-run: echo "xdebug.mode=develop,coverage" >> /etc/php/8.3/mods-available/xdebug.ini
```

**Composer install fails:**
```bash
composer install --no-cache  # Clear cache and retry
composer diagnose            # Check for issues
```

**Tests fail with missing dependencies:**
- Ensure you ran `composer install` successfully
- Check that `vendor/` directory exists and is populated
- Verify `phpunit.xml` exists in project root

## Quick Reference

**Full setup (copy-paste friendly):**
```bash
# Install dependencies
apt-get update && apt-get install -y php php-cli php-mbstring php-xml php-curl php-zip php-mysql php-sqlite3 php-intl php8.3-xdebug composer unzip

# Configure Xdebug
echo "xdebug.mode=develop,coverage" >> /etc/php/8.3/mods-available/xdebug.ini

# Install project packages (from project root)
composer install

# Verify
php --version && composer --version && php -m | grep xdebug && composer test
```

**Success criteria:**
- ✅ PHP 8.3.6+ installed
- ✅ Composer 2.7.1+ installed
- ✅ Xdebug 3.2.0+ with coverage mode
- ✅ 520+ tests passing (~40 seconds)

**Estimated time:** 5-10 minutes (depending on network speed)

## Notes for AI Agents

- Run with sudo/root privileges
- Execute from project root directory
- If any step fails, check Troubleshooting section above
- After successful setup, review `CLAUDE.md` for project-specific guidance
