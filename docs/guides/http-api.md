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

**Default**: `/api/ai/work/*` (Laravel auto-prefixes with `/api`)

> **💡 Customizing the Base Path**
>
> The examples in this guide use `/api/ai/work` as the base path. To customize this for your application, use:
>
> ```php
> // In routes/api.php or AppServiceProvider
> WorkManager::routes(basePath: 'agent/work', middleware: ['api', 'auth:sanctum']);
> // Routes will be at /api/ai/work/*
> ```
>
> Or set in `config/work-manager.php`:
> ```php
> 'routes' => [
>     'enabled' => true,
>     'base_path' => 'agent/work',  // Your custom path
> ],
> ```

---

## Authentication

All endpoints require authentication by default.

### Using Sanctum (Default)

#### Creating Agent Tokens

In your application, create Personal Access Tokens for agents:

```php
use App\Models\User;

// Create a dedicated agent user
$agent = User::create([
    'name' => 'Research Agent',
    'email' => 'research-agent@example.com',
    'password' => bcrypt(Str::random(32)), // Random, not used
]);

// Issue token with specific abilities
$token = $agent->createToken('research-agent', [
    'work:propose',
    'work:checkout',
    'work:submit',
    'work:heartbeat',
])->plainTextToken;

// Store this token securely - it won't be accessible again
```

#### Recommended Abilities

Map abilities to Work Manager operations:

```php
// Read-only access
['work:view']

// Agent operations (most common)
['work:propose', 'work:checkout', 'work:submit', 'work:heartbeat']

// Admin operations
['work:approve', 'work:reject']

// Full access
['work:*']
```

#### Using Tokens in Requests

```bash
# 1. Obtain token (via your application's token issuance endpoint)
curl -X POST https://yourapp.com/api/tokens/create \
  -H "Content-Type: application/json" \
  -d '{"agent_id":"research-agent"}'

# Response: {"token": "1|abc123..."}

# 2. Use token in all requests
curl -X GET https://yourapp.com/api/ai/work/orders \
  -H "Authorization: Bearer 1|abc123..."
```

#### Checking Abilities in Policies

Define gates that check these abilities:

```php
// app/Providers/AuthServiceProvider.php
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;

Gate::define('work.propose', function ($user) {
    return $user->tokenCan('work:propose') || $user->tokenCan('work:*');
});

Gate::define('work.approve', function ($user, WorkOrder $order) {
    return $user->tokenCan('work:approve') || $user->isAdmin();
});
```

### Custom Guards

Configure in `config/work-manager.php`:

```php
'routes' => [
    'guard' => 'api',  // Use 'api' guard instead of 'sanctum'
],
```

---

## Idempotency

Work Manager provides comprehensive idempotency guarantees for safe retries and concurrent operations.

### How Idempotency Works

**Idempotency Key**: A unique string provided in the `X-Idempotency-Key` header (configurable).

**Key Features**:
- Same key + same request → returns cached response (no duplicate processing)
- Same key + different payload → returns `409 Conflict` with `idempotency_mismatch`
- Keys are scoped per endpoint and user
- Keys are stored with SHA-256 hash for security

### Required Endpoints

By default, idempotency keys are required for:
- `POST /propose` - Creating work orders
- `POST /items/{item}/submit` - Submitting results
- `POST /items/{item}/submit-part` - Partial submissions
- `POST /items/{item}/finalize` - Finalizing assembled results
- `POST /orders/{order}/approve` - Approving orders
- `POST /orders/{order}/reject` - Rejecting orders

Configure in `config/work-manager.php`:

```php
'idempotency' => [
    'header' => 'X-Idempotency-Key', // Header name
    'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
],
```

### Key Retention and TTL

**Storage**: Keys are stored in the `work_idempotency_keys` table.

**Retention**: Keys are retained for **24 hours** by default after first use.

**Best Practices**:
- Generate unique keys per operation (e.g., `propose-{uuid}`, `submit-{item-id}-{timestamp}`)
- Reuse the same key for retries of the same operation
- Don't reuse keys across different operations
- Clean up old keys periodically (automated by `work-manager:maintain`)

### Key Scope and Uniqueness

Keys are scoped by:
1. **User/Agent** - Different users can use the same key
2. **Endpoint** - Same key can be used on different endpoints
3. **Payload hash** - Different payloads with same key → conflict

**Example**:
```bash
# First request - processed
curl -X POST /api/ai/work/propose \
  -H "X-Idempotency-Key: propose-123" \
  -d '{"type":"user.sync","payload":{...}}'
# Response: 201 Created

# Retry with same key and payload - cached response returned
curl -X POST /api/ai/work/propose \
  -H "X-Idempotency-Key: propose-123" \
  -d '{"type":"user.sync","payload":{...}}'
# Response: 201 Created (from cache)

# Same key, different payload - conflict
curl -X POST /api/ai/work/propose \
  -H "X-Idempotency-Key: propose-123" \
  -d '{"type":"different.type","payload":{...}}'
# Response: 409 Conflict (idempotency_mismatch)
```

