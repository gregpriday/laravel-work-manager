<?php

namespace GregPriday\WorkManager\Events;

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\Diff;

class WorkOrderApplied extends WorkOrderEvent
{
    public function __construct(
        WorkOrder $order,
        public readonly Diff $diff,
        ?array $payload = null
    ) {
        parent::__construct($order, $payload);
    }
}
