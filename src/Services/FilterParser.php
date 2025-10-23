<?php

namespace GregPriday\WorkManager\Services;

use GregPriday\WorkManager\Exceptions\FilterValidationException;

/**
 * Parses and validates filter and sort JSON structures for work order queries.
 *
 * Supports:
 * - Top-level field filtering (id, state, type, priority, etc.)
 * - Meta field filtering via dot-paths (meta.batch_id, meta.score, etc.)
 * - Boolean grouping (AND/OR logic)
 * - Various operators (eq, ne, gt, gte, lt, lte, in, nin, contains, exists, etc.)
 * - Multi-key sorting with direction and null handling
 */
class FilterParser
{
    /**
     * Allowed top-level fields for filtering and sorting.
     */
    protected const ALLOWED_TOP_LEVEL_FIELDS = [
        'id',
        'state',
        'type',
        'requested_by_type',
        'requested_by_id',
        'priority',
        'created_at',
        'updated_at',
        'last_transitioned_at',
        'applied_at',
        'completed_at',
    ];

    /**
     * Allowed operators and their applicable types.
     */
    protected const OPERATORS = [
        'eq' => ['string', 'number', 'boolean', 'null'],
        'ne' => ['string', 'number', 'boolean', 'null'],
        'gt' => ['number', 'date'],
        'gte' => ['number', 'date'],
        'lt' => ['number', 'date'],
        'lte' => ['number', 'date'],
        'in' => ['string', 'number'],
        'nin' => ['string', 'number'],
        'contains' => ['array', 'string'],
        'contains_all' => ['array'],
        'exists' => ['any'],
        'length_eq' => ['array'],
        'is_null' => ['any'],
        'not_null' => ['any'],
    ];

    /**
     * Boolean group operators.
     */
    protected const BOOLEAN_OPERATORS = ['and', 'or'];

    /**
     * Maximum depth for meta paths to prevent abuse.
     */
    protected const MAX_META_DEPTH = 5;

    /**
     * Parse and validate a filter structure.
     *
     * @param array|null $filters The filter structure to parse
     * @return array Normalized filter structure
     * @throws FilterValidationException
     */
    public function parseFilters(?array $filters): ?array
    {
        if ($filters === null || empty($filters)) {
            return null;
        }

        return $this->validateFilterNode($filters, []);
    }

    /**
     * Parse and validate a sort structure.
     *
     * @param array|null $sort The sort structure to parse
     * @return array Normalized sort structure
     * @throws FilterValidationException
     */
    public function parseSort(?array $sort): ?array
    {
        if ($sort === null || empty($sort)) {
            return null;
        }

        if (!is_array($sort)) {
            $this->throwError('INVALID_SORT', 'Sort must be an array of sort directives', []);
        }

        $normalized = [];

        foreach ($sort as $index => $directive) {
            if (!is_array($directive)) {
                $this->throwError('INVALID_SORT', 'Each sort directive must be an object', ['sort', $index]);
            }

            if (!isset($directive['field'])) {
                $this->throwError('INVALID_SORT', 'Sort directive must include "field"', ['sort', $index]);
            }

            $field = $directive['field'];
            $this->validateField($field, ['sort', $index, 'field']);

            $direction = $directive['direction'] ?? 'asc';
            if (!in_array($direction, ['asc', 'desc'], true)) {
                $this->throwError(
                    'INVALID_SORT',
                    'Sort direction must be "asc" or "desc"',
                    ['sort', $index, 'direction']
                );
            }

            $nulls = $directive['nulls'] ?? null;
            if ($nulls !== null && !in_array($nulls, ['first', 'last'], true)) {
                $this->throwError(
                    'INVALID_SORT',
                    'Nulls handling must be "first" or "last"',
                    ['sort', $index, 'nulls']
                );
            }

            $normalized[] = [
                'field' => $field,
                'direction' => $direction,
                'nulls' => $nulls,
            ];
        }

        return $normalized;
    }

