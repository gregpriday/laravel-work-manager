# Orders Filtering Examples

Copy-paste examples for common filtering scenarios using both HTTP API and MCP tools.

---

## Scenario A: Agent Discovery - "Find Work I Can Do"

An AI agent wants to find high-priority work orders that have available work items ready for checkout.

### Requirements
- Orders in `queued` state
- Has items in `queued` state (not leased)
- Priority >= 50
- Need items count for decision-making
- Minimal payload size
- Sort by priority, then oldest first

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[state]=queued& \
  filter[has_available_items]=true& \
  filter[priority]=>50& \
  include=itemsCount& \
  fields[work_orders]=id,type,state,priority,created_at& \
  sort=-priority,created_at& \
  page[size]=20" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "state": "queued",
      "has_available_items": true,
      "priority": ">50"
    },
    "include": "itemsCount",
    "fields": {
      "work_orders": "id,type,state,priority,created_at"
    },
    "sort": "-priority,created_at",
    "page": {
      "size": 20
    }
  }
}
```

### Expected Response (HTTP)

```json
{
  "data": [
    {
      "id": "019a0f0b-a6e8-71b8-9319-90d028bf2deb",
      "type": "user.data.sync",
      "state": "queued",
      "priority": 90,
      "created_at": "2025-01-15T08:30:00Z",
      "items_count": 5
    },
    {
      "id": "019a0f0b-b2c4-7244-8f9d-1234567890ab",
      "type": "database.record.insert",
      "state": "queued",
      "priority": 75,
      "created_at": "2025-01-15T09:00:00Z",
      "items_count": 3
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "total": 8,
  "last_page": 1
}
```

### Expected Response (MCP)

```json
{
  "success": true,
  "count": 2,
  "orders": [
    {
      "id": "019a0f0b-a6e8-71b8-9319-90d028bf2deb",
      "type": "user.data.sync",
      "state": "queued",
      "priority": 90,
      "items_count": 5,
      "created_at": "2025-01-15T08:30:00Z",
      "last_transitioned_at": "2025-01-15T08:30:00Z"
    },
    {
      "id": "019a0f0b-b2c4-7244-8f9d-1234567890ab",
      "type": "database.record.insert",
      "state": "queued",
      "priority": 75,
      "items_count": 3,
      "created_at": "2025-01-15T09:00:00Z",
      "last_transitioned_at": "2025-01-15T09:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 8,
    "last_page": 1
  }
}
```

---

## Scenario B: Dashboard - Recent Submitted Orders

A dashboard needs to display recently submitted work orders awaiting approval.

### Requirements
- Orders in `submitted` state
- Created in the last week
- Include items count and recent events
- Show relevant timestamps
- Sort by most recent first

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[state]=submitted& \
  filter[created_at]>=2025-01-15T00:00:00Z& \
  include=itemsCount,events& \
  fields[work_orders]=id,type,state,created_at,last_transitioned_at& \
  fields[events]=id,event,created_at& \
  sort=-created_at& \
  page[size]=50" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "state": "submitted",
      "created_at": ">=2025-01-15T00:00:00Z"
    },
    "include": "itemsCount,events",
    "fields": {
      "work_orders": "id,type,state,created_at,last_transitioned_at",
      "events": "id,event,created_at"
    },
    "sort": "-created_at",
    "page": {
      "size": 50
    }
  }
}
```

### Expected Response

```json
{
  "data": [
    {
      "id": "019a0f10-1234-5678-9abc-def012345678",
      "type": "user.data.sync",
      "state": "submitted",
      "created_at": "2025-01-20T14:30:00Z",
      "last_transitioned_at": "2025-01-20T15:00:00Z",
      "items_count": 10,
      "events": [
        {
          "id": "019a0f10-abcd-ef01-2345-6789abcdef01",
          "event": "work_order_submitted",
          "created_at": "2025-01-20T15:00:00Z"
        },
        {
          "id": "019a0f10-bcde-f012-3456-789abcdef012",
          "event": "work_item_submitted",
          "created_at": "2025-01-20T14:58:00Z"
        }
      ]
    }
  ]
}
```

---

## Scenario C: Batch Tracking - Specific Batch Completion

Track completion status of a specific batch job by meta field.

### Requirements
- Filter by order type
- Filter by batch ID in meta
- Only completed orders
- Include items count
- Sort by completion time (newest first)

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[type]=database.record.insert& \
  filter[meta]=batch_id:202501& \
  filter[state]=completed& \
  include=itemsCount& \
  fields[work_orders]=id,type,state,completed_at,meta& \
  sort=-completed_at" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "type": "database.record.insert",
      "meta": "batch_id:202501",
      "state": "completed"
    },
    "include": "itemsCount",
    "fields": {
      "work_orders": "id,type,state,completed_at,meta"
    },
    "sort": "-completed_at"
  }
}
```

### Expected Response

```json
{
  "data": [
    {
      "id": "019a0f11-9876-5432-10fe-dcba98765432",
      "type": "database.record.insert",
      "state": "completed",
      "completed_at": "2025-01-20T16:45:00Z",
      "meta": {
        "batch_id": "202501",
        "source": "import-job",
        "records_total": 1000
      },
      "items_count": 10
    },
    {
      "id": "019a0f11-8765-4321-0fed-cba987654321",
      "type": "database.record.insert",
      "state": "completed",
      "completed_at": "2025-01-20T16:30:00Z",
      "meta": {
        "batch_id": "202501",
        "source": "import-job",
        "records_total": 500
      },
      "items_count": 5
    }
  ]
}
```

---

## Scenario D: Priority Queue Monitoring

Monitor high-priority orders across all states with detailed item information.

### Requirements
- Priority >= 80
- All states (no state filter)
- Include full items data
- Include items count for quick overview
- Sort by priority descending

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[priority]=>80& \
  include=items,itemsCount& \
  fields[work_orders]=id,type,state,priority,created_at& \
  fields[items]=id,state,attempts& \
  sort=-priority& \
  page[size]=30" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "priority": ">80"
    },
    "include": "items,itemsCount",
    "fields": {
      "work_orders": "id,type,state,priority,created_at",
      "items": "id,state,attempts"
    },
    "sort": "-priority",
    "page": {
      "size": 30
    }
  }
}
```

