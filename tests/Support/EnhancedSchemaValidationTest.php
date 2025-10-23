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

// ===== Enhanced validation tests =====

test('validates minItems for arrays', function () {
    $schema = [
        'properties' => [
            'tags' => [
                'type' => 'array',
                'minItems' => 2,
            ],
        ],
    ];

    // Test with too few items
    $data = ['tags' => ['php']];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.min_items');
    expect($errors[0]['field'])->toBe('tags');

    // Test with enough items
    $data = ['tags' => ['php', 'laravel']];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates minimum for numbers', function () {
    $schema = [
        'properties' => [
            'age' => [
                'type' => 'number',
                'minimum' => 18,
            ],
        ],
    ];

    // Test below minimum
    $data = ['age' => 15];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.minimum');
    expect($errors[0]['field'])->toBe('age');

    // Test at minimum
    $data = ['age' => 18];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();

    // Test above minimum
    $data = ['age' => 25];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates maximum for numbers', function () {
    $schema = [
        'properties' => [
            'score' => [
                'type' => 'number',
                'maximum' => 100,
            ],
        ],
    ];

    // Test above maximum
    $data = ['score' => 150];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.maximum');
    expect($errors[0]['field'])->toBe('score');

    // Test at maximum
    $data = ['score' => 100];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();

    // Test below maximum
    $data = ['score' => 50];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates minLength for strings', function () {
    $schema = [
        'properties' => [
            'username' => [
                'type' => 'string',
                'minLength' => 3,
            ],
        ],
    ];

    // Test below minLength
    $data = ['username' => 'ab'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.min_length');
    expect($errors[0]['field'])->toBe('username');

    // Test at minLength
    $data = ['username' => 'abc'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();

    // Test above minLength
    $data = ['username' => 'abcdef'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates maxLength for strings', function () {
    $schema = [
        'properties' => [
            'code' => [
                'type' => 'string',
                'maxLength' => 10,
            ],
        ],
    ];

    // Test above maxLength
    $data = ['code' => '12345678901'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.max_length');
    expect($errors[0]['field'])->toBe('code');

    // Test at maxLength
    $data = ['code' => '1234567890'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();

    // Test below maxLength
    $data = ['code' => '12345'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates pattern for strings', function () {
    $schema = [
        'properties' => [
            'slug' => [
                'type' => 'string',
                'pattern' => '^[a-z0-9-]+$',
            ],
        ],
    ];

    // Test invalid pattern (contains uppercase and special chars)
    $data = ['slug' => 'Hello_World!'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.pattern');
    expect($errors[0]['field'])->toBe('slug');

    // Test valid pattern
    $data = ['slug' => 'hello-world-123'];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates nested object structures', function () {
    $schema = [
        'properties' => [
            'user' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'number'],
                    'name' => ['type' => 'string', 'minLength' => 2],
                    'email' => ['type' => 'string'],
                ],
            ],
        ],
    ];

    // Test missing required nested field
    $data = [
        'user' => [
            'id' => 123,
            // Missing 'name'
        ],
    ];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.required');
    expect($errors[0]['field'])->toContain('name');

    // Test invalid nested field
    $data = [
        'user' => [
            'id' => 123,
            'name' => 'A', // Too short
        ],
    ];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.min_length');

    // Test valid nested object
    $data = [
        'user' => [
            'id' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ],
    ];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('validates array item schemas', function () {
    $schema = [
        'properties' => [
            'coordinates' => [
                'type' => 'array',
                'items' => [
                    ['type' => 'number'], // First item must be a number
                    ['type' => 'number'], // Second item must be a number
                ],
            ],
        ],
    ];

    // Test invalid array items
    $data = ['coordinates' => ['not-a-number', 456]];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('schema.type_mismatch');

    // Test valid array items (tuple)
    $data = ['coordinates' => [123.45, 678.90]];
    $errors = Helpers::validateJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});
