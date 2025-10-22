<?php

use GregPriday\WorkManager\Support\Helpers;

it('generates valid UUID', function () {
    $uuid = Helpers::uuid();

    expect($uuid)->toBeString();
    expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('generates unique UUIDs', function () {
    $uuid1 = Helpers::uuid();
    $uuid2 = Helpers::uuid();

    expect($uuid1)->not->toBe($uuid2);
});

it('hashes key to 64-char hex', function () {
    $hash = Helpers::hashKey('test-key');

    expect($hash)->toBeString();
    expect(strlen($hash))->toBe(64);
    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('produces stable hash for same input', function () {
    $hash1 = Helpers::hashKey('same-key');
    $hash2 = Helpers::hashKey('same-key');

    expect($hash1)->toBe($hash2);
});

it('produces different hashes for different inputs', function () {
    $hash1 = Helpers::hashKey('key-1');
    $hash2 = Helpers::hashKey('key-2');

    expect($hash1)->not->toBe($hash2);
});

it('calculates backoff delay with exponential growth', function () {
    $attempt1 = Helpers::backoffDelay(1, 10, 0);
    $attempt2 = Helpers::backoffDelay(2, 10, 0);
    $attempt3 = Helpers::backoffDelay(3, 10, 0);

    expect($attempt1)->toBe(10);  // 10 * (2^0) = 10
    expect($attempt2)->toBe(20);  // 10 * (2^1) = 20
    expect($attempt3)->toBe(40);  // 10 * (2^2) = 40
});

it('applies jitter to backoff delay', function () {
    $delays = [];
    for ($i = 0; $i < 10; $i++) {
        $delays[] = Helpers::backoffDelay(1, 10, 5);
    }

    // All delays should be in range [10, 15]
    foreach ($delays as $delay) {
        expect($delay)->toBeGreaterThanOrEqual(10);
        expect($delay)->toBeLessThanOrEqual(15);
    }

    // Should have some variation (not all the same)
    expect(count(array_unique($delays)))->toBeGreaterThan(1);
});

it('validates required fields in schema', function () {
    $schema = [
        'required' => ['name', 'email'],
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ],
    ];

    $data = ['name' => 'John'];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['field'])->toBe('email');
    expect($errors[0]['code'])->toBe('schema.required');
});

it('validates field types in schema', function () {
    $schema = [
        'properties' => [
            'age' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
        ],
    ];

    $data = [
        'age' => 'not-a-number',
        'active' => 'not-a-boolean',
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(2);
    expect($errors[0]['code'])->toBe('schema.type_mismatch');
    expect($errors[1]['code'])->toBe('schema.type_mismatch');
});

it('validates enum values in schema', function () {
    $schema = [
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'inactive', 'pending'],
            ],
        ],
    ];

    $data = ['status' => 'invalid'];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['field'])->toBe('status');
    expect($errors[0]['code'])->toBe('schema.enum_invalid');
});

it('passes validation for valid data', function () {
    $schema = [
        'required' => ['name'],
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
        ],
    ];

    $data = [
        'name' => 'John',
        'age' => 30,
        'active' => true,
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('allows optional fields to be missing', function () {
    $schema = [
        'required' => ['name'],
        'properties' => [
            'name' => ['type' => 'string'],
            'nickname' => ['type' => 'string'], // optional
        ],
    ];

    $data = ['name' => 'John'];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});

it('validates complex types', function () {
    $schema = [
        'properties' => [
            'tags' => ['type' => 'array'],
            'metadata' => ['type' => 'object'],
        ],
    ];

    $data = [
        'tags' => ['php', 'laravel'],
        'metadata' => ['version' => '1.0'],
    ];

    $errors = Helpers::validateJsonSchema($data, $schema);

    expect($errors)->toBeEmpty();
});
