<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Models\WorkProvenance;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('WorkProvenance can be created with all attributes', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $idempotencyKeyHash = hash('sha256', 'test-key-123');

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'idempotency_key_hash' => $idempotencyKeyHash,
        'agent_version' => '1.0.0',
        'agent_name' => 'claude-agent',
        'request_fingerprint' => hash('sha256', 'request-data'),
        'extra' => ['custom' => 'data'],
    ]);

    expect($provenance)->toBeInstanceOf(WorkProvenance::class);
    expect($provenance->order_id)->toBe($order->id);
    expect($provenance->item_id)->toBe($item->id);
    expect($provenance->idempotency_key_hash)->toBe($idempotencyKeyHash);
    expect($provenance->agent_version)->toBe('1.0.0');
    expect($provenance->agent_name)->toBe('claude-agent');
    expect($provenance->extra)->toBe(['custom' => 'data']);
});

test('WorkProvenance::scopeByAgent filters by agent name', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'claude-agent',
    ]);
    WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'gpt-agent',
    ]);
    WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'claude-agent',
    ]);

    $claudeProvenances = WorkProvenance::query()->byAgent('claude-agent')->get();
    expect($claudeProvenances)->toHaveCount(2);
    expect($claudeProvenances->first()->agent_name)->toBe('claude-agent');
});

test('WorkProvenance relationships work correctly', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $item = WorkItem::create([
        'order_id' => $order->id,
        'type' => 'test.echo',
        'state' => ItemState::QUEUED,
        'input' => [],
        'max_attempts' => 3,
    ]);

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'item_id' => $item->id,
        'agent_name' => 'claude-agent',
    ]);

    expect($provenance->order->id)->toBe($order->id);
    expect($provenance->item->id)->toBe($item->id);
});

test('WorkProvenance extra field handles empty arrays', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'claude-agent',
        'extra' => [],
    ]);

    expect($provenance->extra)->toBe([]);
});

test('WorkProvenance extra field handles null values', function () {
    $order = WorkOrder::create([
        'type' => 'test.echo',
        'state' => OrderState::QUEUED,
        'requested_by_type' => ActorType::AGENT,
        'requested_by_id' => 'agent-1',
        'payload' => [],
    ]);

    $provenance = WorkProvenance::create([
        'order_id' => $order->id,
        'agent_name' => 'claude-agent',
        'extra' => null,
    ]);

    expect($provenance->extra)->toBeNull();
});
