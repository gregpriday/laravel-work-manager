<?php

namespace GregPriday\WorkManager\Events;

use GregPriday\WorkManager\Models\WorkItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class WorkItemEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkItem $item,
        public readonly ?array $payload = null
    ) {}
}
