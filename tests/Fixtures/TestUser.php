<?php

namespace GregPriday\WorkManager\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

class TestUser implements Authenticatable
{
    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return 1;
    }

    public function getAuthPassword()
    {
        return 'password';
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        //
    }

    public function getRememberTokenName()
    {
        return null;
    }
}
