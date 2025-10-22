<?php

namespace GregPriday\WorkManager\Support;

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
