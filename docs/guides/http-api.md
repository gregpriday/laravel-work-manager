# HTTP API Guide

**By the end of this guide, you'll be able to:** Use all HTTP endpoints, understand request/response formats, implement authentication, and handle errors effectively.

---

## API Overview

The Work Manager HTTP API provides RESTful endpoints for:
- Proposing work orders
- Checking out and processing work items
- Submitting results (complete or partial)
- Approving/rejecting orders
- Viewing logs and status

**Base Path**: Configured in `config/work-manager.php` or via `WorkManager::routes()`

**Default**: `/api/agent/work/*` (Laravel auto-prefixes with `/api`)

---

## Authentication

All endpoints require authentication by default.

### Using Sanctum (Default)

```bash
# 1. Obtain token
curl -X POST https://yourapp.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"agent@example.com","password":"secret"}'

# Response: {"token": "1|abc123..."}

# 2. Use token in requests
curl -X GET https://yourapp.com/api/agent/work/orders \
  -H "Authorization: Bearer 1|abc123..."
```

### Custom Guards

Configure in `config/work-manager.php`:

```php
'routes' => [
    'guard' => 'api',  // Use 'api' guard instead of 'sanctum'
],
```

---

## Endpoints Reference

### POST /propose

Create a new work order.

**Request**:
```bash
curl -X POST /api/agent/work/propose \
  -H "Authorization: Bearer {token}" \
  -H "X-Idempotency-Key: propose-123" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "user.data.sync",
    "payload": {
      "source": "crm",
      "user_ids": [1, 2, 3]
    },
    "meta": {
      "requested_by": "admin"
    },
    "priority": 10
  }'
```

**Response (201)**:
```json
{
  "order": {
    "id": "9a7c8f2e-5b4d-4e3a-8c9d-1a2b3c4d5e6f",
    "type": "user.data.sync",
    "state": "queued",
    "priority": 10,
    "payload": {
      "source": "crm",
      "user_ids": [1, 2, 3]
    },
    "created_at": "2025-10-22T12:00:00Z"
  }
}
```

**Required Headers**:
- `X-Idempotency-Key` (if configured as required)

---

### GET /orders

List work orders with optional filtering.

**Request**:
```bash
curl -X GET "/api/agent/work/orders?state=queued&type=user.data.sync&limit=20" \
  -H "Authorization: Bearer {token}"
```

**Query Parameters**:
- `state` (optional): Filter by state (queued, in_progress, submitted, etc.)
- `type` (optional): Filter by order type
- `requested_by_type` (optional): Filter by actor type
- `limit` (optional): Max results (default: 50, max: 100)

**Response (200)**:
```json
{
  "current_page": 1,
  "data": [
    {
      "id": "9a7c8f2e-5b4d-4e3a-8c9d-1a2b3c4d5e6f",
      "type": "user.data.sync",
      "state": "queued",
      "priority": 10,
      "items": [
        {
          "id": "item-uuid-1",
          "state": "queued"
        }
      ],
      "created_at": "2025-10-22T12:00:00Z"
    }
  ],
  "total": 1
}
```

---

### GET /orders/{order}

Get detailed information about a specific order.

**Request**:
```bash
curl -X GET /api/agent/work/orders/9a7c8f2e-5b4d-4e3a-8c9d-1a2b3c4d5e6f \
  -H "Authorization: Bearer {token}"
```

**Response (200)**:
```json
{
  "order": {
    "id": "9a7c8f2e-5b4d-4e3a-8c9d-1a2b3c4d5e6f",
    "type": "user.data.sync",
    "state": "queued",
    "payload": {...},
    "items": [...],
    "events": [...]
  }
}
```

---

### POST /orders/{order}/checkout

Lease the next available work item from an order.

**Request**:
```bash
curl -X POST /api/agent/work/orders/9a7c8f2e.../checkout \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: my-agent-123"
```

