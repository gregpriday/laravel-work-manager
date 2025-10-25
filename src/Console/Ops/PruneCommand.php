<?php

namespace GregPriday\WorkManager\Console\Ops;

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkProvenance;
use Illuminate\Console\Command;

/**
 * Prune old work events and provenance records.
 *
 * @internal Admin command for data hygiene
 *
 * @see docs/reference/commands-reference.md
 */
class PruneCommand extends Command
{
    protected $signature = 'work-manager:ops:prune
        {--older-than=90d : Prune events older than this (e.g., 24h, 30d, 90d)}
        {--dry-run : Show what would be pruned}
        {--force : Skip confirmation in production}';

    protected $description = 'Prune old work events and provenance records';

    public function handle(): int
    {
        $spec = $this->option('older-than');
        $cutoff = $this->parseOlderThan($spec);

        $this->info("Pruning events older than {$cutoff->toDateTimeString()}...");

        $eventsCount = WorkEvent::where('created_at', '<', $cutoff)->count();
        $provenanceCount = WorkProvenance::where('created_at', '<', $cutoff)->count();
        $totalCount = $eventsCount + $provenanceCount;

        if ($totalCount === 0) {
            $this->info('No records to prune.');

            return self::SUCCESS;
        }

        $this->info("Found {$eventsCount} event(s) and {$provenanceCount} provenance record(s) to prune.");

        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm("You are in production. Prune {$totalCount} record(s)?")) {
                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->line("DRY-RUN would prune {$eventsCount} event(s) and {$provenanceCount} provenance record(s).");

            return self::SUCCESS;
        }

        $deletedEvents = WorkEvent::where('created_at', '<', $cutoff)->delete();
        $deletedProvenance = WorkProvenance::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deletedEvents} event(s) and {$deletedProvenance} provenance record(s).");

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
