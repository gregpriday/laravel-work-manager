<?php

namespace GregPriday\WorkManager\Support;

enum ItemState: string
{
    case QUEUED = 'queued';
    case LEASED = 'leased';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case DEAD_LETTERED = 'dead_lettered';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::REJECTED,
            self::DEAD_LETTERED,
        ]);
    }

    public function canTransitionTo(ItemState $newState): bool
    {
        $transitions = config('work-manager.state_machine.item_transitions');
        $allowed = $transitions[$this->value] ?? [];

        return in_array($newState->value, $allowed);
    }
}
