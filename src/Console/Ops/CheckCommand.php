<?php

namespace GregPriday\WorkManager\Console\Ops;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quick health probe for WorkManager system.
 *
 * @internal Admin command for health monitoring
 *
 * @see docs/reference/commands-reference.md
 */
class CheckCommand extends Command
{
    protected $signature = 'work-manager:ops:check
        {--json : Output as JSON}';

    protected $description = 'Check WorkManager system health';

    public function handle(): int
    {
        $health = [
            'timestamp' => now()->toIso8601String(),
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'orders' => $this->getOrderStats(),
            'items' => $this->getItemStats(),
            'leases' => $this->getLeaseStats(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayHealthReport($health);

        // Determine overall health status
        $isHealthy = $health['database']['status'] === 'ok' &&
                     $health['leases']['expired'] < 10;

        return $isHealthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Check database connectivity.
     */
    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'status' => 'ok',
                'connection' => config('database.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connectivity if Redis lease backend is configured.
     */
    protected function checkRedis(): array
    {
        $backend = config('work-manager.lease.backend', 'database');

        if ($backend !== 'redis') {
            return ['status' => 'not_configured'];
        }

        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $redis->ping();

            return [
                'status' => 'ok',
                'backend' => $backend,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get order statistics.
     */
    protected function getOrderStats(): array
    {
        return [
            'total' => WorkOrder::count(),
            'queued' => WorkOrder::where('state', 'queued')->count(),
            'in_progress' => WorkOrder::where('state', 'in_progress')->count(),
            'submitted' => WorkOrder::where('state', 'submitted')->count(),
            'completed' => WorkOrder::where('state', 'completed')->count(),
            'failed' => WorkOrder::where('state', 'failed')->count(),
            'dead_lettered' => WorkOrder::where('state', 'dead_lettered')->count(),
        ];
    }

    /**
     * Get item statistics.
     */
    protected function getItemStats(): array
    {
        return [
            'total' => WorkItem::count(),
            'queued' => WorkItem::where('state', 'queued')->count(),
            'leased' => WorkItem::where('state', 'leased')->count(),
            'in_progress' => WorkItem::where('state', 'in_progress')->count(),
            'submitted' => WorkItem::where('state', 'submitted')->count(),
            'completed' => WorkItem::where('state', 'completed')->count(),
            'failed' => WorkItem::where('state', 'failed')->count(),
        ];
    }

    /**
     * Get lease statistics.
     */
    protected function getLeaseStats(): array
    {
        $activeLeases = WorkItem::whereNotNull('leased_by_agent_id')
            ->where('lease_expires_at', '>', now())
            ->count();

        $expiredLeases = WorkItem::withExpiredLease()->count();

        return [
            'active' => $activeLeases,
            'expired' => $expiredLeases,
        ];
    }

    /**
     * Display formatted health report.
     */
    protected function displayHealthReport(array $health): void
    {
        $this->info('=== WorkManager Health Check ===');
        $this->newLine();

        // Database
        $dbStatus = $health['database']['status'] === 'ok' ? '<fg=green>OK</>' : '<fg=red>ERROR</>';
        $this->line("Database: {$dbStatus} ({$health['database']['connection']})");

        // Redis
        if ($health['redis']['status'] === 'not_configured') {
            $this->line('Redis: <fg=gray>Not configured</> (using database lease backend)');
        } elseif ($health['redis']['status'] === 'ok') {
            $this->line('Redis: <fg=green>OK</>');
        } else {
            $this->line('Redis: <fg=red>ERROR</> - '.$health['redis']['error']);
        }

        $this->newLine();

        // Orders
        $this->line('<fg=cyan>Orders:</>');
        $this->line("  Total: {$health['orders']['total']}");
        $this->line("  Queued: {$health['orders']['queued']}");
        $this->line("  In Progress: {$health['orders']['in_progress']}");
        $this->line("  Submitted: {$health['orders']['submitted']}");
        $this->line("  Completed: {$health['orders']['completed']}");

        if ($health['orders']['failed'] > 0) {
            $this->line("  <fg=red>Failed: {$health['orders']['failed']}</>");
        }
        if ($health['orders']['dead_lettered'] > 0) {
            $this->line("  <fg=red>Dead Lettered: {$health['orders']['dead_lettered']}</>");
        }

        $this->newLine();

        // Items
        $this->line('<fg=cyan>Items:</>');
        $this->line("  Total: {$health['items']['total']}");
        $this->line("  Queued: {$health['items']['queued']}");
        $this->line("  Leased: {$health['items']['leased']}");
        $this->line("  In Progress: {$health['items']['in_progress']}");
        $this->line("  Submitted: {$health['items']['submitted']}");
        $this->line("  Completed: {$health['items']['completed']}");

        if ($health['items']['failed'] > 0) {
            $this->line("  <fg=red>Failed: {$health['items']['failed']}</>");
        }

        $this->newLine();

        // Leases
        $this->line('<fg=cyan>Leases:</>');
        $this->line("  Active: {$health['leases']['active']}");

        if ($health['leases']['expired'] > 0) {
            $this->line("  <fg=yellow>Expired: {$health['leases']['expired']}</> (run work-manager:maintain to reclaim)");
        } else {
            $this->line("  Expired: {$health['leases']['expired']}");
        }
    }
}
