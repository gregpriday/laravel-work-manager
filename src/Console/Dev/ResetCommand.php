<?php

namespace GregPriday\WorkManager\Console\Dev;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Truncate WorkManager tables (local/dev only).
 *
 * @internal Dev command - dangerous in production
 *
 * @see docs/reference/commands-reference.md
 */
class ResetCommand extends Command
{
    protected $signature = 'work-manager:dev:reset
        {--force : Force reset even in production}';

    protected $description = 'Truncate WorkManager tables (local/dev only)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to reset in production without --force');

            return self::FAILURE;
        }

        if (! $this->confirm('This will delete ALL work manager data. Continue?')) {
            return self::FAILURE;
        }

        $tables = [
            'work_item_parts',
            'work_events',
            'work_provenances',
            'work_idempotency_keys',
            'work_items',
            'work_orders',
        ];

        $this->info('Truncating WorkManager tables...');

        // Disable foreign key checks
        if (config('database.default') === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        $truncated = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->line("  Skipped {$table} (table does not exist)");
                $skipped++;

                continue;
            }

            DB::table($table)->truncate();
            $this->line("  Truncated {$table}");
            $truncated++;
        }

        // Re-enable foreign key checks
        if (config('database.default') === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Reset complete. Truncated {$truncated} table(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
