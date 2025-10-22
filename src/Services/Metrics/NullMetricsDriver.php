<?php

namespace GregPriday\WorkManager\Services\Metrics;

use GregPriday\WorkManager\Contracts\MetricsDriver;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;

/**
 * Null metrics driver (no-op implementation).
 *
 * Use when metrics are disabled or for testing.
 *
 * Configuration in config/work-manager.php:
 * 'metrics' => [
 *     'enabled' => false,
 * ]
 */
class NullMetricsDriver implements MetricsDriver
{
    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        // No-op
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        // No-op
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        // No-op
    }

    public function recordOrderCreated(WorkOrder $order): void
    {
        // No-op
    }

    public function recordLeaseAcquired(WorkItem $item, string $agentId): void
    {
        // No-op
    }

    public function recordLeaseReleased(WorkItem $item, string $agentId): void
    {
        // No-op
    }

    public function recordLeaseExpired(WorkItem $item): void
    {
        // No-op
    }

    public function recordItemSubmitted(WorkItem $item): void
    {
        // No-op
    }

    public function recordOrderApproved(WorkOrder $order): void
    {
        // No-op
    }

    public function recordOrderRejected(WorkOrder $order): void
    {
        // No-op
    }

    public function recordApplyDuration(WorkOrder $order, float $duration): void
    {
        // No-op
    }

    public function recordApplyFailure(WorkOrder $order, \Throwable $exception): void
    {
        // No-op
    }

    public function recordItemFailure(WorkItem $item, array $error): void
    {
        // No-op
    }

    public function recordQueueDepth(string $type, int $count): void
    {
        // No-op
    }
}
