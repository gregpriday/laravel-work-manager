<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\StateMachine;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Console\Command;

/**
 * Force orders (and their items) back to queued state, releasing leases.
 *
 * @internal Admin command for resetting work orders
 *
 * @see docs/reference/commands-reference.md
 */
class RequeueOrdersCommand extends Command
{
    protected $signature = 'work-manager:orders:requeue
        {--order= : Specific order UUID}
        {--type= : Orders of this type}
        {--state= : Only from this state (checked_out|in_progress|submitted)}
        {--requeue-submitted : For submitted orders, reject with allow_rework to return to queued}
        {--limit=100 : Max orders to process}
        {--dry-run : Show what would happen}
        {--force : Skip interactive confirm in production}';

    protected $description = 'Force orders (and their items) back to queued state, releasing leases';

    public function handle(LeaseService $leases, WorkExecutor $executor, StateMachine $stateMachine): int
    {
        $q = WorkOrder::query();

        if ($id = $this->option('order')) {
            $q->where('id', $id);
        }
        if ($t = $this->option('type')) {
            $q->where('type', $t);
        }
        if ($s = $this->option('state')) {
            $q->where('state', $s);
        }

        $orders = $q->limit((int) $this->option('limit'))->get();

        if ($orders->isEmpty()) {
            $this->info('No matching orders.');

            return self::SUCCESS;
        }

        $this->info("Found {$orders->count()} order(s) to requeue.");

        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm("You are in production. Requeue {$orders->count()} order(s)?")) {
                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            foreach ($orders as $order) {
                $this->line("DRY-RUN would requeue order {$order->id} [{$order->state->value}]");
            }

            return self::SUCCESS;
        }

        $requeued = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $this->line("Processing order {$order->id} [{$order->state->value}]");

            try {
                // Release any active leases on items first
                $order->items()->whereNotNull('leased_by_agent_id')->get()
                    ->each(fn (WorkItem $i) => $leases->release($i->id, $i->leased_by_agent_id));

                if ($order->state === OrderState::SUBMITTED && $this->option('requeue-submitted')) {
                    // Two-step transition: SUBMITTED → REJECTED → QUEUED
                    // Step 1: Reject (state machine allows SUBMITTED → REJECTED)
                    $executor->reject($order, [
                        ['code' => 'requeue_requested', 'message' => 'Admin requeue via CLI'],
                    ], ActorType::USER, 'system:cli', false);

                    // Step 2: Move from REJECTED → QUEUED (state machine allows this)
                    $order->refresh();
                    $stateMachine->transitionOrder(
                        $order,
                        OrderState::QUEUED,
                        ActorType::USER,
                        'system:cli',
                        [],
                        'Admin requeue from rejected'
                    );

                    $this->line('  Rejected → queued (via two-step transition)');
                    $requeued++;

                    continue;
                }

                // For checked_out/in_progress → queued (legal transitions)
                foreach ($order->items as $item) {
                    if ($item->state === ItemState::IN_PROGRESS) {
                        $item->state = ItemState::QUEUED;
                        $item->leased_by_agent_id = null;
                        $item->lease_expires_at = null;
                        $item->save();
                    }
                }

                if (in_array($order->state, [OrderState::CHECKED_OUT, OrderState::IN_PROGRESS], true)) {
                    $order->state = OrderState::QUEUED;
                    $order->save();
                    $this->line('  Order → queued');
                    $requeued++;
                } else {
                    $this->line("  Skipped: state {$order->state->value} not requeueable without --requeue-submitted");
                }
            } catch (\Exception $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Requeued {$requeued} order(s)".($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }
}
