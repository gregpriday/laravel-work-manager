<?php

namespace GregPriday\WorkManager\Contracts;

use Illuminate\Http\Request;

/**
 * Contract for extracting agent metadata from request context.
 *
 * @api
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
interface ProvenanceEnricher
{
    /**
     * Enrich provenance data from the current request context.
     * Returns an array with keys like: agent_name, agent_version, request_fingerprint, etc.
     */
    public function enrich(Request $request, array $context = []): array;
}
