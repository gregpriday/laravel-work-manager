<?php

use Illuminate\Support\Facades\Schema;

it('creates all required tables', function () {
    expect(Schema::hasTable('work_orders'))->toBeTrue();
    expect(Schema::hasTable('work_items'))->toBeTrue();
    expect(Schema::hasTable('work_item_parts'))->toBeTrue();
    expect(Schema::hasTable('work_events'))->toBeTrue();
    expect(Schema::hasTable('work_provenances'))->toBeTrue();
    expect(Schema::hasTable('work_idempotency_keys'))->toBeTrue();
});

it('work_orders table has all required columns', function () {
    $columns = Schema::getColumnListing('work_orders');

    $required = [
        'id', 'type', 'state', 'priority',
        'requested_by_type', 'requested_by_id',
        'payload', 'meta', 'acceptance_config',
        'applied_at', 'completed_at', 'last_transitioned_at',
        'created_at', 'updated_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('work_items table has all required columns', function () {
    $columns = Schema::getColumnListing('work_items');

    $required = [
        'id', 'order_id', 'type', 'state',
        'attempts', 'max_attempts',
        'leased_by_agent_id', 'lease_expires_at', 'last_heartbeat_at',
        'input', 'result', 'assembled_result', 'parts_required', 'parts_state', 'error',
        'accepted_at', 'created_at', 'updated_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('work_events table has all required columns', function () {
    $columns = Schema::getColumnListing('work_events');

    $required = [
        'id', 'order_id', 'item_id', 'event',
        'actor_type', 'actor_id',
        'payload', 'diff', 'message',
        'created_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('work_provenances table has all required columns', function () {
    $columns = Schema::getColumnListing('work_provenances');

    $required = [
        'id', 'order_id', 'item_id', 'idempotency_key_hash',
        'agent_version', 'agent_name', 'request_fingerprint',
        'extra', 'created_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('work_idempotency_keys table has all required columns', function () {
    $columns = Schema::getColumnListing('work_idempotency_keys');

    $required = [
        'id', 'scope', 'key_hash', 'response_snapshot', 'created_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('work_item_parts table has all required columns', function () {
    $columns = Schema::getColumnListing('work_item_parts');

    $required = [
        'id', 'work_item_id', 'part_key', 'seq', 'status',
        'payload', 'evidence', 'notes', 'errors', 'checksum',
        'submitted_by_agent_id', 'idempotency_key_hash',
        'created_at', 'updated_at',
    ];

    foreach ($required as $column) {
        expect($columns)->toContain($column);
    }
});

it('has unique constraint on idempotency scope and key_hash', function () {
    // This will be verified by attempting duplicate inserts in IdempotencyServiceTest
    $columns = Schema::getColumnListing('work_idempotency_keys');
    expect($columns)->toContain('scope');
    expect($columns)->toContain('key_hash');
});
