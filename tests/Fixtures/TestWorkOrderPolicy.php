<?php

namespace GregPriday\WorkManager\Tests\Fixtures;

class TestWorkOrderPolicy
{
    public function propose($user)
    {
        return true;
    }

    public function view($user, $order)
    {
        return true;
    }

    public function checkout($user, $order)
    {
        return true;
    }

    public function submit($user, $order)
    {
        return true;
    }

    public function approve($user, $order)
    {
        return true;
    }

    public function reject($user, $order)
    {
        return true;
    }
}
