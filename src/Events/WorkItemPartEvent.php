<?php

namespace GregPriday\WorkManager\Events;

use GregPriday\WorkManager\Models\WorkItemPart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for partial submission events.
 *
 * @property-read WorkItemPart $part
 *
 * @see docs/guides/partial-submissions.md
 */
abstract class WorkItemPartEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkItemPart $part,
        public readonly ?array $payload = null
    ) {}
}
