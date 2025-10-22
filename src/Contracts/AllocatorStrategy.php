<?php

namespace GregPriday\WorkManager\Contracts;

interface AllocatorStrategy
{
    /**
     * Discover work that needs to be done and return an array of
     * work order specifications to be created.
     *
     * Called by the GenerateCommand.
     */
    public function discoverWork(): array;
}