**Response (200)**:
```json
{
  "item": {
    "id": "item-uuid",
    "type": "user.data.sync",
    "input": {
      "user_ids": [1, 2, 3],
      "source": "crm"
    },
    "lease_expires_at": "2025-10-22T12:10:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

**Error (409)** - No items available:
```json
{
  "error": {
    "code": "no_items_available",
    "message": "No work items available for checkout"
  }
}
```

---

### POST /items/{item}/heartbeat

Extend lease on a work item.

**Request**:
```bash
curl -X POST /api/agent/work/items/item-uuid/heartbeat \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: my-agent-123"
```

**Response (200)**:
```json
{
  "lease_expires_at": "2025-10-22T12:12:00Z"
}
```

**Send heartbeats every** `heartbeat_every_seconds` **to maintain lease.**

---

### POST /items/{item}/submit

Submit complete work item results.

**Request**:
```bash
curl -X POST /api/agent/work/items/item-uuid/submit \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: my-agent-123" \
  -H "X-Idempotency-Key: submit-item-uuid-1" \
  -H "Content-Type: application/json" \
  -d '{
    "result": {
      "success": true,
      "synced_users": [
        {"user_id": 1, "email": "user1@example.com", "verified": true},
        {"user_id": 2, "email": "user2@example.com", "verified": true}
      ]
    },
    "evidence": {
      "api_response_code": 200
    },
    "notes": "Sync completed successfully"
  }'
```

**Response (202)** - Accepted:
```json
{
  "item": {
    "id": "item-uuid",
    "state": "submitted"
  },
  "state": "submitted"
}
```

**Error (422)** - Validation failed:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "result.synced_users.0.email": [
      "The email field is required."
    ]
  }
}
```

---

### POST /items/{item}/submit-part

Submit partial result (for incremental work).

**Request**:
```bash
curl -X POST /api/agent/work/items/item-uuid/submit-part \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: my-agent-123" \
  -H "X-Idempotency-Key: submit-part-identity-1" \
  -H "Content-Type: application/json" \
  -d '{
    "part_key": "identity",
    "seq": 1,
    "payload": {
      "name": "Acme Corp",
      "domain": "acme.com",
      "confidence": 0.95
    },
    "evidence": {
      "sources": ["https://acme.com/about"]
    }
  }'
```

**Response (202)**:
```json
{
  "success": true,
  "part": {
    "id": "part-uuid",
    "part_key": "identity",
    "seq": 1,
    "status": "validated"
  },
  "item_parts_state": {
    "identity": "validated",
    "firmographics": null
  }
}
```

---

### GET /items/{item}/parts

List all parts for a work item.

**Request**:
```bash
curl -X GET "/api/agent/work/items/item-uuid/parts?part_key=identity" \
  -H "Authorization: Bearer {token}"
```

**Response (200)**:
```json
{
  "parts": [
    {
      "id": "part-uuid",
      "part_key": "identity",
      "seq": 1,
      "status": "validated",
      "payload": {...},
      "created_at": "2025-10-22T12:00:00Z"
    }
  ],
  "parts_state": {
    "identity": "validated",
    "firmographics": null
  }
}
```

---

### POST /items/{item}/finalize

Finalize work item by assembling all parts.

**Request**:
```bash
curl -X POST /api/agent/work/items/item-uuid/finalize \
  -H "Authorization: Bearer {token}" \
  -H "X-Idempotency-Key: finalize-item-uuid-1" \
  -H "Content-Type: application/json" \
  -d '{
    "mode": "strict"
  }'
```

**Parameters**:
- `mode`: "strict" (default) or "best_effort"

**Response (202)**:
```json
{
  "success": true,
  "item": {
    "id": "item-uuid",
    "state": "submitted",
    "assembled_result": {
      "identity": {...},
      "firmographics": {...}
    }
  },
  "order_state": "submitted"
}
```

---

