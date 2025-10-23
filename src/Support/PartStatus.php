<?php

namespace GregPriday\WorkManager\Support;

/**
 * Partial submission states (draft, validated, rejected).
 *
 * @see docs/guides/partial-submissions.md
 */
enum PartStatus: string
{
    case DRAFT = 'draft';
    case VALIDATED = 'validated';
    case REJECTED = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::VALIDATED,
            self::REJECTED,
        ]);
    }
}
