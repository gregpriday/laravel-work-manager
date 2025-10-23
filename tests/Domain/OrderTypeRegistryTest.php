<?php

use GregPriday\WorkManager\Exceptions\OrderTypeNotFoundException;
use GregPriday\WorkManager\Facades\WorkManager;
use GregPriday\WorkManager\Services\Registry\OrderTypeRegistry;
use GregPriday\WorkManager\Tests\Fixtures\OrderTypes\BatchOrderType;
use GregPriday\WorkManager\Tests\Fixtures\OrderTypes\EchoOrderType;

it('registers and retrieves order types', function () {
    $registry = new OrderTypeRegistry;
    $echoType = new EchoOrderType;

    $registry->register($echoType);

    expect($registry->get('test.echo'))->toBe($echoType);
});

it('checks if type is registered', function () {
    $registry = new OrderTypeRegistry;
    $echoType = new EchoOrderType;

    expect($registry->has('test.echo'))->toBeFalse();

    $registry->register($echoType);

    expect($registry->has('test.echo'))->toBeTrue();
});

it('throws when getting non-existent type', function () {
    $registry = new OrderTypeRegistry;

    expect(fn () => $registry->get('non.existent.type'))
        ->toThrow(OrderTypeNotFoundException::class);
});

it('returns all registered types', function () {
    $registry = new OrderTypeRegistry;
    $echoType = new EchoOrderType;
    $batchType = new BatchOrderType;

    $registry->register($echoType);
    $registry->register($batchType);

    $all = $registry->all();

    expect($all)->toHaveCount(2);
    expect($all)->toHaveKeys(['test.echo', 'test.batch']);
    expect($all['test.echo'])->toBe($echoType);
    expect($all['test.batch'])->toBe($batchType);
});

it('returns all registered type names', function () {
    $registry = new OrderTypeRegistry;
    $echoType = new EchoOrderType;
    $batchType = new BatchOrderType;

    $registry->register($echoType);
    $registry->register($batchType);

    $names = $registry->names();

    expect($names)->toBe(['test.echo', 'test.batch']);
});

it('allows re-registering type with same name', function () {
    $registry = new OrderTypeRegistry;
    $echoType1 = new EchoOrderType;
    $echoType2 = new EchoOrderType;

    $registry->register($echoType1);
    expect($registry->get('test.echo'))->toBe($echoType1);

    // Re-register with new instance
    $registry->register($echoType2);
    expect($registry->get('test.echo'))->toBe($echoType2);
});

it('returns empty array when no types registered', function () {
    $registry = new OrderTypeRegistry;

    expect($registry->all())->toBe([]);
    expect($registry->names())->toBe([]);
});

it('uses global registry through facade', function () {
    // The TestCase already registers test types, so they should be available
    $registry = WorkManager::registry();

    expect($registry->has('test.echo'))->toBeTrue();
    expect($registry->has('test.batch'))->toBeTrue();

    $echoType = $registry->get('test.echo');
    expect($echoType)->toBeInstanceOf(\GregPriday\WorkManager\Contracts\OrderType::class);
});

it('provides detailed exception message for missing type', function () {
    $registry = new OrderTypeRegistry;

    try {
        $registry->get('missing.type');
        $this->fail('Should have thrown OrderTypeNotFoundException');
    } catch (OrderTypeNotFoundException $e) {
        expect($e->getMessage())->toContain('missing.type');
    }
});

it('handles type registration idempotently', function () {
    $registry = new OrderTypeRegistry;
    $type = new EchoOrderType;

    // Register multiple times
    $registry->register($type);
    $registry->register($type);
    $registry->register($type);

    // Should only have one entry
    expect($registry->names())->toHaveCount(1);
    expect($registry->all())->toHaveCount(1);
});

it('maintains type identity after retrieval', function () {
    $registry = new OrderTypeRegistry;
    $echoType = new EchoOrderType;

    $registry->register($echoType);

    // Get multiple times
    $retrieved1 = $registry->get('test.echo');
    $retrieved2 = $registry->get('test.echo');

    // Should be the same instance
    expect($retrieved1)->toBe($retrieved2);
    expect($retrieved1)->toBe($echoType);
});
