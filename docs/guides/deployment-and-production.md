# Deployment and Production Guide

**By the end of this guide, you'll be able to:** Deploy Work Manager to production, configure supervisor, scale the system, and implement monitoring.

---

## Production Configuration

### 1. Environment Variables

```bash
# .env.production
APP_ENV=production
APP_DEBUG=false

# Work Manager
WORK_MANAGER_LEASE_BACKEND=redis
WORK_MANAGER_REDIS_CONNECTION=work
WORK_MANAGER_QUEUE_CONNECTION=redis
WORK_MANAGER_MAX_LEASES_PER_AGENT=5
```

### 2. Cache Config

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 3. Optimize Autoloader

```bash
composer install --optimize-autoloader --no-dev
```

---

## Supervisor Setup

### Queue Workers

`/etc/supervisor/conf.d/work-manager-queue.conf`:

```ini
[program:work-manager-queue]
command=php /var/www/app/artisan queue:work redis --queue=work:maintenance,work:planning,default --tries=3 --timeout=300
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/queue-worker.log
```

### MCP Server (if using HTTP mode)

`/etc/supervisor/conf.d/work-manager-mcp.conf`:

```ini
[program:work-manager-mcp]
command=php /var/www/app/artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/mcp-server.log
```

Apply changes:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

---

## Scheduler

Ensure cron is configured:

```bash
crontab -e
```

Add:

```
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

Verify scheduler tasks in `app/Console/Kernel.php`:

```php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
$schedule->command('work-manager:maintain')->everyMinute();
```

---

## Scaling

### Horizontal Scaling

- Run multiple queue workers
- Use Redis for leases (better concurrency)
- Use dedicated Redis for queues
- Load balance HTTP API endpoints

### Vertical Scaling

- Increase `max_leases_per_agent`
- Increase `max_leases_per_type`
- Optimize database indexes
- Use read replicas for queries

---

## Monitoring

### Health Checks

```bash
# Check queue workers
supervisorctl status work-manager-queue

# Check database
php artisan tinker
>>> WorkOrder::count()

# Check Redis
redis-cli ping
```

### Logging

Monitor logs:

```bash
tail -f storage/logs/laravel.log
tail -f storage/logs/queue-worker.log
```

### Metrics

Configure metrics driver:

```php
'metrics' => [
    'enabled' => true,
    'driver' => 'statsd',
    'namespace' => 'work_manager',
],
```

---

## See Also

- [Configuration Guide](configuration.md)
- [MCP Server Integration](mcp-server-integration.md)
- [Environment Variables Guide](environment-variables.md)
- Main [README.md](../../README.md)
