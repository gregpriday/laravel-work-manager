<?php

namespace GregPriday\WorkManager\Support;

/**
 * Work order lifecycle states (see configured transitions).
 *
 * @see docs/concepts/state-management.md
 */
enum OrderState: string
{
    case QUEUED = 'queued';
    case CHECKED_OUT = 'checked_out';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case APPLIED = 'applied';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case DEAD_LETTERED = 'dead_lettered';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::DEAD_LETTERED,
        ]);
    }

    public function canTransitionTo(OrderState $newState): bool
    {
        $transitions = config('work-manager.state_machine.order_transitions');
        $allowed = $transitions[$this->value] ?? [];

        return in_array($newState->value, $allowed);
    }
}