---

## Scenario E: Failed Orders Requiring Attention

Find orders that have failed or been rejected for review.

### Requirements
- State is `rejected` or `failed`
- Created in last 24 hours
- Include events for debugging
- Full payload for analysis
- Sort by most recent first

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[state]=rejected,failed& \
  filter[created_at]>=2025-01-22T00:00:00Z& \
  include=events,itemsCount& \
  sort=-created_at& \
  page[size]=25" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "state": "rejected,failed",
      "created_at": ">=2025-01-22T00:00:00Z"
    },
    "include": "events,itemsCount",
    "sort": "-created_at",
    "page": {
      "size": 25
    }
  }
}
```

---

## Scenario F: Agent Type Analysis

Analyze orders by requesting agent type for metrics.

### Requirements
- Filter by agent type requests
- Date range for reporting period
- Minimal payload (just counts)
- Group-friendly sort

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[requested_by_type]=agent& \
  filter[created_at]>=2025-01-01& \
  filter[created_at]<2025-02-01& \
  include=itemsCount& \
  fields[work_orders]=id,type,state,requested_by_type,created_at& \
  sort=type,created_at" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "requested_by_type": "agent",
      "created_at": ">=2025-01-01,<2025-02-01"
    },
    "include": "itemsCount",
    "fields": {
      "work_orders": "id,type,state,requested_by_type,created_at"
    },
    "sort": "type,created_at"
  }
}
```

---

## Scenario G: Stuck in Progress Orders

Find orders that have been in progress for too long (potential issues).

### Requirements
- State is `in_progress`
- Last transition was more than 2 hours ago
- Include items to check lease status
- Sort by oldest transition first

### HTTP Request

```bash
curl -X GET "https://yourapp.com/api/agent/work/orders? \
  filter[state]=in_progress& \
  filter[last_transitioned_at]<2025-01-23T10:00:00Z& \
  include=items& \
  fields[work_orders]=id,type,state,last_transitioned_at& \
  fields[items]=id,state,leased_by_agent_id,lease_expires_at& \
  sort=last_transitioned_at" \
  -H "Authorization: Bearer {token}"
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "state": "in_progress",
      "last_transitioned_at": "<2025-01-23T10:00:00Z"
    },
    "include": "items",
    "fields": {
      "work_orders": "id,type,state,last_transitioned_at",
      "items": "id,state,leased_by_agent_id,lease_expires_at"
    },
    "sort": "last_transitioned_at"
  }
}
```

---

## See Also

- [Filtering Orders Guide](../guides/filtering-orders.md) - Complete filtering documentation
- [Query Parameters Reference](../reference/query-parameters.md) - Parameter specification
- [HTTP API Guide](../guides/http-api.md) - HTTP API documentation
- [MCP Server Integration](../guides/mcp-server-integration.md) - MCP tools documentation
