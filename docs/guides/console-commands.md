# Console Commands Guide

**By the end of this guide, you'll be able to:** Run all Work Manager commands, schedule automation, and understand command options.

---

## Available Commands

1. `work-manager:generate` - Generate work orders
2. `work-manager:maintain` - Maintenance tasks
3. `work-manager:mcp` - MCP server

---

## work-manager:generate

Runs registered allocator strategies to create new work orders.

### Basic Usage

```bash
php artisan work-manager:generate
```

### Options

```bash
--dry-run    Show what would be generated without creating orders
```

### Example Output

```
Discovering work to be done...
Running strategy: App\Strategies\StaleDataAllocatorStrategy
  Discovered 5 work order(s)
  Created order: 9a7c... (user.data.sync)
  Created order: 9a7d... (user.data.sync)
Generated 5 work orders.
```

### Scheduling

In `app/Console/Kernel.php`:

```php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
```

### Creating Allocator Strategies

```php
// app/Strategies/StaleDataAllocatorStrategy.php
namespace App\Strategies;

use GregPriday\WorkManager\Contracts\AllocatorStrategy;

class StaleDataAllocatorStrategy implements AllocatorStrategy
{
    public function discoverWork(): array
    {
        // Find users with stale data
        $staleUsers = User::where('synced_at', '<', now()->subDays(7))->get();
        
        return $staleUsers->chunk(50)->map(fn($batch) => [
            'type' => 'user.data.sync',
            'payload' => ['user_ids' => $batch->pluck('id')->toArray()],
            'priority' => 5,
        ])->toArray();
    }
}
```

Register in `AppServiceProvider`:

```php
$this->app->tag([StaleDataAllocatorStrategy::class], 'work-manager.strategies');
```

---

## work-manager:maintain

Performs maintenance: reclaim leases, dead-letter failed work, alert on stale orders.

### Basic Usage

```bash
php artisan work-manager:maintain
```

Runs all tasks.

### Options

```bash
--reclaim-leases    Only reclaim expired leases
--dead-letter       Only dead-letter failed orders
--check-stale       Only check for stale orders
```

### Example Output

```
Reclaiming expired leases...
  Reclaimed 3 expired lease(s)
Processing failed orders for dead lettering...
  Dead lettered 1 order(s)/item(s)
Checking for stale orders...
  No stale orders found
```

### Scheduling

```php
$schedule->command('work-manager:maintain')->everyMinute();
```

---

## work-manager:mcp

Starts the MCP server for AI agent integration.

### stdio Mode (Local)

```bash
php artisan work-manager:mcp --transport=stdio
```

For Cursor, Claude Desktop, etc.

### HTTP Mode (Production)

```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

### Options

```bash
--transport=stdio|http    Transport mode (default: stdio)
--host=127.0.0.1          HTTP host (default: 127.0.0.1)
--port=8090               HTTP port (default: 8090)
```

See [MCP Server Integration Guide](mcp-server-integration.md) for details.

---

## Scheduling All Commands

Complete scheduler setup:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Generate work orders every 15 minutes
    $schedule->command('work-manager:generate')->everyFifteenMinutes();
    
    // Maintain every minute
    $schedule->command('work-manager:maintain')->everyMinute();
}
```

Ensure cron is running:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## See Also

- [MCP Server Integration](mcp-server-integration.md)
- [Leasing Guide](leasing-and-concurrency.md)
- [Configuration Guide](configuration.md)
