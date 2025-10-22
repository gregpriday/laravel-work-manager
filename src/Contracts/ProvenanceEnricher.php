<?php

namespace GregPriday\WorkManager\Contracts;

interface ProvenanceEnricher
{
    /**
     * Enrich provenance data from the current request context.
     * Returns an array with keys like: agent_name, agent_version, request_fingerprint, etc.
     */
    public function enrich(array $context = []): array;
}
