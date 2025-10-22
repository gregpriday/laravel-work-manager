<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;

/**
 * Interface for metrics collection drivers.
 *
 * Implementations can export to:
 * - Log files (LogMetricsDriver)
 * - Prometheus (PrometheusMetricsDriver)
 * - StatsD (StatsDMetricsDriver)
 * - Datadog (DatadogMetricsDriver)
 * - CloudWatch (CloudWatchMetricsDriver)
 *
 * Metrics are recorded at key points in the order lifecycle:
 * - Order creation (by type)
 * - Item leasing (by type, agent)
 * - Submissions (by type)
 * - Approvals/Rejections (by type)
 * - Apply duration (by type)
 * - Lease expirations (by type)
 * - Failures (by type, reason)
 */
interface MetricsDriver
{
    /**
     * Record a counter metric (monotonically increasing).
     *
     * @param  string  $name  Metric name
     * @param  int  $value  Value to increment by (default 1)
     * @param  array  $labels  Key-value pairs for metric labels
     */
    public function increment(string $name, int $value = 1, array $labels = []): void;

    /**
     * Record a gauge metric (can go up or down).
     *
     * @param  string  $name  Metric name
     * @param  float  $value  Current value
     * @param  array  $labels  Key-value pairs for metric labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;

    /**
     * Record a histogram/timing metric (distribution of values).
     *
     * @param  string  $name  Metric name
     * @param  float  $value  Observed value (e.g., duration in seconds)
     * @param  array  $labels  Key-value pairs for metric labels
     */
    public function histogram(string $name, float $value, array $labels = []): void;

    /**
     * Record order creation.
     *
     * @param  WorkOrder  $order  The created order
     */
    public function recordOrderCreated(WorkOrder $order): void;

    /**
     * Record item lease acquisition.
     *
     * @param  WorkItem  $item  The leased item
     * @param  string  $agentId  Agent identifier
     */
    public function recordLeaseAcquired(WorkItem $item, string $agentId): void;

    /**
     * Record item lease release.
     *
     * @param  WorkItem  $item  The released item
     * @param  string  $agentId  Agent identifier
     */
    public function recordLeaseReleased(WorkItem $item, string $agentId): void;

    /**
     * Record item lease expiration.
     *
     * @param  WorkItem  $item  The expired item
     */
    public function recordLeaseExpired(WorkItem $item): void;

    /**
     * Record item submission.
     *
     * @param  WorkItem  $item  The submitted item
     */
    public function recordItemSubmitted(WorkItem $item): void;

    /**
     * Record order approval.
     *
     * @param  WorkOrder  $order  The approved order
     */
    public function recordOrderApproved(WorkOrder $order): void;

    /**
     * Record order rejection.
     *
     * @param  WorkOrder  $order  The rejected order
     */
    public function recordOrderRejected(WorkOrder $order): void;

    /**
     * Record order apply duration.
     *
     * @param  WorkOrder  $order  The applied order
     * @param  float  $duration  Duration in seconds
     */
    public function recordApplyDuration(WorkOrder $order, float $duration): void;

    /**
     * Record order apply failure.
     *
     * @param  WorkOrder  $order  The order that failed
     * @param  \Throwable  $exception  The exception thrown
     */
    public function recordApplyFailure(WorkOrder $order, \Throwable $exception): void;

    /**
     * Record item failure.
     *
     * @param  WorkItem  $item  The failed item
     * @param  array  $error  Error details
     */
    public function recordItemFailure(WorkItem $item, array $error): void;

    /**
     * Record queue depth (gauge metric).
     *
     * @param  string  $type  Order type
     * @param  int  $count  Number of items in queue
     */
    public function recordQueueDepth(string $type, int $count): void;
}
