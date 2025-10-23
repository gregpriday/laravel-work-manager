<?php

namespace GregPriday\WorkManager\Events;

use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class WorkOrderEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkOrder $order,
        public readonly ?array $payload = null
    ) {}
}
