<?php

namespace GregPriday\WorkManager\Support;

enum ActorType: string
{
    case USER = 'user';
    case AGENT = 'agent';
    case SYSTEM = 'system';
}
