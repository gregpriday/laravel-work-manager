# Development Environment Setup

This guide provides step-by-step instructions for setting up a development environment for Laravel Work Manager on **Ubuntu/Debian-based systems** (tested on Ubuntu 24.04 Noble).

## Prerequisites

This setup assumes you have:
- Ubuntu/Debian-based Linux distribution
- Root or sudo access
- apt package manager available

## Quick Setup

If you're an AI agent or developer setting up this environment for the first time, follow these steps to get a complete working environment with PHP, Composer, Xdebug, and all necessary dependencies.

## Step 1: Install PHP 8.3

Install PHP 8.3 with all required extensions for Laravel development:

```bash
# Update package lists
apt-get update

# Install PHP 8.3 with essential extensions
apt-get install -y php php-cli php-mbstring php-xml php-curl php-zip php-mysql php-sqlite3 php-intl unzip
```

This installs:
- **php** and **php-cli**: PHP command-line interpreter
- **php-mbstring**: Multibyte string handling
- **php-xml**: XML parsing and manipulation
- **php-curl**: HTTP client functionality
- **php-zip**: Archive handling
- **php-mysql**: MySQL database support
- **php-sqlite3**: SQLite support (used for testing)
- **php-intl**: Internationalization support
- **unzip**: For extracting archives

### Verify PHP Installation

```bash
php --version
```

Expected output: `PHP 8.3.x (cli) ...`

## Step 2: Install Composer

Install Composer for PHP dependency management:

```bash
apt-get install -y composer
```

This installs Composer 2.7.x from the Ubuntu repository along with all necessary PHP dependencies.

### Verify Composer Installation

```bash
composer --version
```

Expected output: `Composer version 2.7.x`

## Step 3: Install Xdebug for Code Coverage

Install and configure Xdebug to enable code coverage reporting:

```bash
# Install Xdebug extension
apt-get install -y php8.3-xdebug

# Configure Xdebug for coverage mode
echo "xdebug.mode=develop,coverage" >> /etc/php/8.3/mods-available/xdebug.ini
```

### Verify Xdebug Installation

```bash
php -m | grep xdebug
php -i | grep "xdebug.mode"
```

Expected output should show `xdebug` in the modules list and `xdebug.mode => develop,coverage`.

## Step 4: Install Project Dependencies

Navigate to the project directory and install PHP dependencies:

```bash
cd /path/to/laravel-work-manager
composer install
```

This will:
- Install all PHP packages defined in `composer.json`
- Generate the autoload files
- Set up the development dependencies including Pest, PHPUnit, Orchestra Testbench, and MCP server

## Step 5: Verify Setup

Run the test suite to ensure everything is working:

```bash
composer test
# or directly
vendor/bin/pest
```

If tests run successfully (300+ passing tests), your environment is ready!

### Running Tests with Coverage

To generate code coverage reports:

```bash
vendor/bin/pest --coverage
```

## Configuration Files

The following files should exist in your project:

### phpunit.xml

If `phpunit.xml` doesn't exist, create it with this content:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="WorkManager Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

### .gitignore Updates

Ensure `.gitignore` includes the PHPUnit cache directory:

```
vendor/
composer.lock
.phpunit.result.cache
.phpunit.cache/
.DS_Store
.idea
```

## Installed Software Summary

After completing this setup, you will have:

- **PHP 8.3.6** with extensions:
  - mbstring, xml, curl, zip, mysql, sqlite3, intl, opcache
- **Composer 2.7.1** for dependency management
- **Xdebug 3.2.0** with coverage mode enabled
- **139 PHP packages** installed via Composer
- **Pest 3.x** test framework
- **PHPUnit 11.x** testing framework
- **Orchestra Testbench** for Laravel package testing

## Common Commands

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/pest tests/Services/WorkAllocatorTest.php

# Run tests with coverage
vendor/bin/pest --coverage

# Run tests for specific directory
vendor/bin/pest tests/Feature/

# Install/update dependencies
composer install
composer update
```

## Troubleshooting

### PHP Not Found

If `php` command is not found after installation:
```bash
which php
# Should return: /usr/bin/php
```

If not found, verify the installation:
```bash
dpkg -l | grep php
```

### Composer Not Found

If `composer` command is not found:
```bash
which composer
# Should return: /usr/bin/composer
```

### Tests Fail with XML Error

If you see "Could not read XML from file" errors, ensure `phpunit.xml` exists in the project root.

### Xdebug Coverage Not Working

Verify Xdebug mode configuration:
```bash
php -i | grep "xdebug.mode"
```

Should show: `xdebug.mode => develop,coverage => develop,coverage`

If not, edit `/etc/php/8.3/mods-available/xdebug.ini` and add:
```ini
xdebug.mode=develop,coverage
```

## Alternative PHP Versions

This guide uses PHP 8.3 as it's the stable version available in Ubuntu 24.04 repositories and is fully compatible with Laravel 11.x/12.x.

If you need a different PHP version, you would need to add third-party PPAs (like Ondrej's PHP repository), which may have network/permission restrictions in some environments.

## Notes for AI Agents

If you're an AI agent setting up this environment:

1. Run all commands with root/sudo privileges
2. Check each step completes successfully before proceeding
3. The environment should be Ubuntu/Debian with apt-get available
4. After setup, verify with `composer test`
5. Commit and push any new configuration files (phpunit.xml, .gitignore updates)
6. This setup takes approximately 5-10 minutes depending on network speed

## Next Steps

Once your environment is set up:

1. Review `CLAUDE.md` for project-specific guidance
2. Read `README.md` for package documentation
3. Check `docs/getting-started/quickstart.md` for usage examples
4. Explore `examples/` directory for order type implementations
