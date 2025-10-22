# Upgrading Between Versions Guide

**By the end of this guide, you'll be able to:** Upgrade Laravel Work Manager safely, understand breaking changes, and execute migration steps.

---

## General Upgrade Process

### Step 1: Update Package

```bash
composer update gregpriday/laravel-work-manager
```

### Step 2: Publish New Migrations (if any)

```bash
php artisan vendor:publish --tag=work-manager-migrations --force
```

Review the migrations before running:

```bash
ls database/migrations/*work*
```

### Step 3: Run Migrations

```bash
php artisan migrate
```

### Step 4: Update Config

```bash
# Backup your current config
cp config/work-manager.php config/work-manager.php.backup

# Publish new config
php artisan vendor:publish --tag=work-manager-config --force

# Manually merge your customizations
diff config/work-manager.php.backup config/work-manager.php
```

### Step 5: Clear Caches

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### Step 6: Test

```bash
composer test
```

---

## Version-Specific Upgrades

### From v0.x to v1.0

**Breaking Changes**:
- Partial submissions feature added (enabled by default)
- New tables: `work_item_parts`
- Config structure changed for `idempotency.enforce_on`

**Migration Steps**:

1. Run new migrations:
   ```bash
   php artisan migrate
   ```

2. Update config - add partial submissions enforcement:
   ```php
   'idempotency' => [
       'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
   ],
   ```

3. If you don't need partials, disable:
   ```php
   'partials' => [
       'enabled' => false,
   ],
   ```

---

## Rollback

If upgrade causes issues:

```bash
# Rollback migrations
php artisan migrate:rollback --step=1

# Downgrade package
composer require gregpriday/laravel-work-manager:^0.9
```

---

## See Also

- [Configuration Guide](configuration.md)
- [Database and Migrations Guide](database-and-migrations.md)
- GitHub [Releases](https://github.com/gregpriday/laravel-work-manager/releases)
- GitHub [Changelog](https://github.com/gregpriday/laravel-work-manager/blob/main/CHANGELOG.md)
