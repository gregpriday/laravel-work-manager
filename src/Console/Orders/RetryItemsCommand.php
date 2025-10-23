<?php

namespace GregPriday\WorkManager\Console\Orders;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Support\ItemState;
use Illuminate\Console\Command;

/**
 * Reset failed items back to queued and clear error/lease.
 *
 * @internal Admin command for retrying failed items
 *
 * @see docs/reference/commands-reference.md
 */
class RetryItemsCommand extends Command
{
    protected $signature = 'work-manager:items:retry
        {--order= : Only items for this order UUID}
        {--type= : Only items of this type}
        {--limit=1000 : Max items to process}
        {--dry-run : Show what would happen}
        {--force : Skip interactive confirm in production}';

    protected $description = 'Reset failed items back to queued state';

    public function handle(): int
    {
        $q = WorkItem::query()->where('state', ItemState::FAILED->value);

        if ($order = $this->option('order')) {
            $q->where('order_id', $order);
        }
        if ($type = $this->option('type')) {
            $q->where('type', $type);
        }

        $items = $q->limit((int) $this->option('limit'))->get();

        if ($items->isEmpty()) {
            $this->info('No failed items found.');

            return self::SUCCESS;
        }

        $this->info("Found {$items->count()} failed item(s).");

        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm("You are in production. Retry {$items->count()} item(s)?")) {
                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            foreach ($items as $item) {
                $error = $item->error['code'] ?? 'unknown';
                $this->line("DRY-RUN would retry item {$item->id} (error: {$error})");
            }

            return self::SUCCESS;
        }

        $retried = 0;

        foreach ($items as $item) {
            $item->state = ItemState::QUEUED;
            $item->error = null;
            $item->leased_by_agent_id = null;
            $item->lease_expires_at = null;
            $item->last_heartbeat_at = null;
            // Note: Keep attempts count for backoff policy
            $item->save();

            $this->line("Reset item {$item->id} → queued");
            $retried++;
        }

        $this->info("Retried {$retried} item(s).");

        return self::SUCCESS;
    }
}
