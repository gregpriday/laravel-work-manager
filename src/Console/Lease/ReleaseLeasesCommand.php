<?php

namespace GregPriday\WorkManager\Console\Lease;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Services\LeaseService;
use Illuminate\Console\Command;

/**
 * Force-release leased items back to the queue.
 *
 * @internal Admin command for lease management
 *
 * @see docs/reference/commands-reference.md
 */
class ReleaseLeasesCommand extends Command
{
    protected $signature = 'work-manager:lease:release
        {--agent= : Only items leased by this agent ID}
        {--order= : Only items for this order UUID}
        {--type= : Only items of this type}
        {--older-than= : Only leases older than e.g. 10m, 2h}
        {--all : Release all currently leased items}
        {--dry-run : Show what would happen}';

    protected $description = 'Force-release leased items back to the queue';

    public function handle(LeaseService $leases): int
    {
        // Build a query of currently leased items
        $q = WorkItem::query()->whereNotNull('leased_by_agent_id');

        if ($agent = $this->option('agent')) {
            $q->where('leased_by_agent_id', $agent);
        }
        if ($order = $this->option('order')) {
            $q->where('order_id', $order);
        }
        if ($type = $this->option('type')) {
            $q->where('type', $type);
        }
        if ($age = $this->option('older-than')) {
            $cutoff = $this->parseOlderThan($age);
            $q->where('lease_expires_at', '<', $cutoff);
        }

        if (! $this->option('all') && ! $agent && ! $order && ! $type && ! $age) {
            $this->warn('Refusing to act without a selector; pass --all to affect everything.');

            return self::FAILURE;
        }

        $items = $q->get();

        $this->info("Found {$items->count()} leased item(s).");

        if ($this->option('dry-run')) {
            foreach ($items as $i) {
                $this->line("DRY-RUN would release item {$i->id} (agent={$i->leased_by_agent_id})");
            }

            return self::SUCCESS;
        }

        // Release each via LeaseService
        $released = 0;
        $failed = 0;

        foreach ($items as $i) {
            try {
                $leases->release($i->id, $i->leased_by_agent_id);
                $this->line("Released item {$i->id} (was agent={$i->leased_by_agent_id})");
                $released++;
            } catch (\Exception $e) {
                $this->error("Failed to release item {$i->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Released {$released} item(s)".($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }

    /**
     * Parse "older-than" time specification into a Carbon date.
     */
    protected function parseOlderThan(string $spec): \Illuminate\Support\Carbon
    {
        // Support formats like: 10m, 2h, 1d, 30s
        if (preg_match('/^(\d+)([smhd])$/', $spec, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                's' => now()->subSeconds($value),
                'm' => now()->subMinutes($value),
                'h' => now()->subHours($value),
                'd' => now()->subDays($value),
                default => throw new \InvalidArgumentException("Invalid time unit: {$unit}"),
            };
        }

        throw new \InvalidArgumentException("Invalid time format: {$spec}. Use format like 10m, 2h, 1d");
    }
}
