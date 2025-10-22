<?php

namespace GregPriday\WorkManager\Services\Metrics;

use GregPriday\WorkManager\Contracts\MetricsDriver;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * Log-based metrics driver.
 *
 * Writes metrics to Laravel log with structured format.
 * Useful for development and debugging, or when piping logs to a centralized system.
 *
 * Metrics are logged with:
 * - context.metric_type: counter|gauge|histogram
 * - context.metric_name: name of the metric
 * - context.metric_value: the value
 * - context.metric_labels: array of labels
 *
 * Configuration in config/work-manager.php:
 * 'metrics' => [
 *     'enabled' => true,
 *     'driver' => 'log',
 *     'namespace' => 'work_manager',
 *     'log_channel' => 'metrics', // optional, defaults to default channel
 * ]
 */
class LogMetricsDriver implements MetricsDriver
{
    protected string $namespace;

    protected ?string $channel;

    public function __construct()
    {
        $this->namespace = config('work-manager.metrics.namespace', 'work_manager');
        $this->channel = config('work-manager.metrics.log_channel');
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        $this->log('counter', $name, $value, $labels);
    }

    /**
     * {@inheritDoc}
     */
    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->log('gauge', $name, $value, $labels);
    }

    /**
     * {@inheritDoc}
     */
    public function histogram(string $name, float $value, array $labels = []): void
    {
        $this->log('histogram', $name, $value, $labels);
    }

    /**
     * {@inheritDoc}
     */
    public function recordOrderCreated(WorkOrder $order): void
    {
        $this->increment('orders_created_total', 1, [
            'type' => $order->type,
            'priority' => $order->priority,
            'requested_by_type' => $order->requested_by_type?->value,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordLeaseAcquired(WorkItem $item, string $agentId): void
    {
        $this->increment('leases_acquired_total', 1, [
            'type' => $item->type,
            'agent_id' => $agentId,
        ]);

        $this->gauge('leases_active', $this->getActiveLeaseCount($item->type), [
            'type' => $item->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordLeaseReleased(WorkItem $item, string $agentId): void
    {
        $this->increment('leases_released_total', 1, [
            'type' => $item->type,
            'agent_id' => $agentId,
        ]);

        $this->gauge('leases_active', $this->getActiveLeaseCount($item->type), [
            'type' => $item->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordLeaseExpired(WorkItem $item): void
    {
        $this->increment('leases_expired_total', 1, [
            'type' => $item->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordItemSubmitted(WorkItem $item): void
    {
        $this->increment('items_submitted_total', 1, [
            'type' => $item->type,
            'agent_id' => $item->leased_by_agent_id,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordOrderApproved(WorkOrder $order): void
    {
        $this->increment('orders_approved_total', 1, [
            'type' => $order->type,
        ]);

        // Time from creation to approval
        $timeToApproval = now()->diffInSeconds($order->created_at);
        $this->histogram('order_time_to_approval_seconds', $timeToApproval, [
            'type' => $order->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordOrderRejected(WorkOrder $order): void
    {
        $this->increment('orders_rejected_total', 1, [
            'type' => $order->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordApplyDuration(WorkOrder $order, float $duration): void
    {
        $this->histogram('order_apply_duration_seconds', $duration, [
            'type' => $order->type,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordApplyFailure(WorkOrder $order, \Throwable $exception): void
    {
        $this->increment('orders_apply_failed_total', 1, [
            'type' => $order->type,
            'exception_class' => get_class($exception),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordItemFailure(WorkItem $item, array $error): void
    {
        $this->increment('items_failed_total', 1, [
            'type' => $item->type,
            'error_code' => $error['code'] ?? 'unknown',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function recordQueueDepth(string $type, int $count): void
    {
        $this->gauge('queue_depth', $count, [
            'type' => $type,
        ]);
    }

    /**
     * Log a metric.
     *
     * @param  string  $metricType  counter|gauge|histogram
     * @param  string  $name  Metric name
     * @param  float|int  $value  Metric value
     * @param  array  $labels  Metric labels
     */
    protected function log(string $metricType, string $name, float|int $value, array $labels): void
    {
        $fullName = $this->namespace.'.'.$name;

        $context = [
            'metric_type' => $metricType,
            'metric_name' => $fullName,
            'metric_value' => $value,
            'metric_labels' => $labels,
        ];

        $message = sprintf(
            '[Metric] %s %s = %s %s',
            strtoupper($metricType),
            $fullName,
            $value,
            $this->formatLabels($labels)
        );

        if ($this->channel) {
            Log::channel($this->channel)->info($message, $context);
        } else {
            Log::info($message, $context);
        }
    }

    /**
     * Format labels for log output.
     *
     * @param  array  $labels  Labels array
     * @return string Formatted labels (e.g., "{type=user.sync, agent=agent-1}")
     */
    protected function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        $formatted = [];
        foreach ($labels as $key => $value) {
            $formatted[] = "{$key}={$value}";
        }

        return '{'.implode(', ', $formatted).'}';
    }

    /**
     * Get active lease count for a type (for gauge metrics).
     *
     * @param  string  $type  Order type
     * @return int Active lease count
     */
    protected function getActiveLeaseCount(string $type): int
    {
        return WorkItem::where('type', $type)
            ->whereNotNull('leased_by_agent_id')
            ->where('lease_expires_at', '>', now())
            ->count();
    }
}
