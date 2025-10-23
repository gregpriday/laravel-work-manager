<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Console\Command;

/**
 * Unified review command for approving or rejecting orders.
 *
 * @internal Admin command - replaces separate approve/reject commands
 *
 * @see docs/reference/commands-reference.md
 */
class ReviewOrderCommand extends Command
{
    protected $signature = 'work-manager:orders:review
        {order : Order UUID to review}
        {--approve : Approve the order}
        {--reject : Reject the order}
        {--reason= : Rejection reason (required when rejecting)}
        {--allow-rework : Allow rework (returns order to queued state when rejecting)}
        {--actor-id=system:cli : Actor ID for audit trail}';

    protected $description = 'Review a work order (approve or reject)';

    public function handle(WorkExecutor $executor): int
    {
        $orderId = $this->argument('order');
        $order = WorkOrder::find($orderId);

        if (! $order) {
            $this->error("Order not found: {$orderId}");

            return self::FAILURE;
        }

        $approve = $this->option('approve');
        $reject = $this->option('reject');

        if ($approve === $reject) {
            $this->error('Must specify exactly one of --approve or --reject');

            return self::FAILURE;
        }

        if ($approve) {
            return $this->approveOrder($executor, $order);
        }

        return $this->rejectOrder($executor, $order);
    }

    protected function approveOrder(WorkExecutor $executor, WorkOrder $order): int
    {
        $this->info("Approving order {$order->id} ({$order->type})...");
        $this->line("Current state: {$order->state->value}");

        try {
            $result = $executor->approve(
                $order,
                ActorType::USER,
                $this->option('actor-id')
            );

            $this->info('Order approved successfully!');
            $this->line("New state: {$result['order']->state->value}");

            if (! empty($result['diff'])) {
                $this->line('Changes applied:');
                $this->line(json_encode($result['diff'], JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to approve order: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function rejectOrder(WorkExecutor $executor, WorkOrder $order): int
    {
        $reason = $this->option('reason');
        if (! $reason) {
            $this->error('Rejection reason is required. Use --reason="..."');

            return self::FAILURE;
        }

        $allowRework = $this->option('allow-rework');

        $this->info("Rejecting order {$order->id} ({$order->type})...");
        $this->line("Current state: {$order->state->value}");
        $this->line('Allow rework: '.($allowRework ? 'yes' : 'no'));

        try {
            $errors = [
                ['code' => 'manual_rejection', 'message' => $reason],
            ];

            $order = $executor->reject(
                $order,
                $errors,
                ActorType::USER,
                $this->option('actor-id'),
                $allowRework
            );

            $this->info('Order rejected successfully!');
            $this->line("New state: {$order->state->value}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to reject order: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
