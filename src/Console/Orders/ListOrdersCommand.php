<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Console\Command;

/**
 * List work orders with filtering options.
 *
 * @internal Admin command for inspection
 *
 * @see docs/reference/commands-reference.md
 */
class ListOrdersCommand extends Command
{
    protected $signature = 'work-manager:orders:list
        {--state= : Filter by state}
        {--type= : Filter by type}
        {--has-available-items : Only orders with available items}
        {--sort=-priority,created_at : Sort order}
        {--limit=50 : Max orders to display}
        {--json : Output as JSON}';

    protected $description = 'List work orders with optional filtering';

    public function handle(): int
    {
        $q = WorkOrder::query();

        if ($state = $this->option('state')) {
            $q->where('state', $state);
        }
        if ($type = $this->option('type')) {
            $q->where('type', $type);
        }
        if ($this->option('has-available-items')) {
            $q->whereHas('items', function ($q) {
                $q->where('state', 'queued')
                    ->where(function ($q) {
                        $q->whereNull('lease_expires_at')
                            ->orWhere('lease_expires_at', '<', now());
                    });
            });
        }

        // Parse sort
        $sorts = explode(',', $this->option('sort'));
        foreach ($sorts as $sort) {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $field = ltrim($sort, '-');
            $q->orderBy($field, $direction);
        }

        $orders = $q->limit((int) $this->option('limit'))->get();

        if ($this->option('json')) {
            $this->line(json_encode($orders->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($orders->isEmpty()) {
            $this->info('No orders found.');

            return self::SUCCESS;
        }

        $this->info("Found {$orders->count()} order(s):");
        $this->newLine();

        foreach ($orders as $order) {
            $availableItems = $order->items()->where('state', 'queued')->count();
            $totalItems = $order->items()->count();

            $this->line(sprintf(
                '<fg=yellow>%s</> | <fg=cyan>%s</> | State: <fg=%s>%s</> | Priority: %d | Items: %d/%d available',
                $order->id,
                $order->type,
                $this->getStateColor($order->state->value),
                $order->state->value,
                $order->priority,
                $availableItems,
                $totalItems
            ));
            $this->line("  Created: {$order->created_at->diffForHumans()}");

            if (! empty($order->meta)) {
                $this->line('  Meta: '.json_encode($order->meta));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function getStateColor(string $state): string
    {
        return match ($state) {
            'queued' => 'white',
            'checked_out', 'in_progress' => 'blue',
            'submitted' => 'yellow',
            'approved', 'applied', 'completed' => 'green',
            'rejected', 'failed', 'dead_lettered' => 'red',
            default => 'gray',
        };
    }
}
