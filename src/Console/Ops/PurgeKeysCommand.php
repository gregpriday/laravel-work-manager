<?php

namespace GregPriday\WorkManager\Console\Ops;

use GregPriday\WorkManager\Models\WorkIdempotencyKey;
use Illuminate\Console\Command;

/**
 * Purge old idempotency keys from the database.
 *
 * @internal Admin command for data hygiene
 *
 * @see docs/reference/commands-reference.md
 */
class PurgeKeysCommand extends Command
{
    protected $signature = 'work-manager:ops:purge-keys
        {--older-than=30d : Purge keys older than this (e.g., 24h, 30d, 90d)}
        {--dry-run : Show what would be purged}
        {--force : Skip confirmation in production}';

    protected $description = 'Purge old idempotency keys';

    public function handle(): int
    {
        $spec = $this->option('older-than');
        $cutoff = $this->parseOlderThan($spec);

        $this->info("Purging idempotency keys older than {$cutoff->toDateTimeString()}...");

        $q = WorkIdempotencyKey::where('created_at', '<', $cutoff);
        $count = $q->count();

        if ($count === 0) {
            $this->info('No keys to purge.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} idempotency key(s) to purge.");

        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm("You are in production. Purge {$count} key(s)?")) {
                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $keys = $q->limit(10)->get();
            $this->line('DRY-RUN sample keys that would be purged:');
            foreach ($keys as $key) {
                $this->line("  - {$key->key_hash} (created {$key->created_at->diffForHumans()})");
            }
            $this->line('  ... and '.max(0, $count - 10).' more');

            return self::SUCCESS;
        }

        $deleted = $q->delete();

        $this->info("Purged {$deleted} idempotency key(s).");

        return self::SUCCESS;
    }

    /**
     * Parse "older-than" time specification into a Carbon date.
     */
    protected function parseOlderThan(string $spec): \Illuminate\Support\Carbon
    {
        // Support formats like: 30d, 90d, 24h
        if (preg_match('/^(\d+)([smhd])$/', $spec, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                's' => now()->subSeconds($value),
                'm' => now()->subMinutes($value),
                'h' => now()->subHours($value),
                'd' => now()->subDays($value),
                default => throw new \InvalidArgumentException("Invalid time unit: {$unit}"),
            };
        }

        throw new \InvalidArgumentException("Invalid time format: {$spec}. Use format like 30d, 90d, 24h");
    }
}
