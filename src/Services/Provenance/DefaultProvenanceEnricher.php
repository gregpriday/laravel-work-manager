<?php

namespace GregPriday\WorkManager\Services\Provenance;

use GregPriday\WorkManager\Contracts\ProvenanceEnricher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Captures agent metadata and request fingerprints from HTTP headers.
 *
 * @internal Standard headers: X-Agent-ID/Name/Version, X-Model-Name, X-Runtime, X-Request-ID
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
class DefaultProvenanceEnricher implements ProvenanceEnricher
{
    /**
     * Enrich provenance data from request.
     *
     * @param  Request  $request  The HTTP request
     * @param  array  $context  Additional context data
     * @return array Enriched provenance data
     */
    public function enrich(Request $request, array $context = []): array
    {
        return array_merge([
            'agent_id' => $this->getAgentId($request),
            'agent_name' => $request->header('X-Agent-Name'),
            'agent_version' => $request->header('X-Agent-Version'),
            'model_name' => $request->header('X-Model-Name'),
            'runtime' => $request->header('X-Runtime'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->header('X-Request-ID') ?? Str::uuid()->toString(),
            'fingerprint' => $this->generateFingerprint($request),
            'timestamp' => now()->toIso8601String(),
            'authenticated_user_id' => auth()->id(),
            'session_id' => $request->session()?->getId(),
        ], $context);
    }

    /**
     * Get agent ID from request.
     *
     * Tries headers first, falls back to authenticated user ID.
     */
    protected function getAgentId(Request $request): ?string
    {
        return $request->header('X-Agent-ID')
            ?? $request->header('X-Agent-Id') // Support both casings
            ?? (auth()->check() ? (string) auth()->id() : null);
    }

    /**
     * Generate a fingerprint for the request.
     *
     * Combines multiple request attributes to create a unique identifier
     * for tracking and correlation.
     *
     * @return string SHA-256 hash of request attributes
     */
    protected function generateFingerprint(Request $request): string
    {
        $components = [
            $request->ip(),
            $request->userAgent(),
            $request->header('X-Agent-ID'),
            $request->header('Accept-Language'),
            auth()->id(),
        ];

        return hash('sha256', implode('|', array_filter($components)));
    }

    /**
     * Validate agent metadata.
     *
     * Check if required agent headers are present and valid.
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validate(Request $request): array
    {
        $errors = [];

        if (! ($request->hasHeader('X-Agent-ID') || $request->hasHeader('X-Agent-Id'))) {
            $errors[] = 'Missing required header: X-Agent-ID';
        }

        if ($request->hasHeader('X-Agent-Version')) {
            $version = $request->header('X-Agent-Version');
            if (! $this->isValidSemver($version)) {
                $errors[] = 'Invalid semantic version in X-Agent-Version header';
            }
        }

        return $errors;
    }

    /**
     * Check if a string is a valid semantic version.
     */
    protected function isValidSemver(string $version): bool
    {
        $pattern = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([\da-z\-]+(?:\.[\da-z\-]+)*))?(?:\+([\da-z\-]+(?:\.[\da-z\-]+)*))?$/i';

        return preg_match($pattern, $version) === 1;
    }

    /**
     * Extract agent type from agent ID or name.
     *
     * Examples:
     * - "research-agent-1" -> "research"
     * - "fact-checker" -> "fact-checker"
     * - "user-123" -> "user"
     */
    public function extractAgentType(string $agentId): ?string
    {
        // Try to extract type from pattern like "type-instance"
        if (preg_match('/^([a-z][a-z0-9\-]*)-\d+$/i', $agentId, $matches)) {
            return $matches[1];
        }

        // Try to extract first segment
        if (preg_match('/^([a-z][a-z0-9\-]*)/i', $agentId, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Create provenance record for storage.
     *
     * Filters out null values and formats for database storage.
     *
     * @param  array  $enriched  Enriched provenance data
     * @return array Formatted for database storage
     */
    public function createRecord(array $enriched): array
    {
        return array_filter([
            'agent_id' => $enriched['agent_id'] ?? null,
            'agent_name' => $enriched['agent_name'] ?? null,
            'agent_version' => $enriched['agent_version'] ?? null,
            'agent_type' => $enriched['agent_type']
                ?? ($enriched['agent_id'] ? $this->extractAgentType($enriched['agent_id']) : null),
            'model_name' => $enriched['model_name'] ?? null,
            'runtime' => $enriched['runtime'] ?? null,
            'request_fingerprint' => $enriched['fingerprint'] ?? null,
            'request_id' => $enriched['request_id'] ?? null,
            'ip_address' => $enriched['ip_address'] ?? null,
            'user_agent' => $enriched['user_agent'] ?? null,
            'metadata' => array_filter([
                'authenticated_user_id' => $enriched['authenticated_user_id'] ?? null,
                'session_id' => $enriched['session_id'] ?? null,
                'timestamp' => $enriched['timestamp'] ?? null,
            ]),
        ], fn ($value) => $value !== null);
    }
}
