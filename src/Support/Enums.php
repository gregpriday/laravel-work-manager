<?php

namespace GregPriday\WorkManager\Support;

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

enum ActorType: string
{
    case USER = 'user';
    case AGENT = 'agent';
    case SYSTEM = 'system';
}

enum EventType: string
{
    case PROPOSED = 'proposed';
    case PLANNED = 'planned';
    case CHECKED_OUT = 'checked_out';
    case LEASED = 'leased';
    case HEARTBEAT = 'heartbeat';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case APPLIED = 'applied';
    case REJECTED = 'rejected';
    case LEASE_EXPIRED = 'lease_expired';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case DEAD_LETTERED = 'dead_lettered';
    case RELEASED = 'released';
}
