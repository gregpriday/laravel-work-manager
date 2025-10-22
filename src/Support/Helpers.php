<?php

namespace GregPriday\WorkManager\Support;

use Ramsey\Uuid\Uuid;

class Helpers
{
    /**
     * Generate a new UUID v4.
     */
    public static function uuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Hash a string for idempotency key storage.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Generate a jittered backoff delay.
     */
    public static function backoffDelay(int $attempt, int $baseSeconds, int $jitterSeconds): int
    {
        $exponential = $baseSeconds * (2 ** ($attempt - 1));
        $jitter = random_int(0, $jitterSeconds);

        return $exponential + $jitter;
    }

    /**
     * Validate JSON schema (basic implementation).
     */
    public static function validateJsonSchema(array $data, array $schema): array
    {
        $errors = [];

        // Check required fields
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $data)) {
                    $errors[] = [
                        'field' => $field,
                        'code' => 'schema.required',
                        'message' => "Field '{$field}' is required",
                    ];
                }
            }
        }

        // Check property types
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $field => $rules) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $expectedTypes = (array) ($rules['type'] ?? []);

                if (!empty($expectedTypes) && !self::matchesType($value, $expectedTypes)) {
                    $errors[] = [
                        'field' => $field,
                        'code' => 'schema.type_mismatch',
                        'message' => "Field '{$field}' must be of type: ".implode('|', $expectedTypes),
                    ];
                }

                // Check enum values
                if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                    $errors[] = [
                        'field' => $field,
                        'code' => 'schema.enum_invalid',
                        'message' => "Field '{$field}' must be one of: ".implode(', ', $rules['enum']),
                    ];
                }
            }
        }

        return $errors;
    }

    protected static function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $match = match ($type) {
                'string' => is_string($value),
                'number', 'integer' => is_numeric($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value) || is_object($value),
                'null' => is_null($value),
                default => false,
            };

            if ($match) {
                return true;
            }
        }

        return false;
    }
}
