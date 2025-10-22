# Database and Migrations Guide

**By the end of this guide, you'll be able to:** Understand the database schema, tables and relationships, indexes, and how to work with the data models.

---

## Publishing Migrations

```bash
php artisan vendor:publish --tag=work-manager-migrations
php artisan migrate
```

---

## Tables Created

### work_orders

Main work order table.

**Columns**:
- `id` (UUID, primary key)
- `type` (string) - Order type identifier
- `state` (enum) - Current state
- `priority` (smallint) - Priority (0-100)
- `requested_by_type` (string) - Actor type
- `requested_by_id` (string) - Actor ID
- `payload` (json) - Order data
- `meta` (json) - Metadata
- `acceptance_config` (json) - Acceptance configuration
- `applied_at`, `completed_at`, `last_transitioned_at` (timestamps)
- `created_at`, `updated_at` (timestamps)

**Indexes**:
- Primary: `id`
- `type`
- `state`
- `state, type`
- `priority, created_at`

### work_items

Individual units of work.

**Columns**:
- `id` (UUID, primary key)
- `order_id` (UUID, foreign key)
- `type` (string) - Item type
- `state` (enum) - Current state
- `attempts`, `max_attempts` (smallint) - Retry tracking
- `leased_by_agent_id` (string) - Current lease holder
- `lease_expires_at` (timestamp) - Lease expiration
- `last_heartbeat_at` (timestamp) - Last heartbeat
- `input` (json) - Work instructions
- `result` (json) - Agent submission
- `assembled_result` (json) - From partial submissions
- `parts_required`, `parts_state` (json) - Partial submission tracking
- `error` (json) - Error details
- `accepted_at` (timestamp)
- `created_at`, `updated_at` (timestamps)

**Indexes**:
- Primary: `id`
- Foreign: `order_id`
- `type`
- `state`
- `lease_expires_at`
- `order_id, state`
- `state, lease_expires_at`

### work_item_parts

Partial submission storage.

**Columns**:
- `id` (UUID, primary key)
- `work_item_id` (UUID, foreign key)
- `part_key` (string) - Part identifier
- `seq` (integer) - Sequence number
- `status` (enum) - draft, validated, rejected
- `payload` (json) - Part data
- `evidence`, `errors` (json)
- `notes` (text)
- `checksum` (string)
- `submitted_by_agent_id` (string)
- `idempotency_key_hash` (string)
- `created_at`, `updated_at` (timestamps)

**Indexes**:
- Primary: `id`
- Foreign: `work_item_id`
- Unique: `work_item_id, part_key, seq`
- `work_item_id, status`
- `work_item_id, part_key`

### work_events

Audit trail.

**Columns**:
- `id` (bigint, primary key)
- `order_id`, `item_id` (UUIDs, foreign keys)
- `event` (string) - Event type
- `actor_type`, `actor_id` (strings)
- `payload`, `diff` (json)
- `message` (text)
- `created_at` (timestamp)

**Indexes**:
- Primary: `id`
- Foreign: `order_id`, `item_id`
- `order_id, event`
- `item_id, event`
- `event, created_at`

### work_provenances

Request tracking.

**Columns**:
- `id` (bigint)
- `order_id`, `item_id` (UUIDs)
- `idempotency_key` (string, unique)
- `agent_version`, `agent_name` (strings)
- `request_fingerprint` (string)
- `extra` (json)
- `created_at` (timestamp)

### work_idempotency_keys

Idempotency key storage.

**Columns**:
- `id` (bigint)
- `scope` (string)
- `key_hash` (char(64))
- `response_snapshot` (json)
- `created_at` (timestamp)

**Unique**: `scope, key_hash`

---

## Relationships

```php
// WorkOrder
$order->items()        // HasMany WorkItem
$order->events()       // HasMany WorkEvent
$order->provenance()   // HasOne WorkProvenance

// WorkItem
$item->order()         // BelongsTo WorkOrder
$item->parts()         // HasMany WorkItemPart
$item->events()        // HasMany WorkEvent

// WorkItemPart
$part->item()          // BelongsTo WorkItem
```

---

## Querying

### Find Orders by State

```php
$queued = WorkOrder::where('state', OrderState::QUEUED)->get();
```

### Find Items Available for Lease

```php
$available = WorkItem::where('state', ItemState::QUEUED)
    ->whereNull('lease_expires_at')
    ->orderBy('created_at')
    ->first();
```

### Get Order with Items

```php
$order = WorkOrder::with('items', 'events')->find($id);
```

---

## See Also

- [Service Provider Guide](service-provider-and-bootstrapping.md)
- [Configuration Guide](configuration.md)
- Main [README.md](../../README.md)
