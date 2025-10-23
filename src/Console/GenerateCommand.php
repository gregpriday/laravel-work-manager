<?php

namespace GregPriday\WorkManager\Console;

use GregPriday\WorkManager\Contracts\AllocatorStrategy;
use GregPriday\WorkManager\Contracts\PlannerPort;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Console\Command;

/**
 * Discovers and proposes work orders via AllocatorStrategy implementations.
 *
 * @internal Runs via scheduler; idempotent.
 *
 * @see docs/reference/commands-reference.md
 */
class GenerateCommand extends Command
{
    protected $signature = 'work-manager:generate
                          {--dry-run : Show what would be generated without creating orders}';

    protected $description = 'Generate work orders based on registered allocator strategies';

    public function handle(WorkAllocator $allocator): int
    {
        $this->info('Discovering work to be done...');

        $strategies = $this->getStrategies();

        if (empty($strategies)) {
            $this->warn('No allocator strategies registered. Register strategies in your AppServiceProvider.');

            return self::SUCCESS;
        }

        $totalOrders = 0;
        $dryRun = $this->option('dry-run');

        foreach ($strategies as $strategy) {
            $strategyName = get_class($strategy);
            $this->info("Running strategy: {$strategyName}");

            try {
                $workSpecs = $this->getWorkFromStrategy($strategy);

                if (empty($workSpecs)) {
                    $this->line('  No work discovered');

                    continue;
                }

                $this->line('  Discovered '.count($workSpecs).' work order(s)');

                foreach ($workSpecs as $spec) {
                    if ($dryRun) {
                        $this->line("  [DRY RUN] Would create: {$spec['type']}");

                        continue;
                    }

                    $order = $allocator->propose(
                        type: $spec['type'],
                        payload: $spec['payload'],
                        requestedByType: ActorType::SYSTEM,
                        requestedById: 'scheduler',
                        meta: $spec['meta'] ?? null,
                        priority: $spec['priority'] ?? 0
                    );

                    $this->line("  Created order: {$order->id} ({$order->type})");
                    $totalOrders++;
                }
            } catch (\Exception $e) {
                $this->error('  Error: '.$e->getMessage());
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Would have created {$totalOrders} orders.");
        } else {
            $this->info("Generated {$totalOrders} work orders.");
        }

        return self::SUCCESS;
    }

    /**
     * Get all registered allocator strategies.
     */
    protected function getStrategies(): array
    {
        // Strategies should be bound in the service container with a tag
        // or registered in a collection that the app can configure
        $tagged = app()->tagged('work-manager.strategies');

        return $tagged ? iterator_to_array($tagged) : [];
    }

    /**
     * Get work specifications from a strategy.
     */
    protected function getWorkFromStrategy(AllocatorStrategy|PlannerPort $strategy): array
    {
        if ($strategy instanceof AllocatorStrategy) {
            return $strategy->discoverWork();
        }

        if ($strategy instanceof PlannerPort) {
            return $strategy->generateOrders();
        }

        return [];
    }
}
