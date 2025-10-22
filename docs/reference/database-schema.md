# Database Schema Reference

Complete documentation of all database tables created by Laravel Work Manager migrations.

## Table of Contents

- [Overview](#overview)
- [work_orders](#work_orders)
- [work_items](#work_items)
- [work_item_parts](#work_item_parts)
- [work_events](#work_events)
- [work_provenances](#work_provenances)
- [work_idempotency_keys](#work_idempotency_keys)
- [Relationships](#relationships)
- [Indexes](#indexes)
- [Querying Examples](#querying-examples)

---

## Overview

Laravel Work Manager creates 6 main tables to manage work orders, items, events, and related metadata.

### Migration File

```
database/migrations/2025_01_01_000000_create_work_orders_tables.php
```

### Table Summary

| Table | Purpose | Row Count (typical) |
|-------|---------|---------------------|
| `work_orders` | High-level work contracts | Low-Medium (thousands) |
| `work_items` | Units of work to be processed | Medium-High (tens of thousands) |
| `work_item_parts` | Partial submissions for incremental work | Medium (depends on usage) |
| `work_events` | Audit trail of all state changes | High (millions in production) |
| `work_provenances` | Agent metadata and request fingerprints | Medium (one per order/item) |
| `work_idempotency_keys` | Stored idempotency keys with cached responses | Medium (grows over time) |

### Foreign Key Constraints

All tables use cascading deletes to maintain referential integrity:
- When a `work_order` is deleted, all related `work_items`, `work_events`, and `work_provenances` are automatically deleted
- When a `work_item` is deleted, all related `work_item_parts`, `work_events`, and `work_provenances` are automatically deleted

---

## work_orders

Stores high-level work order contracts.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | uuid | No | - | Primary key (UUID v4) |
| `type` | varchar(120) | No | - | Order type identifier (e.g., "user.data.sync") |
| `state` | enum | No | `'queued'` | Current state of the order |
| `priority` | smallint | No | `0` | Priority level (higher = more urgent) |
| `requested_by_type` | varchar(50) | Yes | `NULL` | Actor type who requested (user/agent/system) |
| `requested_by_id` | varchar(255) | Yes | `NULL` | ID of the requesting actor |
| `payload` | json | No | - | Order-specific data validated against type schema |
| `meta` | json | Yes | `NULL` | Optional metadata (not validated) |
| `acceptance_config` | json | Yes | `NULL` | Stored JSON schema for validation |
| `applied_at` | timestamp | Yes | `NULL` | When the order was applied |
| `completed_at` | timestamp | Yes | `NULL` | When the order was completed |
| `last_transitioned_at` | timestamp | Yes | `NULL` | Last state transition timestamp |
| `created_at` | timestamp | No | `now()` | Record creation timestamp |
| `updated_at` | timestamp | No | `now()` | Record last update timestamp |

### State Enum Values

```sql
'queued', 'checked_out', 'in_progress', 'submitted', 'approved',
'applied', 'completed', 'rejected', 'failed', 'dead_lettered'
```

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_orders_type_index` | `type` | Index | Filter by order type |
| `work_orders_state_index` | `state` | Index | Filter by state |
| `work_orders_priority_created_at_index` | `priority`, `created_at` | Index | Priority queue ordering |
| `work_orders_state_type_index` | `state`, `type` | Index | Combined state and type filtering |

### Example Data

```json
{
  "id": "01234567-89ab-cdef-0123-456789abcdef",
  "type": "user.data.sync",
  "state": "submitted",
  "priority": 10,
  "requested_by_type": "agent",
  "requested_by_id": "agent-123",
  "payload": {
    "source": "external-api",
    "filters": {"active": true}
  },
  "meta": {
    "requested_by": "admin-dashboard"
  },
  "acceptance_config": {
    "type": "object",
    "properties": {
      "source": {"type": "string"},
      "filters": {"type": "object"}
    }
  },
  "applied_at": null,
  "completed_at": null,
  "last_transitioned_at": "2025-01-15T10:35:00.000000Z",
  "created_at": "2025-01-15T10:30:00.000000Z",
  "updated_at": "2025-01-15T10:35:00.000000Z"
}
```

---

## work_items

Stores discrete units of work that agents lease and process.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | uuid | No | - | Primary key (UUID v4) |
| `order_id` | uuid | No | - | Foreign key to `work_orders.id` |
| `type` | varchar(120) | No | - | Work item type (usually matches order type) |
| `state` | enum | No | `'queued'` | Current state of the item |
| `attempts` | smallint unsigned | No | `0` | Number of retry attempts |
| `max_attempts` | smallint unsigned | No | `3` | Maximum retry attempts before failure |
| `leased_by_agent_id` | varchar(255) | Yes | `NULL` | ID of agent holding the lease |
| `lease_expires_at` | timestamp | Yes | `NULL` | When the current lease expires |
| `last_heartbeat_at` | timestamp | Yes | `NULL` | Last heartbeat timestamp |
| `input` | json | No | - | Input data for this work item |
| `result` | json | Yes | `NULL` | Submitted result data |
| `assembled_result` | json | Yes | `NULL` | Assembled result from parts |
| `parts_required` | json | Yes | `NULL` | Array of required part keys |
| `parts_state` | json | Yes | `NULL` | Materialized view of latest part states |
| `error` | json | Yes | `NULL` | Error information if failed |
| `accepted_at` | timestamp | Yes | `NULL` | When the item was accepted |
| `created_at` | timestamp | No | `now()` | Record creation timestamp |
| `updated_at` | timestamp | No | `now()` | Record last update timestamp |

### State Enum Values

```sql
'queued', 'leased', 'in_progress', 'submitted', 'accepted',
'rejected', 'completed', 'failed', 'dead_lettered'
```

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_items_order_id_foreign` | `order_id` | Foreign Key | Links to work_orders |
| `work_items_type_index` | `type` | Index | Filter by item type |
| `work_items_state_index` | `state` | Index | Filter by state |
| `work_items_lease_expires_at_index` | `lease_expires_at` | Index | Find expired leases |
| `work_items_order_id_state_index` | `order_id`, `state` | Index | Order's items by state |
| `work_items_state_lease_expires_at_index` | `state`, `lease_expires_at` | Index | Available items for lease |

### Foreign Keys

| Constraint | Columns | References | On Delete |
|------------|---------|------------|-----------|
| `work_items_order_id_foreign` | `order_id` | `work_orders(id)` | CASCADE |

### Example Data

```json
{
  "id": "fedcba98-7654-3210-fedc-ba9876543210",
  "order_id": "01234567-89ab-cdef-0123-456789abcdef",
  "type": "user.data.sync",
  "state": "leased",
  "attempts": 0,
  "max_attempts": 3,
  "leased_by_agent_id": "agent-123",
  "lease_expires_at": "2025-01-15T10:40:00.000000Z",
  "last_heartbeat_at": "2025-01-15T10:30:00.000000Z",
  "input": {
    "batch_id": 1,
    "user_ids": [123, 456, 789]
  },
  "result": null,
  "assembled_result": null,
  "parts_required": null,
  "parts_state": null,
  "error": null,
  "accepted_at": null,
  "created_at": "2025-01-15T10:30:00.000000Z",
  "updated_at": "2025-01-15T10:30:00.000000Z"
}
```

---

## work_item_parts

Stores partial submissions for incremental work item results.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | uuid | No | - | Primary key (UUID v4) |
| `work_item_id` | uuid | No | - | Foreign key to `work_items.id` |
| `part_key` | varchar(120) | No | - | Part identifier (e.g., "contacts", "identity") |
| `seq` | int unsigned | Yes | `NULL` | Sequence number for ordered parts |
| `status` | enum | No | `'draft'` | Validation status of the part |
| `payload` | json | No | - | Part data |
| `evidence` | json | Yes | `NULL` | Supporting evidence |
| `notes` | text | Yes | `NULL` | Additional notes or comments |
| `errors` | json | Yes | `NULL` | Validation errors if rejected |
| `checksum` | varchar(255) | Yes | `NULL` | SHA-256 hash of payload |
| `submitted_by_agent_id` | varchar(255) | No | - | ID of agent who submitted |
| `idempotency_key_hash` | varchar(255) | Yes | `NULL` | Hash of idempotency key |
| `created_at` | timestamp | No | `now()` | Record creation timestamp |
| `updated_at` | timestamp | No | `now()` | Record last update timestamp |

### Status Enum Values

```sql
'draft', 'validated', 'rejected'
```

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_item_parts_work_item_id_foreign` | `work_item_id` | Foreign Key | Links to work_items |
| `work_item_parts_part_key_index` | `part_key` | Index | Filter by part key |
| `work_item_parts_status_index` | `status` | Index | Filter by status |
| `work_item_parts_work_item_id_part_key_seq_unique` | `work_item_id`, `part_key`, `seq` | Unique | Prevent duplicate parts |
| `work_item_parts_work_item_id_status_index` | `work_item_id`, `status` | Index | Item's parts by status |
| `work_item_parts_work_item_id_part_key_index` | `work_item_id`, `part_key` | Index | Item's parts by key |

### Foreign Keys

| Constraint | Columns | References | On Delete |
|------------|---------|------------|-----------|
| `work_item_parts_work_item_id_foreign` | `work_item_id` | `work_items(id)` | CASCADE |

### Example Data

```json
{
  "id": "abcd1234-5678-90ef-abcd-1234567890ef",
  "work_item_id": "fedcba98-7654-3210-fedc-ba9876543210",
  "part_key": "contacts",
  "seq": 1,
  "status": "validated",
  "payload": {
    "contacts": [
      {"email": "user@example.com", "name": "John Doe"}
    ]
  },
  "evidence": {
    "source": "linkedin-api",
    "retrieved_at": "2025-01-15T10:30:00Z"
  },
  "notes": "Retrieved from LinkedIn API",
  "errors": null,
  "checksum": "abc123def456...",
  "submitted_by_agent_id": "agent-123",
  "idempotency_key_hash": null,
  "created_at": "2025-01-15T10:35:00.000000Z",
  "updated_at": "2025-01-15T10:35:00.000000Z"
}
```

---

## work_events

Stores audit trail of all state changes and actions.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | auto | Primary key (auto-increment) |
| `order_id` | uuid | No | - | Foreign key to `work_orders.id` |
| `item_id` | uuid | Yes | `NULL` | Foreign key to `work_items.id` (NULL for order-level events) |
| `event` | varchar(100) | No | - | Event type (e.g., "proposed", "leased", "submitted") |
| `actor_type` | varchar(50) | Yes | `NULL` | Type of actor (user/agent/system) |
| `actor_id` | varchar(255) | Yes | `NULL` | ID of the actor |
| `payload` | json | Yes | `NULL` | Event-specific data |
| `diff` | json | Yes | `NULL` | Diff of changes (for applied events) |
| `message` | text | Yes | `NULL` | Human-readable message |
| `created_at` | timestamp | No | `now()` | Event timestamp |

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_events_order_id_foreign` | `order_id` | Foreign Key | Links to work_orders |
| `work_events_item_id_foreign` | `item_id` | Foreign Key | Links to work_items |
| `work_events_event_index` | `event` | Index | Filter by event type |
| `work_events_created_at_index` | `created_at` | Index | Time-based queries |
| `work_events_order_id_event_index` | `order_id`, `event` | Index | Order's events by type |
| `work_events_item_id_event_index` | `item_id`, `event` | Index | Item's events by type |
| `work_events_event_created_at_index` | `event`, `created_at` | Index | Event timeline queries |

### Foreign Keys

| Constraint | Columns | References | On Delete |
|------------|---------|------------|-----------|
| `work_events_order_id_foreign` | `order_id` | `work_orders(id)` | CASCADE |
| `work_events_item_id_foreign` | `item_id` | `work_items(id)` | CASCADE |

### Example Data

```json
{
  "id": 12345,
  "order_id": "01234567-89ab-cdef-0123-456789abcdef",
  "item_id": "fedcba98-7654-3210-fedc-ba9876543210",
  "event": "submitted",
  "actor_type": "agent",
  "actor_id": "agent-123",
  "payload": {
    "result": {...},
    "evidence": {...}
  },
  "diff": null,
  "message": "Work item submitted",
  "created_at": "2025-01-15T10:35:00.000000Z"
}
```

---

## work_provenances

Stores agent metadata and request fingerprints for auditability.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | auto | Primary key (auto-increment) |
| `order_id` | uuid | Yes | `NULL` | Foreign key to `work_orders.id` |
| `item_id` | uuid | Yes | `NULL` | Foreign key to `work_items.id` |
| `idempotency_key` | varchar(120) | Yes | `NULL` | Idempotency key used |
| `agent_version` | varchar(255) | Yes | `NULL` | Agent software version |
| `agent_name` | varchar(255) | Yes | `NULL` | Agent name/identifier |
| `request_fingerprint` | varchar(64) | Yes | `NULL` | Request fingerprint hash |
| `extra` | json | Yes | `NULL` | Additional metadata |
| `created_at` | timestamp | No | `now()` | Record creation timestamp |

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_provenances_order_id_foreign` | `order_id` | Foreign Key | Links to work_orders |
| `work_provenances_item_id_foreign` | `item_id` | Foreign Key | Links to work_items |
| `work_provenances_idempotency_key_unique` | `idempotency_key` | Unique | Prevent duplicate keys |

### Foreign Keys

| Constraint | Columns | References | On Delete |
|------------|---------|------------|-----------|
| `work_provenances_order_id_foreign` | `order_id` | `work_orders(id)` | CASCADE |
| `work_provenances_item_id_foreign` | `item_id` | `work_items(id)` | CASCADE |

### Example Data

```json
{
  "id": 6789,
  "order_id": "01234567-89ab-cdef-0123-456789abcdef",
  "item_id": null,
  "idempotency_key": "abc123-unique-key",
  "agent_version": "1.2.3",
  "agent_name": "research-bot",
  "request_fingerprint": "sha256-hash-of-request",
  "extra": {
    "environment": "production",
    "deployment": "us-west-2"
  },
  "created_at": "2025-01-15T10:30:00.000000Z"
}
```

---

## work_idempotency_keys

Stores idempotency keys with cached responses for duplicate request handling.

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | auto | Primary key (auto-increment) |
| `scope` | varchar(80) | No | - | Scope of the idempotency key (e.g., "submit:item:123") |
| `key_hash` | char(64) | No | - | SHA-256 hash of the idempotency key |
| `response_snapshot` | json | Yes | `NULL` | Cached response data |
| `created_at` | timestamp | No | `now()` | Record creation timestamp |

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `PRIMARY` | `id` | Primary | Primary key lookup |
| `work_idempotency_keys_scope_key_hash_unique` | `scope`, `key_hash` | Unique | Enforce idempotency uniqueness |

### Example Data

```json
{
  "id": 456,
  "scope": "submit:item:fedcba98-7654-3210-fedc-ba9876543210",
  "key_hash": "abc123def456...",
  "response_snapshot": {
    "item": {...},
    "state": "submitted"
  },
  "created_at": "2025-01-15T10:35:00.000000Z"
}
```

---

## Relationships

### Entity Relationship Diagram

```
work_orders (1) ----< (∞) work_items
     |                       |
     |                       |
     +----< (∞) work_events <+
     |                       |
     |                       |
     +----< (∞) work_provenances
                             |
                             |
work_item_parts (∞) >----< (1) work_items
```

### Eloquent Relationships

**WorkOrder Model:**

```php
public function items(): HasMany
public function events(): HasMany
public function provenances(): HasMany
```

**WorkItem Model:**

```php
public function order(): BelongsTo
public function events(): HasMany
public function provenances(): HasMany
public function parts(): HasMany
```

**WorkEvent Model:**

```php
public function order(): BelongsTo
public function item(): BelongsTo
```

**WorkProvenance Model:**

```php
public function order(): BelongsTo
public function item(): BelongsTo
```

**WorkItemPart Model:**

```php
public function workItem(): BelongsTo
```

---

## Indexes

### Performance Considerations

1. **Priority Queues:** `work_orders_priority_created_at_index` enables efficient priority-based queue ordering
2. **Lease Management:** `work_items_state_lease_expires_at_index` enables fast expired lease queries
3. **Audit Queries:** `work_events_created_at_index` and composite indexes support efficient timeline queries
4. **State Filtering:** All state columns are indexed for fast filtering

### Index Usage Examples

**Find next available work item:**
```sql
-- Uses: work_items_state_lease_expires_at_index
SELECT * FROM work_items
WHERE state = 'queued'
  AND (lease_expires_at IS NULL OR lease_expires_at < NOW())
ORDER BY created_at ASC
LIMIT 1;
```

**Get order events timeline:**
```sql
-- Uses: work_events_order_id_event_index
SELECT * FROM work_events
WHERE order_id = '...'
ORDER BY created_at DESC;
```

**Find expired leases:**
```sql
-- Uses: work_items_state_lease_expires_at_index
SELECT * FROM work_items
WHERE lease_expires_at < NOW()
  AND state NOT IN ('completed', 'dead_lettered');
```

---

## Querying Examples

### Eloquent Queries

**Get all items for an order:**

```php
$order = WorkOrder::with('items')->find($orderId);
```

**Find available work items:**

```php
$items = WorkItem::where('order_id', $orderId)
    ->availableForLease()
    ->orderBy('created_at')
    ->get();
```

**Get event timeline:**

```php
$events = WorkEvent::where('order_id', $orderId)
    ->orderBy('created_at', 'desc')
    ->limit(100)
    ->get();
```

**Find expired leases:**

```php
$expired = WorkItem::withExpiredLease()->get();
```

**Get latest parts per key:**

```php
$latestParts = WorkItemPart::where('work_item_id', $itemId)
    ->whereIn('id', function ($query) use ($itemId) {
        $query->selectRaw('MAX(id)')
            ->from('work_item_parts')
            ->where('work_item_id', $itemId)
            ->groupBy('part_key');
    })
    ->get();
```

### Raw SQL Queries

**Order completion statistics:**

```sql
SELECT
    type,
    state,
    COUNT(*) as count,
    AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_duration_seconds
FROM work_orders
WHERE completed_at IS NOT NULL
GROUP BY type, state;
```

**Agent performance metrics:**

```sql
SELECT
    leased_by_agent_id as agent_id,
    COUNT(*) as items_processed,
    SUM(CASE WHEN state = 'completed' THEN 1 ELSE 0 END) as successful,
    SUM(CASE WHEN state = 'failed' THEN 1 ELSE 0 END) as failed
FROM work_items
WHERE leased_by_agent_id IS NOT NULL
GROUP BY leased_by_agent_id;
```

**Lease expiration rate:**

```sql
SELECT
    DATE(created_at) as date,
    COUNT(*) as expired_leases
FROM work_events
WHERE event = 'lease_expired'
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Config Reference](./config-reference.md) - Configuration options
- [Events Reference](./events-reference.md) - Event documentation for work_events table
