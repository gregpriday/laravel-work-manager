<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Exceptions\IdempotencyConflictException;
use GregPriday\WorkManager\Models\WorkIdempotencyKey;
use GregPriday\WorkManager\Support\Helpers;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    /**
     * Check if an idempotency key exists and return cached response if it does.
     * Returns null if key is new.
     */
    public function check(string $scope, string $key): ?array
    {
        $keyHash = Helpers::hashKey($key);

        $existing = WorkIdempotencyKey::forKey($scope, $keyHash)->first();

        return $existing?->response_snapshot;
    }

    /**
     * Store an idempotency key with its response.
     */
    public function store(string $scope, string $key, array $response): void
    {
        $keyHash = Helpers::hashKey($key);

        try {
            WorkIdempotencyKey::create([
                'scope' => $scope,
                'key_hash' => $keyHash,
                'response_snapshot' => $response,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // If duplicate key constraint violated, fetch and return the existing response
            $existing = WorkIdempotencyKey::forKey($scope, $keyHash)->first();

            if ($existing) {
                throw new IdempotencyConflictException(
                    'This request has already been processed',
                    $existing->response_snapshot
                );
            }

            throw $e;
        }
    }

    /**
     * Guard a callback with idempotency checking.
     * If key exists, return cached response. Otherwise, execute callback and cache result.
     */
    public function guard(string $scope, string $key, callable $callback): array
    {
        // Check for existing key
        $cached = $this->check($scope, $key);

        if ($cached !== null) {
            return $cached;
        }

        // Execute the callback
        $response = DB::transaction(function () use ($callback, $scope, $key) {
            $result = $callback();

            // Store the idempotency key with the response
            $this->store($scope, $key, $result);

            return $result;
        });

        return $response;
    }

    /**
     * Check if idempotency is required for an endpoint.
     */
    public function isRequired(string $endpoint): bool
    {
        $enforceOn = config('work-manager.idempotency.enforce_on', []);

        return in_array($endpoint, $enforceOn);
    }

    /**
     * Get the idempotency header name from config.
     */
    public function getHeaderName(): string
    {
        return config('work-manager.idempotency.header', 'X-Idempotency-Key');
    }
}
