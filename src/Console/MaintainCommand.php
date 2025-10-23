<?php

namespace GregPriday\WorkManager\Console;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Console\Command;

/**
 * Reclaims expired leases, dead-letters stuck items, alerts on stale orders.
 *
 * @internal Runs via scheduler; idempotent.
 *
 * @see docs/reference/commands-reference.md
 */
class MaintainCommand extends Command
{
    protected $signature = 'work-manager:maintain
                          {--reclaim-leases : Reclaim expired leases}
                          {--dead-letter : Move failed orders to dead letter}
                          {--check-stale : Check for stale orders and alert}';

    protected $description = 'Perform maintenance tasks on work orders and items';

    public function handle(LeaseService $leaseService): int
    {
        $reclaimLeases = $this->option('reclaim-leases');
        $deadLetter = $this->option('dead-letter');
        $checkStale = $this->option('check-stale');

        // If no options specified, run all tasks
        if (! $reclaimLeases && ! $deadLetter && ! $checkStale) {
            $reclaimLeases = true;
            $deadLetter = true;
            $checkStale = true;
        }

        if ($reclaimLeases) {
            $this->reclaimExpiredLeases($leaseService);
        }

        if ($deadLetter) {
            $this->deadLetterFailedOrders();
        }

        if ($checkStale) {
            $this->checkStaleOrders();
        }

        return self::SUCCESS;
    }

    /**
     * Reclaim expired leases.
     */
    protected function reclaimExpiredLeases(LeaseService $leaseService): void
    {
        $this->info('Reclaiming expired leases...');

        $count = $leaseService->reclaimExpired();

        $this->line("  Reclaimed {$count} expired lease(s)");
    }

    /**
     * Move failed orders to dead letter after threshold.
     */
    protected function deadLetterFailedOrders(): void
    {
        $this->info('Processing failed orders for dead lettering...');

        $threshold = now()->subHours(
            config('work-manager.maintenance.dead_letter_after_hours', 48)
        );

        $orders = WorkOrder::where('state', OrderState::FAILED->value)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($orders as $order) {
            $order->state = OrderState::DEAD_LETTERED;
            $order->save();

            $this->line("  Dead lettered order: {$order->id}");
        }

        // Also dead letter items
        $items = WorkItem::where('state', ItemState::FAILED->value)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($items as $item) {
            $item->state = ItemState::DEAD_LETTERED;
            $item->save();
        }

        $totalCount = $orders->count() + $items->count();
        $this->line("  Dead lettered {$totalCount} order(s)/item(s)");
    }

    /**
     * Check for stale orders and alert.
     */
    protected function checkStaleOrders(): void
    {
        $this->info('Checking for stale orders...');

        $threshold = now()->subHours(
            config('work-manager.maintenance.stale_order_threshold_hours', 24)
        );

        $staleOrders = WorkOrder::whereNotIn('state', [
            OrderState::COMPLETED->value,
            OrderState::DEAD_LETTERED->value,
        ])
            ->where('created_at', '<', $threshold)
            ->get();

        if ($staleOrders->isEmpty()) {
            $this->line('  No stale orders found');

            return;
        }

        $this->warn("  Found {$staleOrders->count()} stale order(s):");

        foreach ($staleOrders as $order) {
            $age = $order->created_at->diffForHumans();
            $this->line("    - {$order->id} ({$order->type}, {$order->state->value}, created {$age})");
        }

        if (config('work-manager.maintenance.enable_alerts', true)) {
            // Emit an event or log for alerting
            \Illuminate\Support\Facades\Log::warning('Stale work orders detected', [
                'count' => $staleOrders->count(),
                'order_ids' => $staleOrders->pluck('id')->toArray(),
            ]);
        }
    }
}
