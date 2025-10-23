# Development Environment Setup

Quick setup guide for Laravel Work Manager development on **Ubuntu/Debian-based systems** (tested on Ubuntu 24.04).

## Prerequisites

- Ubuntu/Debian Linux with apt package manager
- Root or sudo access

## Installation (One Command)

Install all required dependencies:

```bash
apt-get update && apt-get install -y \
  php php-cli php-mbstring php-xml php-curl php-zip \
  php-mysql php-sqlite3 php-intl php8.3-xdebug \
  composer unzip
```

Configure Xdebug for code coverage:

```bash
echo "xdebug.mode=develop,coverage" >> /etc/php/8.3/mods-available/xdebug.ini
```

Install project dependencies:

```bash
cd /home/user/laravel-work-manager
composer install
```

## Verification

Verify installation:

```bash
php --version           # Should show PHP 8.3.x
composer --version      # Should show Composer 2.7.x
php -m | grep xdebug    # Should show xdebug
composer test           # Should run 300+ tests successfully
```

If all tests pass, your environment is ready!

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

**PHP/Composer not found**: Verify installation with `dpkg -l | grep -E "(php|composer)"`

**Xdebug not working**: Check with `php -i | grep "xdebug.mode"` (should show `develop,coverage`)

**Tests fail**: Ensure `phpunit.xml` exists in project root

## Notes for AI Agents

- Run commands with sudo/root privileges
- Verify each step with the verification commands above
- Setup takes ~5-10 minutes depending on network
- After setup, run `composer test` to verify environment
- Review `CLAUDE.md` for project-specific guidance
