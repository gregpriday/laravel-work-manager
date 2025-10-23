# Routes Reference

Complete documentation of all HTTP routes exposed by Laravel Work Manager.

## Table of Contents

- [Route Registration](#route-registration)
- [Base Configuration](#base-configuration)
- [Work Order Routes](#work-order-routes)
- [Work Item Routes](#work-item-routes)
- [Partial Submission Routes](#partial-submission-routes)
- [Common Response Codes](#common-response-codes)
- [Authentication](#authentication)
- [Idempotency](#idempotency)

---

## Route Registration

Routes can be registered in two ways:

### Auto-Registration (via config)

Enable in `config/work-manager.php`:
```php
'routes' => [
    'enabled' => true,
    'base_path' => 'agent/work',
    'middleware' => ['api', 'auth:sanctum'],
],
```

### Manual Registration (recommended)

In `routes/api.php` or service provider:
```php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes('agent/work', ['api', 'auth:sanctum']);
```

---

## Base Configuration

All routes are prefixed with the configured base path (default: `agent/work`).

**Example:** If `base_path` is `agent/work`, routes will be:
- `POST /agent/work/propose`
- `GET /agent/work/orders`
- etc.

**Default Middleware:** `['api']`
**Authentication Guard:** Configured via `routes.guard` (default: `sanctum`)

---

## Work Order Routes

### Propose Work Order

**Method:** `POST`
**Path:** `/propose`
**Route Name:** `work-manager.propose`
**Authorization:** `work.propose` policy

Create a new work order.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'propose'
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "type": "user.data.sync",
  "payload": {
    "source": "external-api",
    "filters": {"active": true}
  },
  "meta": {
    "requested_by": "admin-dashboard",
    "priority_reason": "user-request"
  },
  "priority": 10
}
```

**Request Schema:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Order type identifier (max 120 chars) |
| `payload` | object | Yes | Order-specific data, validated against type schema |
| `meta` | object | No | Optional metadata (not validated) |
| `priority` | integer | No | Priority level (higher = more urgent, default: 0) |

**Success Response (201 Created):**
```json
{
  "order": {
    "id": "01234567-89ab-cdef-0123-456789abcdef",
    "type": "user.data.sync",
    "state": "queued",
    "priority": 10,
    "requested_by_type": "agent",
    "requested_by_id": "agent-123",
    "payload": {"source": "external-api", "filters": {"active": true}},
    "meta": {"requested_by": "admin-dashboard"},
    "created_at": "2025-01-15T10:30:00.000000Z",
    "updated_at": "2025-01-15T10:30:00.000000Z"
  }
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 422 | Invalid payload | `{"message": "...", "errors": {...}}` |
| 404 | Order type not found | `{"message": "Order type 'xyz' is not registered"}` |
| 428 | Missing idempotency key | `{"error": {"code": "idempotency_key_required", "message": "...", "header": "X-Idempotency-Key"}}` |
| 403 | Authorization failed | `{"message": "This action is unauthorized."}` |

---

### List Work Orders

**Method:** `GET`
**Path:** `/orders`
**Route Name:** `work-manager.index`
**Authorization:** None (implement custom auth if needed)

List and filter work orders with comprehensive query capabilities.

**Request Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**

> **Complete Reference**: See [Query Parameters Reference](query-parameters.md) for full specification of all available parameters.

**Quick Reference:**

| Category | Parameters | Example |
|----------|------------|---------|
| **Filters** | `filter[field]=value` | `filter[state]=queued` |
| **Operators** | `filter[field]=operator value` | `filter[priority]=>50` |
| **Relations** | `filter[relation.field]=value` | `filter[items.state]=queued` |
| **Includes** | `include=relation1,relation2` | `include=events,itemsCount` |
| **Fields** | `fields[model]=field1,field2` | `fields[work_orders]=id,type` |
| **Sorting** | `sort=-field1,field2` | `sort=-priority,created_at` |
| **Pagination** | `page[size]=N&page[number]=M` | `page[size]=25&page[number]=2` |

**Default Behavior:**
- `items` relationship preloaded
- Sort: `-priority,created_at` (highest priority first, oldest first within same priority)
- Page size: 50 (max 100)

**Key Filters:**
- `filter[state]` - Order state (exact)
- `filter[type]` - Order type (exact)
- `filter[priority]` - Priority with operators (`>50`, `>=25`, etc.)
- `filter[created_at]` - Date comparison with operators
- `filter[has_available_items]` - Boolean, orders with available work items
- `filter[meta]` - JSON contains (e.g., `batch_id:42`)

**Examples:**

```bash
# High-priority queued orders
GET /orders?filter[state]=queued&filter[priority]=>50&sort=-priority

# Orders with available work, minimal payload
GET /orders?filter[has_available_items]=true&fields[work_orders]=id,type,state&include=itemsCount

# Recent submitted orders with events
GET /orders?filter[state]=submitted&filter[created_at]>=2025-01-15&include=events&sort=-created_at
```

**Success Response (200 OK):**
```json
{
  "data": [
    {
      "id": "01234567-89ab-cdef-0123-456789abcdef",
      "type": "user.data.sync",
      "state": "submitted",
      "priority": 10,
      "items": [
        {"id": "...", "state": "submitted", ...}
      ],
      "created_at": "2025-01-15T10:30:00.000000Z"
    }
  ],
  "links": {...},
  "meta": {"current_page": 1, "total": 50, ...}
}
```

---

### Show Work Order

**Method:** `GET`
**Path:** `/orders/{order}`
**Route Name:** `work-manager.show`
**Authorization:** `view` policy on WorkOrder model

Get detailed information about a specific work order.

**Request Headers:**
```
Authorization: Bearer {token}
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `order` | uuid | Work order ID |

**Success Response (200 OK):**
```json
{
  "order": {
    "id": "01234567-89ab-cdef-0123-456789abcdef",
    "type": "user.data.sync",
    "state": "submitted",
    "priority": 10,
    "requested_by_type": "agent",
    "requested_by_id": "agent-123",
    "payload": {...},
    "meta": {...},
    "items": [
      {"id": "...", "state": "submitted", "input": {...}, "result": {...}}
    ],
    "events": [
      {"event": "submitted", "created_at": "2025-01-15T10:35:00.000000Z", ...}
    ],
    "created_at": "2025-01-15T10:30:00.000000Z",
    "updated_at": "2025-01-15T10:35:00.000000Z"
  }
}
```

**Error Responses:**

| Code | Scenario |
|------|----------|
| 404 | Order not found |
| 403 | Not authorized to view this order |

---

### Approve Work Order

**Method:** `POST`
**Path:** `/orders/{order}/approve`
**Route Name:** `work-manager.approve`
**Authorization:** `work.approve` policy

Approve a work order and apply its changes.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'approve'
Authorization: Bearer {token}
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `order` | uuid | Work order ID |

**Success Response (200 OK):**
```json
{
  "order": {
    "id": "01234567-89ab-cdef-0123-456789abcdef",
    "state": "applied",
    "applied_at": "2025-01-15T10:40:00.000000Z",
    ...
  },
  "diff": {
    "summary": "Synced 100 users",
    "operations": [
      {"op": "add", "path": "/users/123", "value": {...}},
      {"op": "update", "path": "/users/456", "value": {...}}
    ],
    "stats": {
      "added": 50,
      "updated": 45,
      "deleted": 5
    }
  }
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 400 | Order not ready for approval | `{"message": "Order is not ready for approval"}` |
| 409 | Invalid state transition | `{"message": "Cannot approve order in state 'queued'"}` |
| 428 | Missing idempotency key | `{"error": {"code": "idempotency_key_required", ...}}` |
| 403 | Not authorized | `{"message": "This action is unauthorized."}` |

---

### Reject Work Order

**Method:** `POST`
**Path:** `/orders/{order}/reject`
**Route Name:** `work-manager.reject`
**Authorization:** `work.reject` policy

Reject a work order with structured error feedback.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'reject'
Authorization: Bearer {token}
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `order` | uuid | Work order ID |

**Request Body:**
```json
{
  "errors": [
    {
      "code": "invalid_email",
      "message": "Email format is invalid",
      "field": "users.0.email"
    },
    {
      "code": "missing_required_field",
      "message": "Name is required",
      "field": "users.0.name"
    }
  ],
  "allow_rework": true
}
```

**Request Schema:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `errors` | array | Yes | Array of error objects |
| `errors[].code` | string | Yes | Error code identifier |
| `errors[].message` | string | Yes | Human-readable error message |
| `errors[].field` | string | No | Specific field path (JSON pointer style) |
| `allow_rework` | boolean | No | If true, order returns to `queued` state; if false, moves to `rejected` |

**Success Response (200 OK):**
```json
{
  "order": {
    "id": "01234567-89ab-cdef-0123-456789abcdef",
    "state": "queued",  // or "rejected" if allow_rework=false
    ...
  }
}
```

---

### Checkout Work Item

**Method:** `POST`
**Path:** `/orders/{order}/checkout`
**Route Name:** `work-manager.checkout`
**Authorization:** `work.checkout` policy

Lease the next available work item for an order.

**Request Headers:**
```
Authorization: Bearer {token}
X-Agent-ID: agent-123  // Optional, identifies the agent
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `order` | uuid | Work order ID |

**Success Response (200 OK):**
```json
{
  "item": {
    "id": "fedcba98-7654-3210-fedc-ba9876543210",
    "type": "user.data.sync",
    "input": {
      "batch_id": 1,
      "user_ids": [123, 456, 789]
    },
    "lease_expires_at": "2025-01-15T10:40:00.000000Z",
    "heartbeat_every_seconds": 120
  }
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 409 | No items available | `{"error": {"code": "no_items_available", "message": "No work items available for checkout"}}` |
| 409 | Lease conflict | `{"error": {"code": "lease_conflict", "message": "..."}}` |
| 403 | Not authorized | `{"message": "This action is unauthorized."}` |

---

## Work Item Routes

### Heartbeat (Extend Lease)

**Method:** `POST`
**Path:** `/items/{item}/heartbeat`
**Route Name:** `work-manager.heartbeat`
**Authorization:** None (verified via agent ID)

Extend the lease on a work item to prevent expiration.

**Request Headers:**
```
Authorization: Bearer {token}
X-Agent-ID: agent-123
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Success Response (200 OK):**
```json
{
  "lease_expires_at": "2025-01-15T10:45:00.000000Z"
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 409 | Lease expired | `{"error": {"code": "lease_error", "message": "The lease on this work item has expired"}}` |
| 409 | Wrong agent | `{"error": {"code": "lease_error", "message": "This item is leased by a different agent"}}` |

---

### Submit Work Item

**Method:** `POST`
**Path:** `/items/{item}/submit`
**Route Name:** `work-manager.submit`
**Authorization:** `work.submit` policy

Submit results for a work item.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'submit'
Authorization: Bearer {token}
X-Agent-ID: agent-123
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Request Body:**
```json
{
  "result": {
    "success": true,
    "processed": 150,
    "data": [...]
  },
  "evidence": {
    "api_responses": [...],
    "checksums": [...]
  },
  "notes": "Processed successfully with no errors"
}
```

**Request Schema:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `result` | object | Yes | Work item result data (validated by order type) |
| `evidence` | object | No | Supporting evidence for the submission |
| `notes` | string | No | Additional notes or comments |

**Success Response (202 Accepted):**
```json
{
  "item": {
    "id": "fedcba98-7654-3210-fedc-ba9876543210",
    "state": "submitted",
    "result": {...},
    ...
  },
  "state": "submitted"
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 422 | Validation failed | `{"message": "...", "errors": {"result.field": ["..."]}}` |
| 409 | Lease expired | `{"message": "The lease on this work item has expired"}` |
| 409 | Wrong agent | `{"message": "Item is not leased by this agent"}` |
| 428 | Missing idempotency key | `{"error": {"code": "idempotency_key_required", ...}}` |

---

### Release Work Item

**Method:** `POST`
**Path:** `/items/{item}/release`
**Route Name:** `work-manager.release`
**Authorization:** None (verified via agent ID)

Explicitly release a lease on a work item.

**Request Headers:**
```
Authorization: Bearer {token}
X-Agent-ID: agent-123
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Success Response (200 OK):**
```json
{
  "item": {
    "id": "fedcba98-7654-3210-fedc-ba9876543210",
    "state": "queued",
    "leased_by_agent_id": null,
    "lease_expires_at": null,
    ...
  }
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 409 | Wrong agent | `{"error": {"code": "lease_error", "message": "This item is leased by a different agent"}}` |

---

### Get Item Logs

**Method:** `GET`
**Path:** `/items/{item}/logs`
**Route Name:** `work-manager.logs`
**Authorization:** None

Get event logs for a work item and its parent order.

**Request Headers:**
```
Authorization: Bearer {token}
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Success Response (200 OK):**
```json
{
  "events": [
    {
      "id": 123,
      "order_id": "01234567-89ab-cdef-0123-456789abcdef",
      "item_id": "fedcba98-7654-3210-fedc-ba9876543210",
      "event": "submitted",
      "actor_type": "agent",
      "actor_id": "agent-123",
      "payload": {...},
      "message": "Work item submitted",
      "created_at": "2025-01-15T10:35:00.000000Z"
    },
    ...
  ]
}
```

---

## Partial Submission Routes

### Submit Work Item Part

**Method:** `POST`
**Path:** `/items/{item}/parts`
**Route Name:** `work-manager.submit-part`
**Authorization:** `work.submit` policy

Submit an incremental part of a work item result.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'submit-part'
Authorization: Bearer {token}
X-Agent-ID: agent-123
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Request Body:**
```json
{
  "part_key": "contacts",
  "seq": 1,
  "payload": {
    "contacts": [
      {"email": "user@example.com", "name": "John Doe"}
    ]
  },
  "evidence": {
    "source": "linkedin-api",
    "retrieved_at": "2025-01-15T10:30:00Z"
  },
  "notes": "Retrieved from LinkedIn API"
}
```

**Request Schema:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `part_key` | string | Yes | Part identifier (max 120 chars) |
| `seq` | integer | No | Sequence number for ordered parts (min: 0) |
| `payload` | object | Yes | Part data (validated by order type) |
| `evidence` | object | No | Supporting evidence |
| `notes` | string | No | Additional notes |

**Success Response (202 Accepted):**
```json
{
  "success": true,
  "part": {
    "id": "abcd1234-5678-90ef-abcd-1234567890ef",
    "part_key": "contacts",
    "seq": 1,
    "status": "validated"
  },
  "item_parts_state": {
    "contacts": {
      "status": "validated",
      "seq": 1,
      "checksum": "abc123...",
      "submitted_at": "2025-01-15T10:35:00.000000Z"
    }
  }
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 422 | Validation failed | `{"message": "...", "errors": {...}}` |
| 409 | Lease expired | `{"message": "The lease on this work item has expired"}` |
| 409 | Wrong agent | `{"message": "Item is not leased by this agent"}` |
| 428 | Missing idempotency key | `{"error": {"code": "idempotency_key_required", ...}}` |

---

### List Work Item Parts

**Method:** `GET`
**Path:** `/items/{item}/parts`
**Route Name:** `work-manager.list-parts`
**Authorization:** `view` policy on parent WorkOrder

List all parts submitted for a work item.

**Request Headers:**
```
Authorization: Bearer {token}
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Query Parameters:**

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `part_key` | string | Filter by part key | `?part_key=contacts` |
| `status` | string | Filter by status | `?status=validated` |

**Success Response (200 OK):**
```json
{
  "parts": [
    {
      "id": "abcd1234-5678-90ef-abcd-1234567890ef",
      "part_key": "contacts",
      "seq": 1,
      "status": "validated",
      "payload": {...},
      "evidence": {...},
      "notes": "Retrieved from LinkedIn API",
      "errors": null,
      "checksum": "abc123...",
      "submitted_by_agent_id": "agent-123",
      "created_at": "2025-01-15T10:35:00.000000Z"
    }
  ],
  "parts_state": {
    "contacts": {
      "status": "validated",
      "seq": 1,
      "checksum": "abc123...",
      "submitted_at": "2025-01-15T10:35:00.000000Z"
    }
  }
}
```

---

### Finalize Work Item

**Method:** `POST`
**Path:** `/items/{item}/finalize`
**Route Name:** `work-manager.finalize`
**Authorization:** `work.submit` policy

Assemble all validated parts into a final result and submit the work item.

**Request Headers:**
```
Content-Type: application/json
X-Idempotency-Key: {unique-key}  // Required if enforce_on includes 'finalize'
Authorization: Bearer {token}
X-Agent-ID: agent-123
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `item` | uuid | Work item ID |

**Request Body:**
```json
{
  "mode": "strict"
}
```

**Request Schema:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `mode` | string | No | Finalization mode: `strict` (default) or `best_effort` |

**Finalization Modes:**
- `strict` - All required parts must be validated (fails if any missing)
- `best_effort` - Assemble available validated parts, skip missing ones

**Success Response (202 Accepted):**
```json
{
  "success": true,
  "item": {
    "id": "fedcba98-7654-3210-fedc-ba9876543210",
    "state": "submitted",
    "assembled_result": {
      "contacts": [...],
      "firmographics": {...},
      "identity": {...}
    }
  },
  "order_state": "submitted"
}
```

**Error Responses:**

| Code | Scenario | Response Body |
|------|----------|---------------|
| 422 | Missing required parts (strict mode) | `{"message": "...", "errors": {"parts": ["Missing required parts: identity, firmographics"]}}` |
| 422 | Assembled result validation failed | `{"message": "...", "errors": {...}}` |
| 428 | Missing idempotency key | `{"error": {"code": "idempotency_key_required", ...}}` |

---

## Common Response Codes

### Success Codes

| Code | Status | Usage |
|------|--------|-------|
| 200 | OK | Successful GET requests, non-mutation POSTs |
| 201 | Created | Resource created (propose) |
| 202 | Accepted | Submission accepted for processing |

### Client Error Codes

| Code | Status | Scenario |
|------|--------|----------|
| 400 | Bad Request | Invalid request format or business logic error |
| 403 | Forbidden | Authorization failure |
| 404 | Not Found | Resource not found |
| 409 | Conflict | State conflict (lease issues, race conditions) |
| 422 | Unprocessable Entity | Validation failure |
| 428 | Precondition Required | Missing required idempotency key |

### Server Error Codes

| Code | Status | Scenario |
|------|--------|----------|
| 500 | Internal Server Error | Unexpected server error |
| 503 | Service Unavailable | System maintenance or overload |

---

## Authentication

All routes require authentication via the configured guard (default: `sanctum`).

**Authorization Header:**
```
Authorization: Bearer {access-token}
```

**Agent Identification:**

Agents can be identified via:
1. **X-Agent-ID header** (recommended):
   ```
   X-Agent-ID: agent-123
   ```

2. **Authenticated user ID**: If not provided, falls back to `Auth::id()`

---

## Idempotency

### Idempotency Header

Configure header name in `config/work-manager.php`:
```php
'idempotency' => [
    'header' => 'X-Idempotency-Key',
],
```

### Enforcement

Configure which endpoints require idempotency keys:
```php
'idempotency' => [
    'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
],
```

### Usage

**First Request:**
```http
POST /agent/work/items/{item}/submit
X-Idempotency-Key: abc123-unique-key
Content-Type: application/json

{"result": {...}}
```

**Response (202 Accepted):**
```json
{
  "item": {...},
  "state": "submitted"
}
```

**Retry with Same Key:**
```http
POST /agent/work/items/{item}/submit
X-Idempotency-Key: abc123-unique-key
Content-Type: application/json

{"result": {...}}
```

**Response (202 Accepted, cached):**
```json
{
  "item": {...},
  "state": "submitted"
}
```

**Key Properties:**
- Keys are scoped per endpoint and resource
- Cached responses are returned for duplicate keys
- Keys should be unique per logical operation (e.g., UUID v4)
- Keys are stored as SHA-256 hashes

---

## Rate Limiting

Apply rate limiting via middleware in route registration:

```php
WorkManager::routes('agent/work', ['api', 'auth:sanctum', 'throttle:60,1']);
```

**Per-User Rate Limit:**
```php
'middleware' => ['api', 'auth:sanctum', 'throttle:rate_limit,1'],
```

Define `rate_limit` in `RouteServiceProvider`:
```php
RateLimiter::for('rate_limit', function (Request $request) {
    return Limit::perUser(100)->per(1);
});
```

---

## CORS Configuration

If accessing from browser-based agents, configure CORS in `config/cors.php`:

```php
'paths' => ['api/*', 'agent/work/*'],
'allowed_methods' => ['*'],
'allowed_origins' => ['https://your-agent-ui.com'],
'allowed_headers' => ['*', 'X-Idempotency-Key', 'X-Agent-ID'],
```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Config Reference](./config-reference.md) - Configuration options
- [Events Reference](./events-reference.md) - Event documentation
- [Database Schema](./database-schema.md) - Database structure