### Generating Good Idempotency Keys

**Recommended patterns**:

```bash
# For propose operations
X-Idempotency-Key: propose-{uuid-v4}
X-Idempotency-Key: propose-{timestamp}-{agent-id}

# For submit operations
X-Idempotency-Key: submit-{item-id}-{attempt-number}
X-Idempotency-Key: submit-{item-id}-{timestamp}

# For partial submissions
X-Idempotency-Key: submit-part-{item-id}-{part-number}

# For approve/reject
X-Idempotency-Key: approve-{order-id}-{reviewer-id}
```

**Avoid**:
- Sequential numbers (not globally unique)
- Hardcoded strings (enables accidental reuse)
- Keys based solely on order/item ID (not unique per operation)

### Error Responses

#### 428 Precondition Required

```json
{
  "error": {
    "code": "idempotency_key_required",
    "message": "Idempotency key is required for this endpoint",
    "header": "X-Idempotency-Key"
  }
}
```

**Solution**: Add `X-Idempotency-Key` header to request.

#### 409 Conflict (Idempotency Mismatch)

```json
{
  "error": {
    "code": "idempotency_mismatch",
    "message": "Request with this idempotency key has different payload",
    "original_fingerprint": "abc123...",
    "current_fingerprint": "def456..."
  }
}
```

**Solution**: Use a new idempotency key or use the exact same payload.

### Disabling Idempotency

To disable for specific endpoints:

```php
'idempotency' => [
    'enforce_on' => [], // Disable all enforcement
],
```

**Not recommended for production** - idempotency prevents duplicate operations.

---

## Endpoints Reference

### POST /propose

Create a new work order.

**Request**:
```bash
curl -X POST /api/ai/work/propose \
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
curl -X GET "/api/ai/work/orders?state=queued&type=user.data.sync&limit=20" \
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
curl -X GET /api/ai/work/orders/9a7c8f2e-5b4d-4e3a-8c9d-1a2b3c4d5e6f \
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
curl -X POST /api/ai/work/orders/9a7c8f2e.../checkout \
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
curl -X POST /api/ai/work/items/item-uuid/heartbeat \
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
curl -X POST /api/ai/work/items/item-uuid/submit \
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
curl -X POST /api/ai/work/items/item-uuid/submit-part \
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
curl -X GET "/api/ai/work/items/item-uuid/parts?part_key=identity" \
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
curl -X POST /api/ai/work/items/item-uuid/finalize \
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
curl -X POST /api/ai/work/orders/order-uuid/approve \
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
curl -X POST /api/ai/work/orders/order-uuid/reject \
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
curl -X POST /api/ai/work/items/item-uuid/release \
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
curl -X GET /api/ai/work/items/item-uuid/logs \
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

### 429 Too Many Requests

```json
{
  "message": "Too many requests"
}
```

**Solution**: Implement exponential backoff and respect rate limits.

---

## Client Retry Patterns

Work Manager is designed for reliable agent operations with comprehensive retry support.

### When to Retry

**Always retry** (use same idempotency key):
- Network timeouts or connection errors
- 500 Internal Server Error
- 502 Bad Gateway, 503 Service Unavailable, 504 Gateway Timeout
- 429 Too Many Requests

**Never retry** (fix and use new idempotency key):
- 401 Unauthorized (authentication issue)
- 403 Forbidden (permission issue)
- 422 Validation Error (fix payload)
- 409 Conflict with `idempotency_mismatch` (fix payload or use new key)

**Conditional retry**:
- 409 Conflict with `no_items_available` → Retry after delay or try different order
- 409 Conflict with `lease_conflict` → Retry with exponential backoff

### Retry Strategies by Operation

#### Checkout Operations

```python
# Exponential backoff for lease conflicts
def checkout_with_retry(order_id, agent_id, max_attempts=5):
    base_delay = 0.25  # 250ms
    max_delay = 30     # 30 seconds

    for attempt in range(max_attempts):
        try:
            response = http_client.post(
                f"/api/ai/work/orders/{order_id}/checkout",
                headers={"X-Agent-ID": agent_id}
            )
            return response.json()
        except Conflict as e:
            if e.code == "no_items_available":
                # No items left, don't retry
                raise
            elif e.code == "lease_conflict":
                # Another agent has it, retry with backoff
                delay = min(base_delay * (2 ** attempt), max_delay)
                time.sleep(delay + random.uniform(0, delay * 0.1))
            else:
                raise
    raise MaxRetriesExceeded()
```

#### Submit Operations

```python
# Retry with same idempotency key for network errors
def submit_with_retry(item_id, result, idempotency_key, max_attempts=3):
    for attempt in range(max_attempts):
        try:
            response = http_client.post(
                f"/api/ai/work/items/{item_id}/submit",
                headers={
                    "X-Idempotency-Key": idempotency_key,  # Same key for retries
                    "X-Agent-ID": agent_id
                },
                json={"result": result}
            )
            return response.json()
        except (NetworkError, Timeout, ServerError) as e:
            if attempt == max_attempts - 1:
                raise
            time.sleep(2 ** attempt)  # 1s, 2s, 4s
        except ValidationError as e:
            # Don't retry validation errors - fix payload
            raise
