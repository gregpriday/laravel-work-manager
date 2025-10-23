<?php

namespace GregPriday\WorkManager\Console\Lease;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Services\LeaseService;
use Illuminate\Console\Command;

/**
 * Extend TTL for selected leases (useful during debugging).
 *
 * @internal Admin command for lease management
 *
 * @see docs/reference/commands-reference.md
 */
class ExtendLeasesCommand extends Command
{
    protected $signature = 'work-manager:lease:extend
        {--agent= : Only items leased by this agent ID (required unless --all)}
        {--order= : Only items for this order UUID}
        {--type= : Only items of this type}
        {--ttl=900 : TTL in seconds to extend to}
        {--all : Extend all currently leased items}
        {--dry-run : Show what would happen}';

    protected $description = 'Extend the TTL for selected leases';

    public function handle(LeaseService $leases): int
    {
        // Build a query of currently leased items
        $q = WorkItem::query()
            ->whereNotNull('leased_by_agent_id')
            ->where('lease_expires_at', '>', now());

        $agent = $this->option('agent');

        if ($agent) {
            $q->where('leased_by_agent_id', $agent);
        }
        if ($order = $this->option('order')) {
            $q->where('order_id', $order);
        }
        if ($type = $this->option('type')) {
            $q->where('type', $type);
        }

        if (! $this->option('all') && ! $agent) {
            $this->warn('Must specify --agent or --all');

            return self::FAILURE;
        }

        $items = $q->get();

        if ($items->isEmpty()) {
            $this->info('No matching leased items found.');

            return self::SUCCESS;
        }

        $ttl = (int) $this->option('ttl');
        $this->info("Found {$items->count()} leased item(s).");

        if ($this->option('dry-run')) {
            foreach ($items as $i) {
                $current = $i->lease_expires_at->diffInSeconds(now(), false);
                $this->line("DRY-RUN would extend item {$i->id} (agent={$i->leased_by_agent_id}, current TTL: {$current}s → new TTL: {$ttl}s)");
            }

            return self::SUCCESS;
        }

        // Extend each via LeaseService
        $extended = 0;
        $failed = 0;

        foreach ($items as $i) {
            try {
                $leases->extend($i->id, $i->leased_by_agent_id);
                $this->line("Extended item {$i->id} (agent={$i->leased_by_agent_id}) by {$ttl}s");
                $extended++;
            } catch (\Exception $e) {
                $this->error("Failed to extend item {$i->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Extended {$extended} lease(s)".($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }
}
