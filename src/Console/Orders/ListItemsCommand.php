<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkItem;
use Illuminate\Console\Command;

/**
 * List work items with filtering options.
 *
 * @internal Admin command for inspection
 *
 * @see docs/reference/commands-reference.md
 */
class ListItemsCommand extends Command
{
    protected $signature = 'work-manager:items:list
        {--order= : Filter by order UUID}
        {--type= : Filter by type}
        {--state= : Filter by state}
        {--leased : Only leased items}
        {--limit=200 : Max items to display}
        {--json : Output as JSON}';

    protected $description = 'List work items with optional filtering';

    public function handle(): int
    {
        $q = WorkItem::query()->with('order');

        if ($order = $this->option('order')) {
            $q->where('order_id', $order);
        }
        if ($type = $this->option('type')) {
            $q->where('type', $type);
        }
        if ($state = $this->option('state')) {
            $q->where('state', $state);
        }
        if ($this->option('leased')) {
            $q->whereNotNull('leased_by_agent_id')
                ->where('lease_expires_at', '>', now());
        }

        $items = $q->limit((int) $this->option('limit'))->get();

        if ($this->option('json')) {
            $this->line(json_encode($items->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($items->isEmpty()) {
            $this->info('No items found.');

            return self::SUCCESS;
        }

        $this->info("Found {$items->count()} item(s):");
        $this->newLine();

        foreach ($items as $item) {
            $leaseInfo = '';
            if ($item->leased_by_agent_id) {
                $ttl = $item->lease_expires_at ? $item->lease_expires_at->diffForHumans() : 'unknown';
                $leaseInfo = " | Leased by: {$item->leased_by_agent_id} (expires {$ttl})";
            }

            $this->line(sprintf(
                '<fg=yellow>%s</> | State: <fg=%s>%s</> | Attempts: %d%s',
                $item->id,
                $this->getStateColor($item->state->value),
                $item->state->value,
                $item->attempts,
                $leaseInfo
            ));
            $this->line("  Order: {$item->order_id} ({$item->type})");

            if ($item->error) {
                $errorCode = $item->error['code'] ?? 'unknown';
                $errorMsg = $item->error['message'] ?? '';
                $this->line("  <fg=red>Error:</> {$errorCode}: {$errorMsg}");
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function getStateColor(string $state): string
    {
        return match ($state) {
            'queued' => 'white',
            'leased', 'in_progress' => 'blue',
            'submitted' => 'yellow',
            'accepted', 'completed' => 'green',
            'failed', 'dead_lettered' => 'red',
            default => 'gray',
        };
    }
}
