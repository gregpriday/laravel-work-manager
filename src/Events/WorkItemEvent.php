<?php

namespace GregPriday\WorkManager\Events;

use GregPriday\WorkManager\Models\WorkItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for work item lifecycle events.
 *
 * @property-read WorkItem $item
 *
 * @see docs/reference/events-reference.md
 */
abstract class WorkItemEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkItem $item,
        public readonly ?array $payload = null
    ) {}
}
