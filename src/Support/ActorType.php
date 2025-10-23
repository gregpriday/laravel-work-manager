<?php

namespace GregPriday\WorkManager\Support;

/**
 * Actor types for provenance tracking (user, agent, system).
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
enum ActorType: string
{
    case USER = 'user';
    case AGENT = 'agent';
    case SYSTEM = 'system';
}
