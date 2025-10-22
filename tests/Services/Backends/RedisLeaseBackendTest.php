<?php

use GregPriday\WorkManager\Services\Backends\RedisLeaseBackend;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config()->set('work-manager.lease.redis_connection', 'default');
    config()->set('work-manager.lease.redis_prefix', 'work:lease:');

    $this->backend = new RedisLeaseBackend();
});

test('acquire returns true when lease is acquired successfully', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('set')
        ->with('work:lease:item:123', 'agent-1', 'EX', 600, 'NX')
        ->andReturn(true);

    $result = $this->backend->acquire('item:123', 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('acquire returns false when key already exists', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('set')
        ->with('work:lease:item:123', 'agent-2', 'EX', 600, 'NX')
        ->andReturn(false); // Key already exists

    $result = $this->backend->acquire('item:123', 'agent-2', 600);

    expect($result)->toBeFalse();
});

test('acquire uses correct redis connection from config', function () {
    config()->set('work-manager.lease.redis_connection', 'cache');

    $backend = new RedisLeaseBackend();

    Redis::shouldReceive('connection')
        ->with('cache')
        ->andReturnSelf()
        ->shouldReceive('set')
        ->andReturn(true);

    $result = $backend->acquire('item:123', 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('acquire uses correct prefix from config', function () {
    config()->set('work-manager.lease.redis_prefix', 'custom:prefix:');

    $backend = new RedisLeaseBackend();

    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('set')
        ->with('custom:prefix:item:123', 'agent-1', 'EX', 600, 'NX')
        ->andReturn(true);

    $result = $backend->acquire('item:123', 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('acquire accepts connection override in constructor', function () {
    $backend = new RedisLeaseBackend('custom-connection');

    Redis::shouldReceive('connection')
        ->with('custom-connection')
        ->andReturnSelf()
        ->shouldReceive('set')
        ->andReturn(true);

    $result = $backend->acquire('item:123', 'agent-1', 600);

    expect($result)->toBeTrue();
});

test('extend returns true when owner matches', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->andReturn(1); // Success

    $result = $this->backend->extend('item:123', 'agent-1', 900);

    expect($result)->toBeTrue();
});

test('extend returns false when owner does not match', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->andReturn(0); // Failure

    $result = $this->backend->extend('item:123', 'agent-2', 900);

    expect($result)->toBeFalse();
});

test('extend uses Lua script for atomic compare-and-swap', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->withArgs(function ($script, $numKeys, $key, $owner, $ttl) {
            return str_contains($script, 'redis.call("get", KEYS[1]) == ARGV[1]')
                && str_contains($script, 'redis.call("expire", KEYS[1], ARGV[2])')
                && $numKeys === 1
                && $key === 'work:lease:item:123'
                && $owner === 'agent-1'
                && $ttl === 900;
        })
        ->andReturn(1);

    $result = $this->backend->extend('item:123', 'agent-1', 900);

    expect($result)->toBeTrue();
});

test('release returns true when owner matches', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->andReturn(1); // Success

    $result = $this->backend->release('item:123', 'agent-1');

    expect($result)->toBeTrue();
});

test('release returns false when owner does not match', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->andReturn(0); // Failure

    $result = $this->backend->release('item:123', 'agent-2');

    expect($result)->toBeFalse();
});

test('release uses Lua script for atomic delete', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('eval')
        ->withArgs(function ($script, $numKeys, $key, $owner) {
            return str_contains($script, 'redis.call("get", KEYS[1]) == ARGV[1]')
                && str_contains($script, 'redis.call("del", KEYS[1])')
                && $numKeys === 1
                && $key === 'work:lease:item:123'
                && $owner === 'agent-1';
        })
        ->andReturn(1);

    $result = $this->backend->release('item:123', 'agent-1');

    expect($result)->toBeTrue();
});

test('reclaim returns 0 because Redis handles expiration via TTL', function () {
    $count = $this->backend->reclaim(['item:123', 'item:456']);

    expect($count)->toBe(0);
});

test('getOwner returns owner when lease exists', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->with('work:lease:item:123')
        ->andReturn('agent-1');

    $owner = $this->backend->getOwner('item:123');

    expect($owner)->toBe('agent-1');
});

test('getOwner returns null when lease does not exist', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->with('work:lease:item:123')
        ->andReturn(null);

    $owner = $this->backend->getOwner('item:123');

    expect($owner)->toBeNull();
});

test('getOwner returns null for empty string', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->andReturn('');

    $owner = $this->backend->getOwner('item:123');

    expect($owner)->toBeNull();
});

test('getTtl returns seconds remaining when lease exists', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('ttl')
        ->with('work:lease:item:123')
        ->andReturn(450);

    $ttl = $this->backend->getTtl('item:123');

    expect($ttl)->toBe(450);
});

test('getTtl returns null when key does not exist', function () {
    Redis::shouldReceive('connection')
        ->with('default')
        ->andReturnSelf()
        ->shouldReceive('ttl')
        ->with('work:lease:item:123')
        ->andReturn(-2); // Key doesn't exist

    $ttl = $this->backend->getTtl('item:123');

    expect($ttl)->toBeNull();
});

test('getTtl returns null when no expiration set', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('ttl')
        ->andReturn(-1); // No expiration

    $ttl = $this->backend->getTtl('item:123');

    expect($ttl)->toBeNull();
});

test('isHeldBy returns true when owner matches', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->with('work:lease:item:123')
        ->andReturn('agent-1');

    $result = $this->backend->isHeldBy('item:123', 'agent-1');

    expect($result)->toBeTrue();
});

test('isHeldBy returns false when owner does not match', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->with('work:lease:item:123')
        ->andReturn('agent-1');

    $result = $this->backend->isHeldBy('item:123', 'agent-2');

    expect($result)->toBeFalse();
});

test('isHeldBy returns false when lease does not exist', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('get')
        ->andReturn(null);

    $result = $this->backend->isHeldBy('item:123', 'agent-1');

    expect($result)->toBeFalse();
});

test('getAllLeases returns all active leases', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('keys')
        ->with('work:lease:*')
        ->andReturn([
            'work:lease:item:123',
            'work:lease:item:456',
        ])
        ->shouldReceive('get')
        ->with('work:lease:item:123')
        ->andReturn('agent-1')
        ->shouldReceive('get')
        ->with('work:lease:item:456')
        ->andReturn('agent-2');

    $leases = $this->backend->getAllLeases();

    expect($leases)->toBe([
        'item:123' => 'agent-1',
        'item:456' => 'agent-2',
    ]);
});

test('getAllLeases returns empty array when no leases', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('keys')
        ->with('work:lease:*')
        ->andReturn([]);

    $leases = $this->backend->getAllLeases();

    expect($leases)->toBe([]);
});

test('clearAll deletes all leases and returns count', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('keys')
        ->with('work:lease:*')
        ->andReturn([
            'work:lease:item:123',
            'work:lease:item:456',
            'work:lease:item:789',
        ])
        ->shouldReceive('del')
        ->with('work:lease:item:123', 'work:lease:item:456', 'work:lease:item:789')
        ->andReturn(3);

    $count = $this->backend->clearAll();

    expect($count)->toBe(3);
});

test('clearAll returns 0 when no leases to clear', function () {
    Redis::shouldReceive('connection')
        ->andReturnSelf()
        ->shouldReceive('keys')
        ->with('work:lease:*')
        ->andReturn([]);

    $count = $this->backend->clearAll();

    expect($count)->toBe(0);
});
