# Filtering Orders

**By the end of this guide, you'll be able to:** Filter, sort, include relations, select fields, and paginate work orders using the same powerful query syntax across both HTTP API and MCP tools.

---

## Overview

The Work Manager provides a unified, expressive filtering system powered by [Spatie Laravel Query Builder](https://spatie.be/docs/laravel-query-builder). The same filtering capabilities work identically across:

- **HTTP API**: `GET {basePath}/orders` endpoint
- **MCP Tool**: `work.list` tool

This means agents can discover and filter work using consistent syntax whether they're calling the HTTP API directly or using the MCP protocol.

**Default Behavior**:
- `items` relationship is preloaded by default
- Results are sorted by `priority` descending, then `created_at` ascending
- Invalid filters/sorts/includes return **400 Bad Request**

**See Also**:
- [HTTP API Guide](http-api.md#filtering-orders) - HTTP-specific examples
- [MCP Server Integration](mcp-server-integration.md#listing-work-with-filters) - MCP-specific examples
- [Query Parameters Reference](../reference/query-parameters.md) - Complete parameter specification

---

## Quick Examples

### HTTP Request

```bash
GET /agent/work/orders?filter[state]=queued&filter[priority]=>50&sort=-created_at
```

### MCP Tool Call

```json
{
  "name": "work.list",
  "arguments": {
    "filter": {"state": "queued", "priority": ">50"},
    "sort": "-created_at"
  }
}
```

---

## Filters

### Exact Filters

Filter by exact matches on specific fields.

**Available Fields**:
- `id` - Order UUID
- `state` - Order state (e.g., `queued`, `in_progress`, `completed`)
- `type` - Order type identifier
- `requested_by_type` - Actor type (`agent`, `user`, `system`)
- `requested_by_id` - Actor ID

**HTTP Example**:
```bash
GET /agent/work/orders?filter[state]=queued&filter[type]=user.data.sync
```

**MCP Example**:
```json
{
  "filter": {
    "state": "queued",
    "type": "user.data.sync"
  }
}
```

### Multi-Value Filters

Pass comma-separated values for `IN` queries:

```bash
GET /agent/work/orders?filter[id]=uuid-1,uuid-2,uuid-3
```

### Relation Filters

Filter by related model attributes using dot notation:

```bash
# Orders that have at least one queued work item
GET /agent/work/orders?filter[items.state]=queued
```

**MCP**:
```json
{
  "filter": {
    "items.state": "queued"
  }
}
```

### Operator Filters

Use comparison operators on numeric and date fields.

**Supported Operators**: `=`, `!=`, `>`, `>=`, `<`, `<=`

**Available Fields**:
- `priority` (integer)
- `created_at` (ISO 8601 date)
- `last_transitioned_at` (ISO 8601 date)
- `applied_at` (ISO 8601 date)
- `completed_at` (ISO 8601 date)

**Syntax**:
```bash
# Priority greater than or equal to 50
filter[priority]=>= 50

# Created after 2025-01-01
filter[created_at]>=2025-01-01T00:00:00Z
```

**HTTP Example**:
```bash
GET /agent/work/orders?filter[priority]=>50&filter[created_at]>=2025-01-01
```

**MCP Example**:
```json
{
  "filter": {
    "priority": ">50",
    "created_at": ">=2025-01-01T00:00:00Z"
  }
}
```

### JSON Meta Filters

Filter by values in the `meta` JSON column using key:value notation:

```bash
# Find orders with specific meta value
GET /agent/work/orders?filter[meta]=batch_id:42
```

**MCP**:
```json
{
  "filter": {
    "meta": "batch_id:42"
  }
}
```

### Custom Filters

#### `has_available_items`

Find orders with work items available for checkout (not leased or expired leases):

```bash
GET /agent/work/orders?filter[has_available_items]=true
```

**MCP**:
```json
{
  "filter": {
    "has_available_items": true
  }
}
```

**Use Case**: Agents discovering work they can immediately start processing.

---

## Includes

Load related models and counts to reduce additional queries.

### Available Relationships

- `items` - Full work items collection (preloaded by default)
- `events` - Recent work events
- `itemsCount` - Count of items (efficient aggregate)
- `itemsExists` - Boolean if order has items

**HTTP Example**:
```bash
# Include events and items count
GET /agent/work/orders?include=events,itemsCount
```

**MCP Example**:
```json
{
  "include": "events,itemsCount"
}
```

### When to Use Counts vs Full Relations

- **Use `itemsCount`**: When you only need the number of items (e.g., dashboard displays)
- **Use `items`**: When you need item details (default behavior, already included)
- **Use `itemsExists`**: For boolean checks without loading data

---

## Fields (Sparse Fieldsets)

Select only the fields you need to reduce payload size.

**Syntax**: `fields[{model}]={field1},{field2},...`

**Available Models**:
- `work_orders` - Base order fields
- `items` - Work item fields (when included)
- `events` - Event fields (when included)

**Work Order Fields**:
```
id, type, state, priority, requested_by_type, requested_by_id,
created_at, updated_at, last_transitioned_at, applied_at, completed_at,
payload, meta
```

**HTTP Example**:
```bash
# Minimal order representation
GET /agent/work/orders?fields[work_orders]=id,type,state,priority,created_at

# Include items with selected fields
GET /agent/work/orders?include=items&fields[work_orders]=id,type&fields[items]=id,state
```

**MCP Example**:
```json
{
  "fields": {
    "work_orders": "id,type,state,priority,created_at",
    "items": "id,state"
  },
  "include": "items"
}
```

> **Note**: Fields must be declared **before** includes when constructing queries internally (Spatie requirement).

---

## Sorting

Sort results by one or more fields.

### Available Sorts

- `priority`
- `created_at`
- `last_transitioned_at`
- `applied_at`
- `completed_at`
- `items_count` (requires `include=itemsCount`)

**Syntax**:
- Ascending: `sort=created_at`
- Descending: `sort=-created_at`
- Multi-sort: `sort=-priority,created_at`

**Default Sort**: `-priority,created_at` (highest priority first, oldest first within same priority)

**HTTP Examples**:
```bash
# Oldest first
GET /agent/work/orders?sort=created_at

# Highest priority, most recent
GET /agent/work/orders?sort=-priority,-created_at

# Most items first
GET /agent/work/orders?include=itemsCount&sort=-items_count
```

**MCP Examples**:
```json
{
  "sort": "created_at"
}

{
  "sort": "-priority,-created_at"
}

{
  "include": "itemsCount",
  "sort": "-items_count"
}
```

---

## Pagination

Use JSON:API style pagination with `page[size]` and `page[number]`:

**HTTP**:
```bash
GET /agent/work/orders?page[size]=25&page[number]=2
```

**MCP**:
```json
{
  "page": {
    "size": 25,
    "number": 2
  }
}
```

**Defaults**:
- Page size: 20 (MCP), 50 (HTTP)
- Page number: 1

**Maximum Page Size**: 100 (enforced)

**Response** (HTTP):
```json
{
  "data": [...],
  "current_page": 2,
  "per_page": 25,
  "total": 150,
  "last_page": 6
}
```

**Response** (MCP):
```json
{
  "success": true,
  "orders": [...],
  "meta": {
    "current_page": 2,
    "per_page": 25,
    "total": 150,
    "last_page": 6
  }
}
```

---

## Complete Examples

### Agent Discovery: "Find Work I Can Do"

**HTTP**:
```bash
GET /agent/work/orders? \
  filter[state]=queued& \
  filter[has_available_items]=true& \
  filter[priority]=>50& \
  include=itemsCount& \
  fields[work_orders]=id,type,state,priority,created_at& \
  sort=-priority,created_at& \
  page[size]=20
```

**MCP**:
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
    "page": {"size": 20}
  }
}
```

### Dashboard: Recent Submitted Orders

**HTTP**:
```bash
GET /agent/work/orders? \
  filter[state]=submitted& \
  filter[created_at]>=2025-01-15& \
  include=itemsCount,events& \
  fields[work_orders]=id,type,state,created_at,last_transitioned_at& \
  sort=-created_at& \
  page[size]=50
```

**MCP**:
```json
{
  "filter": {
    "state": "submitted",
    "created_at": ">=2025-01-15T00:00:00Z"
  },
  "include": "itemsCount,events",
  "fields": {
    "work_orders": "id,type,state,created_at,last_transitioned_at"
  },
  "sort": "-created_at",
  "page": {"size": 50}
}
```

### Specific Batch Tracking

**HTTP**:
```bash
GET /agent/work/orders? \
  filter[type]=database.record.insert& \
  filter[meta]=batch_id:202501& \
  filter[state]=completed& \
  include=itemsCount& \
  sort=-completed_at
```

**MCP**:
```json
{
  "filter": {
    "type": "database.record.insert",
    "meta": "batch_id:202501",
    "state": "completed"
  },
  "include": "itemsCount",
  "sort": "-completed_at"
}
```

---

## Error Handling

### Invalid Filter

**Request**:
```bash
GET /agent/work/orders?filter[invalid_field]=value
```

**Response** (HTTP 400):
```json
{
  "message": "The filter 'invalid_field' is not allowed.",
  "errors": {
    "filter": ["invalid_field is not an allowed filter"]
  }
}
```

**MCP Response**:
```json
{
  "success": false,
  "error": "The filter 'invalid_field' is not allowed.",
  "code": "invalid_filter"
}
```

### Invalid Sort

**Request**:
```bash
GET /agent/work/orders?sort=invalid_field
```

**Response** (HTTP 400):
```json
{
  "message": "The sort 'invalid_field' is not allowed."
}
```

### Invalid Include

**Request**:
```bash
GET /agent/work/orders?include=invalid_relation
```

**Response** (HTTP 400):
```json
{
  "message": "The include 'invalid_relation' is not allowed."
}
```

**See**: [Common Errors](../troubleshooting/common-errors.md#filtering-and-query-errors) for more examples and solutions.

---

## Performance Considerations

### Indexing

The Work Manager migrations include indexes on frequently filtered fields:
- `state`
- `type`
- `priority`
- `created_at`
- `requested_by_type`, `requested_by_id`

For heavy filtering on `last_transitioned_at`, `applied_at`, or `completed_at`, consider adding indexes:

```php
Schema::table('work_orders', function (Blueprint $table) {
    $table->index('last_transitioned_at');
});
```

### Relation Filters

Filters like `filter[items.state]=queued` use `whereHas()` which can be expensive on large datasets. Consider:
- Adding indexes on `work_items.state`
- Limiting result sets with pagination
- Caching for frequently-used queries

---

## See Also

- [Query Parameters Reference](../reference/query-parameters.md) - Complete parameter specification
- [Routes Reference](../reference/routes-reference.md#GET-/orders) - HTTP endpoint documentation
- [HTTP API Guide](http-api.md) - Full HTTP API documentation
- [MCP Server Integration](mcp-server-integration.md) - MCP tool documentation
- [Common Errors](../troubleshooting/common-errors.md#filtering-and-query-errors) - Troubleshooting
