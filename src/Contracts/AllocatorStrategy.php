<?php

namespace GregPriday\WorkManager\Contracts;

/**
 * Contract for discovering work to propose (used by GenerateCommand).
 *
 * @api
 *
 * @see docs/concepts/architecture-overview.md
 */
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
