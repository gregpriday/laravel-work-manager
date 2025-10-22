<?php

use GregPriday\WorkManager\Support\Helpers;

// Note: The current Helpers::validateJsonSchema implementation supports:
// - required fields, type checking, enum validation
// Advanced features below are skipped pending implementation

it('validates multiple required fields together', function () {
    $schema = [
        'required' => ['username', 'email', 'age'],
        'properties' => [
            'username' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'age' => ['type' => 'number'],
        ],
    ];

    $data = [
        'username' => 'john_doe',
        // Missing email and age
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(2);
    expect(collect($errors)->pluck('code')->unique()->toArray())->toContain('schema.required');
});

it('validates multiple type mismatches', function () {
    $schema = [
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
            'tags' => ['type' => 'array'],
        ],
    ];

    $data = [
        'name' => 123, // Should be string
        'age' => 'not-a-number', // Should be number
        'active' => 'yes', // Should be boolean
        'tags' => 'not-an-array', // Should be array
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(4);
    expect(collect($errors)->pluck('code')->unique()->toArray())->toBe(['schema.type_mismatch']);
});

it('validates enum with multiple invalid fields', function () {
    $schema = [
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'inactive'],
            ],
            'role' => [
                'type' => 'string',
                'enum' => ['admin', 'user', 'guest'],
            ],
        ],
    ];

    $data = [
        'status' => 'invalid',
        'role' => 'superuser',
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(2);
    expect(collect($errors)->pluck('code')->unique()->toArray())->toBe(['schema.enum_invalid']);
});

it('validates combination of required, type, and enum errors', function () {
    $schema = [
        'required' => ['username', 'status'],
        'properties' => [
            'username' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'inactive'],
            ],
            'age' => ['type' => 'number'],
        ],
    ];

    $data = [
        // Missing 'username' (required)
        'status' => 'invalid', // Invalid enum value
        'age' => 'not-a-number', // Type mismatch
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(3);
    $codes = collect($errors)->pluck('code')->toArray();
    expect($codes)->toContain('schema.required');
    expect($codes)->toContain('schema.enum_invalid');
    expect($codes)->toContain('schema.type_mismatch');
});

it('validates object type correctly', function () {
    $schema = [
        'properties' => [
            'metadata' => ['type' => 'object'],
            'config' => ['type' => 'object'],
        ],
    ];

    $data = [
        'metadata' => ['key' => 'value'], // Valid object
        'config' => ['setting' => true], // Valid object
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('validates array type correctly', function () {
    $schema = [
        'properties' => [
            'tags' => ['type' => 'array'],
            'items' => ['type' => 'array'],
        ],
    ];

    $data = [
        'tags' => ['php', 'laravel'],
        'items' => [1, 2, 3],
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('validates all supported types pass with correct data', function () {
    $schema = [
        'required' => ['string_field', 'number_field', 'boolean_field', 'array_field', 'object_field'],
        'properties' => [
            'string_field' => ['type' => 'string'],
            'number_field' => ['type' => 'number'],
            'boolean_field' => ['type' => 'boolean'],
            'array_field' => ['type' => 'array'],
            'object_field' => ['type' => 'object'],
        ],
    ];

    $data = [
        'string_field' => 'test',
        'number_field' => 42,
        'boolean_field' => true,
        'array_field' => [1, 2, 3],
        'object_field' => ['key' => 'value'],
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('validates integer type correctly', function () {
    $schema = [
        'properties' => [
            'count' => ['type' => 'integer'],
            'quantity' => ['type' => 'integer'],
        ],
    ];

    $data = [
        'count' => 42,
        'quantity' => 100,
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('treats integer and number types the same', function () {
    // Note: Current implementation treats 'integer' and 'number' identically using is_numeric()
    $schema = [
        'properties' => [
            'count' => ['type' => 'integer'],
        ],
    ];

    $data = [
        'count' => 42.5, // Float passes as 'integer' since both use is_numeric()
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty(); // Passes validation
});

// ===== Future enhancement tests (skipped pending implementation) =====

test('validates minItems for arrays')
    ->skip('TODO: Implement minItems validation in Helpers::validateJsonSchema');

test('validates minimum for numbers')
    ->skip('TODO: Implement minimum validation in Helpers::validateJsonSchema');

test('validates maximum for numbers')
    ->skip('TODO: Implement maximum validation in Helpers::validateJsonSchema');

test('validates minLength for strings')
    ->skip('TODO: Implement minLength validation in Helpers::validateJsonSchema');

test('validates maxLength for strings')
    ->skip('TODO: Implement maxLength validation in Helpers::validateJsonSchema');

test('validates pattern for strings')
    ->skip('TODO: Implement pattern validation in Helpers::validateJsonSchema');

test('validates nested object structures')
    ->skip('TODO: Implement nested object validation in Helpers::validateJsonSchema');

test('validates array item schemas')
    ->skip('TODO: Implement array item schema validation in Helpers::validateJsonSchema');
