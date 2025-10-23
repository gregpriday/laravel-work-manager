<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\WorkAllocator;
use Illuminate\Console\Command;

/**
 * Clone a dead-lettered order into a fresh queued order.
 *
 * @internal Admin command for recovering dead-lettered orders
 *
 * @see docs/reference/commands-reference.md
 */
class CloneDeadLetteredOrderCommand extends Command
{
    protected $signature = 'work-manager:dead-letter:clone
        {order : Dead-lettered order UUID}
        {--priority=0 : Priority for the new order}
        {--dry-run : Show what would happen}';

    protected $description = 'Clone a dead-lettered order into a fresh queued order';

    public function handle(WorkAllocator $allocator): int
    {
        $orderId = $this->argument('order');
        $orig = WorkOrder::find($orderId);

        if (! $orig) {
            $this->error("Order not found: {$orderId}");

            return self::FAILURE;
        }

        if ($orig->state->value !== 'dead_lettered') {
            $this->error('Order is not in dead_lettered state.');

            return self::FAILURE;
        }

        $this->info("Cloning dead-lettered order {$orig->id} ({$orig->type})");
        $this->line('Original payload: '.json_encode($orig->payload));

        if ($this->option('dry-run')) {
            $this->line('DRY-RUN would create a new order with same type/payload/meta.');

            return self::SUCCESS;
        }

        try {
            $new = $allocator->propose(
                $orig->type,
                $orig->payload,
                requestedByType: null,
                requestedById: null,
                meta: array_merge($orig->meta ?? [], ['cloned_from' => $orig->id]),
                priority: (int) $this->option('priority')
            );

            $allocator->plan($new);

            $this->info("New order {$new->id} created & planned.");
            $this->line("State: {$new->state->value}");
            $this->line("Items: {$new->items->count()}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to clone order: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