    /**
     * Validate a filter node (either a boolean group or a leaf predicate).
     *
     * @param array $node The node to validate
     * @param array $path The current path for error reporting
     * @return array Normalized node
     * @throws FilterValidationException
     */
    protected function validateFilterNode(array $node, array $path): array
    {
        if (!isset($node['op'])) {
            $this->throwError('INVALID_FILTER', 'Filter node must include "op"', $path);
        }

        $op = $node['op'];

        // Boolean group operator
        if (in_array($op, self::BOOLEAN_OPERATORS, true)) {
            if (!isset($node['filters']) || !is_array($node['filters'])) {
                $this->throwError(
                    'INVALID_FILTER',
                    "Boolean operator \"{$op}\" must include \"filters\" array",
                    $path
                );
            }

            if (empty($node['filters'])) {
                $this->throwError(
                    'INVALID_FILTER',
                    "Boolean operator \"{$op}\" must have at least one filter",
                    $path
                );
            }

            $normalized = [
                'op' => $op,
                'filters' => [],
            ];

            foreach ($node['filters'] as $index => $childNode) {
                if (!is_array($childNode)) {
                    $this->throwError('INVALID_FILTER', 'Filter must be an object', [...$path, 'filters', $index]);
                }

                $normalized['filters'][] = $this->validateFilterNode($childNode, [...$path, 'filters', $index]);
            }

            return $normalized;
        }

        // Leaf predicate
        if (!isset(self::OPERATORS[$op])) {
            $this->throwError('INVALID_FILTER', "Unsupported operator \"{$op}\"", [...$path, 'op']);
        }

        if (!isset($node['field'])) {
            $this->throwError('INVALID_FILTER', 'Filter predicate must include "field"', $path);
        }

        $field = $node['field'];
        $this->validateField($field, [...$path, 'field']);

        // Some operators don't require a value
        if (in_array($op, ['exists', 'is_null', 'not_null'], true)) {
            return [
                'op' => $op,
                'field' => $field,
            ];
        }

        if (!array_key_exists('value', $node)) {
            $this->throwError('INVALID_FILTER', "Operator \"{$op}\" requires a \"value\"", [...$path, 'value']);
        }

        $value = $node['value'];

        // Validate value type for operator
        $this->validateValueForOperator($op, $value, [...$path, 'value']);

        return [
            'op' => $op,
            'field' => $field,
            'value' => $value,
        ];
    }

    /**
     * Validate a field name.
     *
     * @param string $field The field name to validate
     * @param array $path The current path for error reporting
     * @throws FilterValidationException
     */
    protected function validateField(string $field, array $path): void
    {
        // Check if it's a meta field
        if (str_starts_with($field, 'meta.')) {
            $this->validateMetaPath($field, $path);
            return;
        }

        // Check if it's a top-level field
        if (!in_array($field, self::ALLOWED_TOP_LEVEL_FIELDS, true)) {
            $this->throwError(
                'INVALID_FIELD',
                "Field \"{$field}\" is not allowed. Must be one of: " .
                    implode(', ', self::ALLOWED_TOP_LEVEL_FIELDS) . ', or meta.*',
                $path
            );
        }
    }

    /**
     * Validate a meta path (e.g., meta.batch_id, meta.tags.urgent).
     *
     * @param string $field The field name to validate
     * @param array $path The current path for error reporting
     * @throws FilterValidationException
     */
    protected function validateMetaPath(string $field, array $path): void
    {
        // Must match meta.<key>[.<key>...]
        if (!preg_match('/^meta(\.[A-Za-z0-9_]+)+$/', $field)) {
            $this->throwError(
                'INVALID_FIELD',
                "Meta path \"{$field}\" is invalid. Must match pattern: meta.<key>[.<key>...]",
                $path
            );
        }

        // Check depth
        $segments = explode('.', $field);
        if (count($segments) - 1 > self::MAX_META_DEPTH) {
            $this->throwError(
                'INVALID_FIELD',
                "Meta path \"{$field}\" exceeds maximum depth of " . self::MAX_META_DEPTH,
                $path
            );
        }
    }

    /**
     * Validate a value for an operator.
     *
     * @param string $op The operator
     * @param mixed $value The value to validate
     * @param array $path The current path for error reporting
     * @throws FilterValidationException
     */
    protected function validateValueForOperator(string $op, mixed $value, array $path): void
    {
        $allowedTypes = self::OPERATORS[$op];

        // Special handling for 'in' and 'nin'
        if (in_array($op, ['in', 'nin'], true)) {
            if (!is_array($value)) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires an array value",
                    $path
                );
            }
            if (empty($value)) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires a non-empty array",
                    $path
                );
            }
            return;
        }

        // Special handling for 'contains_all'
        if ($op === 'contains_all') {
            if (!is_array($value)) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires an array value",
                    $path
                );
            }
            if (empty($value)) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires a non-empty array",
                    $path
                );
            }
            return;
        }

        // Special handling for 'length_eq'
        if ($op === 'length_eq') {
            if (!is_int($value) || $value < 0) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires a non-negative integer",
                    $path
                );
            }
            return;
        }

        // For 'contains' on strings
        if ($op === 'contains' && is_string($value)) {
            return;
        }

        // Type validation for other operators
        if (in_array('any', $allowedTypes, true)) {
            return;
        }

        // Check numeric operators
        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($value) && !$this->isIso8601Date($value)) {
                $this->throwError(
                    'INVALID_VALUE',
                    "Operator \"{$op}\" requires a numeric or ISO 8601 date value",
                    $path
                );
            }
        }
    }

    /**
     * Check if a value is an ISO 8601 date string.
     *
     * @param mixed $value The value to check
     * @return bool
     */
    protected function isIso8601Date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Basic ISO 8601 pattern check
        return preg_match('/^\d{4}-\d{2}-\d{2}([T\s]\d{2}:\d{2}(:\d{2})?)?/', $value) === 1;
    }

    /**
     * Throw a filter validation exception.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @param array $path Path to the error
     * @throws FilterValidationException
     */
    protected function throwError(string $code, string $message, array $path): void
    {
        throw new FilterValidationException($code, $message, $path);
    }
}
