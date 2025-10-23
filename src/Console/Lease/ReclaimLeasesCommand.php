<?php

namespace GregPriday\WorkManager\Console\Lease;

use GregPriday\WorkManager\Services\LeaseService;
use Illuminate\Console\Command;

/**
 * On-demand wrapper to reclaim expired leases.
 *
 * @internal Admin command; convenience alias of maintain's reclaim portion
 *
 * @see docs/reference/commands-reference.md
 */
class ReclaimLeasesCommand extends Command
{
    protected $signature = 'work-manager:lease:reclaim
        {--dry-run : Show what would be reclaimed}';

    protected $description = 'Reclaim expired leases immediately';

    public function handle(LeaseService $leaseService): int
    {
        $this->info('Reclaiming expired leases...');

        if ($this->option('dry-run')) {
            $items = \GregPriday\WorkManager\Models\WorkItem::withExpiredLease()->get();
            $this->info("DRY-RUN would reclaim {$items->count()} expired lease(s):");

            foreach ($items as $item) {
                $this->line("  - Item {$item->id} (agent={$item->leased_by_agent_id}, expired {$item->lease_expires_at->diffForHumans()})");
            }

            return self::SUCCESS;
        }

        $count = $leaseService->reclaimExpired();

        $this->info("Reclaimed {$count} expired lease(s).");

        return self::SUCCESS;
    }
}
