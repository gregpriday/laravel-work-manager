<?php

namespace GregPriday\WorkManager\Contracts;

use GregPriday\WorkManager\Support\Diff;

interface DiffRenderer
{
    /**
     * Render a diff object into a format suitable for storage or display.
     */
    public function render(Diff $diff): array;
}
