# Commands Reference

Complete documentation of all Artisan commands provided by Laravel Work Manager.

## Table of Contents

- [work-manager:generate](#work-managergenerate)
- [work-manager:maintain](#work-managermaintain)
- [work-manager:mcp](#work-managermcp)
- [Scheduling Commands](#scheduling-commands)
- [Exit Codes](#exit-codes)

---

## work-manager:generate

Generate work orders based on registered allocator strategies.

### Signature

```bash
php artisan work-manager:generate [--dry-run]
```

### Description

Discovers and creates work orders by executing all registered `AllocatorStrategy` and `PlannerPort` implementations. This command is typically run on a schedule to automatically generate work based on your application's business logic.

### Options

| Option | Short | Description |
|--------|-------|-------------|
| `--dry-run` | - | Show what would be generated without creating orders |

### How It Works

1. Resolves all registered strategies from the service container (tagged with `work-manager.strategies`)
2. Calls `discoverWork()` or `generateOrders()` on each strategy
3. Validates work specifications returned by strategies
4. Creates work orders via `WorkAllocator::propose()`
5. Plans each order into discrete work items

### Registering Strategies

**In `AppServiceProvider::boot()`:**

```php
use GregPriday\WorkManager\Contracts\AllocatorStrategy;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tag strategies for discovery
        $this->app->tag([
            UserSyncStrategy::class,
            DataCleanupStrategy::class,
        ], 'work-manager.strategies');
    }
}
```

**Example Strategy Implementation:**

```php
use GregPriday\WorkManager\Contracts\AllocatorStrategy;

class UserSyncStrategy implements AllocatorStrategy
{
    public function discoverWork(): array
    {
        // Check if sync is needed
        if (!$this->shouldSync()) {
            return [];
        }

        return [
            [
                'type' => 'user.data.sync',
                'payload' => [
                    'source' => 'external-api',
                    'filters' => ['active' => true],
                ],
                'meta' => [
                    'generated_by' => 'user-sync-strategy',
                ],
                'priority' => 10,
            ],
        ];
    }

    protected function shouldSync(): bool
    {
        // Your business logic
        return User::where('needs_sync', true)->exists();
    }
}
```

### Output Examples

**Normal Run:**

```
Discovering work to be done...
Running strategy: App\Strategies\UserSyncStrategy
  Discovered 2 work order(s)
  Created order: 01234567-89ab-cdef-0123-456789abcdef (user.data.sync)
  Created order: fedcba98-7654-3210-fedc-ba9876543210 (user.data.sync)
Running strategy: App\Strategies\DataCleanupStrategy
  No work discovered
Generated 2 work orders.
```

**Dry Run:**

```
Discovering work to be done...
Running strategy: App\Strategies\UserSyncStrategy
  Discovered 2 work order(s)
  [DRY RUN] Would create: user.data.sync
  [DRY RUN] Would create: user.data.sync
Running strategy: App\Strategies\DataCleanupStrategy
  No work discovered
Dry run complete. Would have created 2 orders.
```

**No Strategies Registered:**

```
Discovering work to be done...
No allocator strategies registered. Register strategies in your AppServiceProvider.
```

**Strategy Error:**

```
Discovering work to be done...
Running strategy: App\Strategies\UserSyncStrategy
  Error: Order type 'invalid.type' is not registered
Generated 0 work orders.
```

### Exit Codes

| Code | Status | Description |
|------|--------|-------------|
| 0 | SUCCESS | Command completed successfully (even if no work generated) |

### Scheduling

Schedule this command to run periodically in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run every 15 minutes
    $schedule->command('work-manager:generate')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->runInBackground();

    // Or daily at 2am
    $schedule->command('work-manager:generate')
        ->dailyAt('02:00')
        ->timezone('America/New_York');
}
```

### Usage Examples

**Manual Execution:**
```bash
php artisan work-manager:generate
```

**Test Strategy Without Creating Orders:**
```bash
php artisan work-manager:generate --dry-run
```

**Run via Schedule:**
```bash
php artisan schedule:run
```

---

## work-manager:maintain

Perform maintenance tasks on work orders and items.

### Signature

```bash
php artisan work-manager:maintain
    [--reclaim-leases]
    [--dead-letter]
    [--check-stale]
```

### Description

Performs essential maintenance operations to keep the work order system healthy. If no options are specified, all maintenance tasks are run.

### Options

| Option | Short | Description |
|--------|-------|-------------|
| `--reclaim-leases` | - | Reclaim expired leases and return items to queue |
| `--dead-letter` | - | Move failed orders/items to dead letter queue |
| `--check-stale` | - | Check for stale orders and emit alerts |

### Maintenance Tasks

#### 1. Reclaim Expired Leases

**What it does:**
- Finds work items with leases that have expired
- Increments attempt counter
- If max attempts exceeded: transitions item to `failed` state
- Otherwise: releases lease and returns item to `queued` state
- Records `lease_expired` events

**Configuration:**
```php
// config/work-manager.php
'lease' => [
    'ttl_seconds' => 600,  // Leases expire after 10 minutes
],
'retry' => [
    'default_max_attempts' => 3,  // Max retry attempts
],
```

**Output Example:**
```
Reclaiming expired leases...
  Reclaimed 5 expired lease(s)
```

#### 2. Dead Letter Failed Orders/Items

**What it does:**
- Finds orders/items in `failed` state older than threshold
- Transitions them to `dead_lettered` state
- Prevents failed work from clogging the system

**Configuration:**
```php
// config/work-manager.php
'maintenance' => [
    'dead_letter_after_hours' => 48,  // 2 days
],
```

**Output Example:**
```
Processing failed orders for dead lettering...
  Dead lettered order: 01234567-89ab-cdef-0123-456789abcdef
  Dead lettered 3 order(s)/item(s)
```

#### 3. Check Stale Orders

**What it does:**
- Finds non-terminal orders older than threshold
- Logs warnings with order IDs
- Emits alerts if `enable_alerts` is true

**Configuration:**
```php
// config/work-manager.php
'maintenance' => [
    'stale_order_threshold_hours' => 24,  // 1 day
    'enable_alerts' => true,
],
```

**Output Example:**
```
Checking for stale orders...
  Found 2 stale order(s):
    - 01234567-89ab-cdef-0123-456789abcdef (user.data.sync, submitted, created 2 days ago)
    - fedcba98-7654-3210-fedc-ba9876543210 (user.data.sync, in_progress, created 1 day ago)
```

**No Stale Orders:**
```
Checking for stale orders...
  No stale orders found
```

### Exit Codes

| Code | Status | Description |
|------|--------|-------------|
| 0 | SUCCESS | All maintenance tasks completed successfully |

### Scheduling

Schedule this command to run frequently in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run all maintenance tasks every minute
    $schedule->command('work-manager:maintain')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();

    // Or run specific tasks separately
    $schedule->command('work-manager:maintain --reclaim-leases')
        ->everyMinute();

    $schedule->command('work-manager:maintain --dead-letter --check-stale')
        ->hourly();
}
```

### Usage Examples

**Run All Maintenance Tasks:**
```bash
php artisan work-manager:maintain
```

**Reclaim Leases Only:**
```bash
php artisan work-manager:maintain --reclaim-leases
```

**Dead Letter and Check Stale (Skip Lease Reclamation):**
```bash
php artisan work-manager:maintain --dead-letter --check-stale
```

**Run via Schedule:**
```bash
php artisan schedule:run
```

### Monitoring and Alerts

**Listening for Stale Order Alerts:**

```php
// In EventServiceProvider
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function () {
    Log::channel('slack')->warning('Stale work orders detected');
});
```

**Custom Alert Logic:**

Create a listener to process stale order alerts and send notifications to your preferred channel (Slack, PagerDuty, etc.).

---

## work-manager:mcp

Start the MCP server for AI agent integration.

### Signature

```bash
php artisan work-manager:mcp
    [--transport=stdio]
    [--host=127.0.0.1]
    [--port=8090]
```

### Description

Starts the Model Context Protocol (MCP) server, exposing work order operations as tools for AI agents. Supports both STDIO transport (for local AI IDEs like Cursor, Claude Desktop) and HTTP transport (for remote agents).

### Options

| Option | Short | Type | Default | Description |
|--------|-------|------|---------|-------------|
| `--transport` | - | string | `stdio` | Transport type: `stdio` or `http` |
| `--host` | - | string | `127.0.0.1` | Host to bind to (HTTP transport only) |
| `--port` | - | integer | `8090` | Port to listen on (HTTP transport only) |

### Transport Types

#### STDIO Transport

**Use Case:** Local AI IDEs (Cursor, Claude Desktop, VS Code extensions)

**How it works:**
- Communicates via standard input/output streams
- Single client connection
- Suitable for desktop development environments

**Command:**
```bash
php artisan work-manager:mcp --transport=stdio
```

**Output:**
```
Starting Work Manager MCP Server...

Transport: STDIO
Server Name: Laravel Work Manager
Version: 1.0.0

The server is now listening on STDIN/STDOUT.
Connect your MCP client to this process.

⚠️  Do not write to stdout in your handlers when using stdio transport!
```

**Configuration for Claude Desktop:**

Add to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "work-manager": {
      "command": "php",
      "args": [
        "/path/to/your/project/artisan",
        "work-manager:mcp",
        "--transport=stdio"
      ]
    }
  }
}
```

#### HTTP Transport

**Use Case:** Remote agents, production deployments, multiple concurrent clients

**How it works:**
- Runs dedicated HTTP server
- Supports Server-Sent Events (SSE) for real-time communication
- Multiple concurrent client connections
- Scalable for production use

**Command:**
```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

**Output:**
```
Starting Work Manager MCP Server...

Transport: HTTP (Dedicated Server)
Host: 0.0.0.0
Port: 8090
Server Name: Laravel Work Manager
Version: 1.0.0

MCP server is starting at http://0.0.0.0:8090

Available endpoints:
  - GET  http://0.0.0.0:8090/mcp/sse
  - POST http://0.0.0.0:8090/mcp/message

Press Ctrl+C to stop the server
```

**HTTP Endpoints:**

| Method | Path | Description |
|--------|------|-------------|
| GET | `/mcp/sse` | Server-Sent Events stream for real-time updates |
| POST | `/mcp/message` | Send MCP protocol messages |

### Available MCP Tools

The MCP server exposes 13 tools for AI agents:

| Tool | Description |
|------|-------------|
| `work.propose` | Propose a new work order |
| `work.list` | List work orders with filters |
| `work.get` | Get details of a specific work order |
| `work.checkout` | Checkout (lease) the next available work item |
| `work.heartbeat` | Extend lease on a work item |
| `work.submit` | Submit work item results |
| `work.submit_part` | Submit a partial result |
| `work.list_parts` | List all parts for a work item |
| `work.finalize` | Finalize and assemble all parts |
| `work.approve` | Approve a work order |
| `work.reject` | Reject a work order |
| `work.release` | Release a work item lease |
| `work.logs` | Get event logs for a work item |

### Exit Codes

| Code | Status | Description |
|------|--------|-------------|
| 0 | SUCCESS | Server started successfully (runs until interrupted) |
| 1 | FAILURE | Failed to start server (invalid transport, port in use, etc.) |

### Usage Examples

**Local Development (STDIO):**
```bash
php artisan work-manager:mcp
```

**Production HTTP Server:**
```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

**Custom Port:**
```bash
php artisan work-manager:mcp --transport=http --port=9000
```

**Bind to Specific Interface:**
```bash
php artisan work-manager:mcp --transport=http --host=192.168.1.100 --port=8090
```

### Production Deployment

**Using Supervisor:**

Create `/etc/supervisor/conf.d/work-manager-mcp.conf`:

```ini
[program:work-manager-mcp]
process_name=%(program_name)s
command=php /var/www/html/artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/mcp.log
stopwaitsecs=3600
```

Start the service:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start work-manager-mcp
```

**Using systemd:**

Create `/etc/systemd/system/work-manager-mcp.service`:

```ini
[Unit]
Description=Work Manager MCP Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php /var/www/html/artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable work-manager-mcp
sudo systemctl start work-manager-mcp
```

**Behind Reverse Proxy (nginx):**

```nginx
upstream mcp_server {
    server 127.0.0.1:8090;
}

server {
    listen 80;
    server_name mcp.example.com;

    location /mcp/ {
        proxy_pass http://mcp_server;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_buffering off;
        proxy_cache off;
    }
}
```

---

## Scheduling Commands

Recommended schedule configuration in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Generate work orders every 15 minutes
    $schedule->command('work-manager:generate')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->runInBackground()
        ->onFailure(function () {
            // Alert on failure
            Log::error('Work order generation failed');
        });

    // Reclaim expired leases every minute
    $schedule->command('work-manager:maintain --reclaim-leases')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();

    // Dead letter and check stale every hour
    $schedule->command('work-manager:maintain --dead-letter --check-stale')
        ->hourly()
        ->withoutOverlapping();

    // Or run all maintenance tasks every minute
    $schedule->command('work-manager:maintain')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
```

**Testing the Schedule:**

```bash
# See scheduled commands
php artisan schedule:list

# Run scheduled commands manually
php artisan schedule:run

# Run schedule worker (continuously)
php artisan schedule:work
```

---

## Exit Codes

All commands follow standard Unix exit code conventions:

| Code | Status | Description |
|------|--------|-------------|
| 0 | `Command::SUCCESS` | Command completed successfully |
| 1 | `Command::FAILURE` | Command failed due to error |

**Using Exit Codes in Scripts:**

```bash
#!/bin/bash

php artisan work-manager:generate
if [ $? -eq 0 ]; then
    echo "Generation successful"
else
    echo "Generation failed"
    exit 1
fi
```

---

## Debugging Commands

**Verbose Output:**

All commands support Laravel's standard verbosity flags:

```bash
# Normal output
php artisan work-manager:generate

# Verbose output
php artisan work-manager:generate -v

# Very verbose
php artisan work-manager:generate -vv

# Debug output
php artisan work-manager:generate -vvv
```

**Logging:**

Commands log to Laravel's default log channel. Check logs:

```bash
tail -f storage/logs/laravel.log
```

**Testing Commands:**

```bash
# Test in isolated environment
php artisan work-manager:generate --dry-run

# Test maintenance without side effects (view output only)
php artisan work-manager:maintain --check-stale
```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Config Reference](./config-reference.md) - Configuration options
- [Events Reference](./events-reference.md) - Event documentation
- [MCP Server Documentation](../MCP_SERVER.md) - MCP integration guide
