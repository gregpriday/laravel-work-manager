<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Support\Diff;

/**
 * Contract for rendering Diff objects to storage/display format.
 *
 * @api
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
interface DiffRenderer
{
    /**
     * Render a diff object into a format suitable for storage or display.
     */
    public function render(Diff $diff): array;
}