### POST /orders/{order}/approve

Approve and apply a work order.

**Request**:
```bash
curl -X POST /api/agent/work/orders/order-uuid/approve \
  -H "Authorization: Bearer {token}" \
  -H "X-Idempotency-Key: approve-order-uuid-1"
```

**Response (200)**:
```json
{
  "order": {
    "id": "order-uuid",
    "state": "completed"
  },
  "diff": {
    "before": {"count": 0},
    "after": {"count": 3},
    "summary": "Synced 3 users"
  }
}
```

---

### POST /orders/{order}/reject

Reject a work order with errors.

**Request**:
```bash
curl -X POST /api/agent/work/orders/order-uuid/reject \
  -H "Authorization: Bearer {token}" \
  -H "X-Idempotency-Key: reject-order-uuid-1" \
  -H "Content-Type: application/json" \
  -d '{
    "errors": [
      {
        "code": "invalid_data",
        "message": "User data could not be verified",
        "field": "result.synced_users.0"
      }
    ],
    "allow_rework": true
  }'
```

**Response (200)**:
```json
{
  "order": {
    "id": "order-uuid",
    "state": "rejected"
  }
}
```

---

### POST /items/{item}/release

Release lease on a work item.

**Request**:
```bash
curl -X POST /api/agent/work/items/item-uuid/release \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: my-agent-123"
```

**Response (200)**:
```json
{
  "item": {
    "id": "item-uuid",
    "state": "queued"
  }
}
```

---

### GET /items/{item}/logs

Get event logs for a work item.

**Request**:
```bash
curl -X GET /api/agent/work/items/item-uuid/logs \
  -H "Authorization: Bearer {token}"
```

**Response (200)**:
```json
{
  "events": [
    {
      "event": "work_item.leased",
      "actor_type": "agent",
      "actor_id": "my-agent-123",
      "created_at": "2025-10-22T12:00:00Z"
    },
    {
      "event": "work_item.submitted",
      "created_at": "2025-10-22T12:05:00Z"
    }
  ]
}
```

---

## Common Error Responses

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

**Solution**: Provide valid authentication token.

### 403 Forbidden

```json
{
  "message": "This action is unauthorized."
}
```

**Solution**: Check user permissions/gates.

### 409 Conflict

```json
{
  "error": {
    "code": "lease_conflict",
    "message": "Item is already leased by another agent"
  }
}
```

**Solution**: Item unavailable, try another or wait.

### 422 Validation Error

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "result.email": ["The email field is required."]
  }
}
```

**Solution**: Fix validation errors and resubmit.

### 428 Precondition Required

```json
{
  "error": {
    "code": "idempotency_key_required",
    "message": "Idempotency key is required for this endpoint",
    "header": "X-Idempotency-Key"
  }
}
```

**Solution**: Provide idempotency key header.

---

## Agent Workflow Example

Complete workflow for processing a work order:

```bash
# 1. List available orders
curl -X GET "/api/agent/work/orders?state=queued" \
  -H "Authorization: Bearer {token}"

# 2. Checkout first item
curl -X POST /api/agent/work/orders/{order-id}/checkout \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1"

# 3. Start heartbeat loop (every 100 seconds)
# while processing...
curl -X POST /api/agent/work/items/{item-id}/heartbeat \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1"

# 4. Submit results
curl -X POST /api/agent/work/items/{item-id}/submit \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1" \
  -H "X-Idempotency-Key: submit-{item-id}-1" \
  -d '{"result": {...}}'

# 5. Check order status
curl -X GET /api/agent/work/orders/{order-id} \
  -H "Authorization: Bearer {token}"
```

---

## See Also

- [MCP Server Integration](mcp-server-integration.md) - Alternative integration method
- [Partial Submissions Guide](partial-submissions.md) - Using submit-part
- [Configuration Guide](configuration.md) - Route configuration
- Main [README.md](../../README.md) - Package overview