```

#### Partial Submissions

```python
# Resume partial submissions from last successful part
def submit_parts_with_resume(item_id, parts):
    submitted_parts = set()

    # Check which parts already submitted
    response = http_client.get(f"/api/ai/work/items/{item_id}/parts")
    for part in response.json()["parts"]:
        submitted_parts.add(part["part_number"])

    # Submit remaining parts
    for part_num, part_data in enumerate(parts):
        if part_num in submitted_parts:
            continue  # Already submitted

        idempotency_key = f"submit-part-{item_id}-{part_num}"

        try:
            http_client.post(
                f"/api/ai/work/items/{item_id}/submit-part",
                headers={"X-Idempotency-Key": idempotency_key},
                json={
                    "part_number": part_num,
                    "payload": part_data
                }
            )
        except (NetworkError, Timeout):
            # Retry with same key
            time.sleep(1)
            http_client.post(...)  # Retry logic
```

### Heartbeat Reliability

```python
# Heartbeat with jitter to prevent thundering herd
def maintain_heartbeat(item_id, heartbeat_interval, stop_event):
    base_interval = heartbeat_interval / 2  # Send at half the recommended interval

    while not stop_event.is_set():
        # Add jitter ±10%
        jitter = base_interval * random.uniform(-0.1, 0.1)
        time.sleep(base_interval + jitter)

        try:
            http_client.post(
                f"/api/ai/work/items/{item_id}/heartbeat",
                timeout=5
            )
        except Exception as e:
            # Log but continue - lease might still be valid
            logging.warning(f"Heartbeat failed: {e}")
            # If critical, release and re-checkout
```

### Rate Limit Handling

```python
# Exponential backoff with jitter for 429 responses
def request_with_rate_limit_retry(method, url, **kwargs):
    max_retries = 5
    base_delay = 1

    for attempt in range(max_retries):
        response = http_client.request(method, url, **kwargs)

        if response.status_code == 429:
            # Check for Retry-After header
            retry_after = response.headers.get('Retry-After')
            if retry_after:
                delay = int(retry_after)
            else:
                # Exponential backoff with jitter
                delay = base_delay * (2 ** attempt)
                delay += random.uniform(0, delay * 0.1)

            time.sleep(min(delay, 60))  # Cap at 60 seconds
            continue

        return response

    raise RateLimitExceeded()
```

### Best Practices

1. **Always use idempotency keys** - Same key for retries, new key for new operations
2. **Implement exponential backoff** - Start with short delays, increase exponentially
3. **Add jitter** - Prevent thundering herd when multiple agents retry
4. **Set timeouts** - Don't wait forever (recommended: 60s for most operations, 300s for approve/apply)
5. **Monitor retry rates** - High retry rates indicate system issues
6. **Release on fatal errors** - If you can't process an item, release it for other agents
7. **Log all retries** - Include attempt number, error type, and delay for debugging

### Example: Complete Agent Client

```python
class WorkManagerClient:
    def __init__(self, base_url, token, agent_id):
        self.base_url = base_url
        self.token = token
        self.agent_id = agent_id

    def checkout_and_process(self, order_id):
        # Checkout with retry
        item = self.checkout_with_retry(order_id)

        try:
            # Start heartbeat thread
            heartbeat_thread = self.start_heartbeat(
                item['id'],
                item['heartbeat_every_seconds']
            )

            # Process work
            result = self.process_item(item['input'])

            # Submit with retry (same idempotency key)
            self.submit_with_retry(
                item['id'],
                result,
                idempotency_key=f"submit-{item['id']}-{time.time()}"
            )

        except Exception as e:
            # Release lease on error
            self.release(item['id'])
            raise
        finally:
            # Stop heartbeat
            heartbeat_thread.stop()
```

---

## Agent Workflow Example

Complete workflow for processing a work order:

```bash
# 1. List available orders
curl -X GET "/api/ai/work/orders?state=queued" \
  -H "Authorization: Bearer {token}"

# 2. Checkout first item
curl -X POST /api/ai/work/orders/{order-id}/checkout \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1"

# 3. Start heartbeat loop (every 100 seconds)
# while processing...
curl -X POST /api/ai/work/items/{item-id}/heartbeat \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1"

# 4. Submit results
curl -X POST /api/ai/work/items/{item-id}/submit \
  -H "Authorization: Bearer {token}" \
  -H "X-Agent-ID: agent-1" \
  -H "X-Idempotency-Key: submit-{item-id}-1" \
  -d '{"result": {...}}'

# 5. Check order status
curl -X GET /api/ai/work/orders/{order-id} \
  -H "Authorization: Bearer {token}"
```

---

## See Also

- [MCP Server Integration](mcp-server-integration.md) - Alternative integration method
- [Partial Submissions Guide](partial-submissions.md) - Using submit-part
- [Configuration Guide](configuration.md) - Route configuration
- Main [README.md](../../README.md) - Package overview
