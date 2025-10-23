<?php

use GregPriday\WorkManager\Models\WorkEvent;
use GregPriday\WorkManager\Models\WorkIdempotencyKey;
use GregPriday\WorkManager\Services\WorkAllocator;

test('idempotency:purge removes old keys', function () {
    // Create old idempotency key
    $key = WorkIdempotencyKey::create([
        'scope' => 'test',
        'key_hash' => hash('sha256', 'test-key'),
        'response_snapshot' => ['result' => 'test'],
    ]);

    // Backdate the created_at timestamp using DB query (bypasses Eloquent timestamp handling)
    \Illuminate\Support\Facades\DB::table('work_idempotency_keys')
        ->where('id', $key->id)
        ->update(['created_at' => now()->subDays(60)]);

    $this->artisan('work-manager:ops:purge-keys', [
        '--older-than' => '30d',
        '--force' => true,
    ])->assertExitCode(0);

    expect(WorkIdempotencyKey::find($key->id))->toBeNull();
});

test('idempotency:purge dry-run does not delete', function () {
    $key = WorkIdempotencyKey::create([
        'scope' => 'test',
        'key_hash' => hash('sha256', 'test-key'),
        'response_snapshot' => ['result' => 'test'],
    ]);

    // Backdate the created_at timestamp using DB query (bypasses Eloquent timestamp handling)
    \Illuminate\Support\Facades\DB::table('work_idempotency_keys')
        ->where('id', $key->id)
        ->update(['created_at' => now()->subDays(60)]);

    $this->artisan('work-manager:ops:purge-keys', [
        '--older-than' => '30d',
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect(WorkIdempotencyKey::find($key->id))->not->toBeNull();
});

test('events:prune removes old events and provenance', function () {
    $allocator = app(WorkAllocator::class);
    $order = $allocator->propose('test.echo', ['message' => 'test']);

    // Create old event
    $event = WorkEvent::create([
        'order_id' => $order->id,
        'work_order_id' => $order->id,
        'event' => \GregPriday\WorkManager\Support\EventType::PROPOSED,
    ]);

    // Backdate the created_at timestamp using DB query (bypasses Eloquent timestamp handling)
    \Illuminate\Support\Facades\DB::table('work_events')
        ->where('id', $event->id)
        ->update(['created_at' => now()->subDays(120)]);

    $this->artisan('work-manager:ops:prune', [
        '--older-than' => '90d',
        '--force' => true,
    ])->assertExitCode(0);

    expect(WorkEvent::find($event->id))->toBeNull();
});
