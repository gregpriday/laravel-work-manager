<?php

namespace GregPriday\WorkManager\Console\Dev;

use GregPriday\WorkManager\Services\WorkAllocator;
use Illuminate\Console\Command;

/**
 * Seed example work orders for development/testing.
 *
 * @internal Dev command for quick setup
 *
 * @see docs/reference/commands-reference.md
 */
class SeedCommand extends Command
{
    protected $signature = 'work-manager:dev:seed
        {--type= : Order type to seed (e.g., user.data.sync)}
        {--count=5 : Number of orders to create}
        {--priority=5 : Priority for seeded orders}
        {--auto-plan : Automatically plan the orders}';

    protected $description = 'Seed example work orders for development';

    public function handle(WorkAllocator $allocator): int
    {
        $type = $this->option('type');

        if (! $type) {
            $this->error('Please specify --type (e.g., user.data.sync)');
            $this->line('Available types are determined by your registered order types.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $priority = (int) $this->option('priority');

        $this->info("Seeding {$count} order(s) of type '{$type}'...");

        $created = 0;
        $failed = 0;

        for ($i = 1; $i <= $count; $i++) {
            try {
                // Generate sample payload based on type
                $payload = $this->generateSamplePayload($type, $i);

                $order = $allocator->propose(
                    type: $type,
                    payload: $payload,
                    requestedByType: null,
                    requestedById: null,
                    meta: ['seeded' => true, 'seed_index' => $i],
                    priority: $priority
                );

                if ($this->option('auto-plan')) {
                    $allocator->plan($order);
                    $itemsCount = $order->items()->count();
                    $this->line("  Created order {$order->id} with {$itemsCount} item(s)");
                } else {
                    $this->line("  Created order {$order->id}");
                }

                $created++;
            } catch (\Exception $e) {
                $this->error("  Failed to create order {$i}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Seeded {$created} order(s)".($failed > 0 ? ", {$failed} failed" : '').'.');

        if (! $this->option('auto-plan')) {
            $this->line('Orders are queued but not planned. Use work-manager:generate to plan them.');
        }

        return self::SUCCESS;
    }

    /**
     * Generate a sample payload based on the order type.
     */
    protected function generateSamplePayload(string $type, int $index): array
    {
        // Generate type-specific sample payloads
        return match (true) {
            str_contains($type, 'echo') => [
                'message' => "Sample message {$index}",
                'metadata' => ['seed_index' => $index],
            ],
            str_contains($type, 'user') => [
                'user_ids' => [1000 + $index, 2000 + $index, 3000 + $index],
                'source' => 'development',
            ],
            str_contains($type, 'data') => [
                'source' => 'api',
                'batch_id' => 'seed-'.$index,
                'records' => rand(10, 100),
            ],
            str_contains($type, 'research') => [
                'topic' => "Sample Topic {$index}",
                'depth' => 'comprehensive',
                'max_sources' => 10,
            ],
            str_contains($type, 'content') => [
                'content_id' => 1000 + $index,
                'action' => 'process',
            ],
            default => [
                'example' => "Sample data {$index}",
                'timestamp' => now()->toIso8601String(),
            ],
        };
    }
}
