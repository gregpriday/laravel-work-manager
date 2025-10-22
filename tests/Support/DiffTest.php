<?php

use GregPriday\WorkManager\Support\Diff;

it('detects added keys', function () {
    $before = ['a' => 1];
    $after = ['a' => 1, 'b' => 2];

    $diff = Diff::fromArrays($before, $after);

    expect($diff->hasChanges())->toBeTrue();
    expect($diff->changes)->toHaveKey('b');
    expect($diff->changes['b']['type'])->toBe('added');
    expect($diff->changes['b']['value'])->toBe(2);
});

it('detects modified keys', function () {
    $before = ['a' => 1, 'b' => 2];
    $after = ['a' => 1, 'b' => 3];

    $diff = Diff::fromArrays($before, $after);

    expect($diff->hasChanges())->toBeTrue();
    expect($diff->changes)->toHaveKey('b');
    expect($diff->changes['b']['type'])->toBe('modified');
    expect($diff->changes['b']['from'])->toBe(2);
    expect($diff->changes['b']['to'])->toBe(3);
});

it('detects removed keys', function () {
    $before = ['a' => 1, 'b' => 2];
    $after = ['a' => 1];

    $diff = Diff::fromArrays($before, $after);

    expect($diff->hasChanges())->toBeTrue();
    expect($diff->changes)->toHaveKey('b');
    expect($diff->changes['b']['type'])->toBe('removed');
    expect($diff->changes['b']['value'])->toBe(2);
});

it('detects no changes when arrays are identical', function () {
    $before = ['a' => 1, 'b' => 2];
    $after = ['a' => 1, 'b' => 2];

    $diff = Diff::fromArrays($before, $after);

    expect($diff->hasChanges())->toBeFalse();
    expect($diff->changes)->toBeEmpty();
});

it('includes summary when provided', function () {
    $before = ['count' => 0];
    $after = ['count' => 5];

    $diff = Diff::fromArrays($before, $after, 'Updated count');

    expect($diff->summary)->toBe('Updated count');
});

it('converts to array correctly', function () {
    $before = ['a' => 1];
    $after = ['a' => 2];

    $diff = Diff::fromArrays($before, $after, 'Test change');
    $array = $diff->toArray();

    expect($array)->toHaveKeys(['before', 'after', 'changes', 'summary']);
    expect($array['before'])->toBe($before);
    expect($array['after'])->toBe($after);
    expect($array['summary'])->toBe('Test change');
    expect($array['changes'])->not->toBeEmpty();
});

it('converts to JSON correctly', function () {
    $before = ['a' => 1];
    $after = ['a' => 2];

    $diff = Diff::fromArrays($before, $after);
    $json = $diff->toJson();

    expect($json)->toBeJson();

    $decoded = json_decode($json, true);
    expect($decoded)->toHaveKeys(['before', 'after', 'changes', 'summary']);
});

it('creates empty diff', function () {
    $diff = Diff::empty();

    expect($diff->hasChanges())->toBeFalse();
    expect($diff->before)->toBeEmpty();
    expect($diff->after)->toBeEmpty();
    expect($diff->changes)->toBeEmpty();
    expect($diff->summary)->toBeNull();
});

it('detects multiple types of changes at once', function () {
    $before = ['a' => 1, 'b' => 2, 'c' => 3];
    $after = ['a' => 1, 'b' => 99, 'd' => 4];

    $diff = Diff::fromArrays($before, $after);

    expect($diff->hasChanges())->toBeTrue();

    // 'a' unchanged
    expect($diff->changes)->not->toHaveKey('a');

    // 'b' modified
    expect($diff->changes['b']['type'])->toBe('modified');

    // 'c' removed
    expect($diff->changes['c']['type'])->toBe('removed');

    // 'd' added
    expect($diff->changes['d']['type'])->toBe('added');
});
