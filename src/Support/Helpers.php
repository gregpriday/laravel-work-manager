<?php

namespace GregPriday\WorkManager\Support;

use Ramsey\Uuid\Uuid;

/**
 * Static helpers: UUID generation, key hashing, backoff, JSON schema validation.
 *
 * @internal
 */
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
     * Validate JSON schema (enhanced implementation with advanced features).
     */
    public static function validateJsonSchema(array $data, array $schema, string $path = ''): array
    {
        $errors = [];

        // Check required fields
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (! array_key_exists($field, $data)) {
                    $errors[] = [
                        'field' => $path ? "{$path}.{$field}" : $field,
                        'code' => 'schema.required',
                        'message' => "Field '{$field}' is required",
                    ];
                }
            }
        }

        // Check property types and constraints
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $field => $rules) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $fieldPath = $path ? "{$path}.{$field}" : $field;
                $expectedTypes = (array) ($rules['type'] ?? []);

                if (! empty($expectedTypes) && ! self::matchesType($value, $expectedTypes)) {
                    $errors[] = [
                        'field' => $fieldPath,
                        'code' => 'schema.type_mismatch',
                        'message' => "Field '{$field}' must be of type: ".implode('|', $expectedTypes),
                    ];

                    continue; // Skip further validation if type is wrong
                }

                // Check enum values
                if (isset($rules['enum']) && ! in_array($value, $rules['enum'], true)) {
                    $errors[] = [
                        'field' => $fieldPath,
                        'code' => 'schema.enum_invalid',
                        'message' => "Field '{$field}' must be one of: ".implode(', ', $rules['enum']),
                    ];
                }

                // String validations
                if (is_string($value)) {
                    if (isset($rules['minLength']) && mb_strlen($value) < $rules['minLength']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.min_length',
                            'message' => "Field '{$field}' must be at least {$rules['minLength']} characters",
                        ];
                    }

                    if (isset($rules['maxLength']) && mb_strlen($value) > $rules['maxLength']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.max_length',
                            'message' => "Field '{$field}' must be no more than {$rules['maxLength']} characters",
                        ];
                    }

                    if (isset($rules['pattern']) && ! preg_match('/'.$rules['pattern'].'/', $value)) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.pattern',
                            'message' => "Field '{$field}' does not match required pattern",
                        ];
                    }
                }

                // Number validations
                if (is_numeric($value)) {
                    if (isset($rules['minimum']) && $value < $rules['minimum']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.minimum',
                            'message' => "Field '{$field}' must be at least {$rules['minimum']}",
                        ];
                    }

                    if (isset($rules['maximum']) && $value > $rules['maximum']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.maximum',
                            'message' => "Field '{$field}' must be no more than {$rules['maximum']}",
                        ];
                    }
                }

                // Array validations
                if (is_array($value)) {
                    if (isset($rules['minItems']) && count($value) < $rules['minItems']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.min_items',
                            'message' => "Field '{$field}' must contain at least {$rules['minItems']} items",
                        ];
                    }

                    if (isset($rules['maxItems']) && count($value) > $rules['maxItems']) {
                        $errors[] = [
                            'field' => $fieldPath,
                            'code' => 'schema.max_items',
                            'message' => "Field '{$field}' must contain no more than {$rules['maxItems']} items",
                        ];
                    }

                    // Validate array item schemas (tuple validation)
                    if (isset($rules['items']) && is_array($rules['items']) && ! isset($rules['items']['type'])) {
                        // Tuple validation: items is an array of schemas
                        foreach ($rules['items'] as $index => $itemSchema) {
                            if (isset($value[$index])) {
                                $itemErrors = self::validateJsonSchema(
                                    [$index => $value[$index]],
                                    ['properties' => [$index => $itemSchema]],
                                    $fieldPath
                                );
                                $errors = array_merge($errors, $itemErrors);
                            }
                        }
                    } elseif (isset($rules['items']['type'])) {
                        // All items must match the same schema
                        foreach ($value as $index => $item) {
                            $itemErrors = self::validateJsonSchema(
                                [$index => $item],
                                ['properties' => [$index => $rules['items']]],
                                $fieldPath
                            );
                            $errors = array_merge($errors, $itemErrors);
                        }
                    }
                }

                // Nested object validation
                if (is_array($value) && isset($rules['properties'])) {
                    // Recursively validate nested object
                    $nestedErrors = self::validateJsonSchema($value, $rules, $fieldPath);
                    $errors = array_merge($errors, $nestedErrors);
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
                'number' => is_numeric($value),
                'integer' => is_int($value) || (is_numeric($value) && (int) $value == $value),
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
