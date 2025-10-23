# Query Parameters Reference

Complete specification of query parameters supported by Work Manager filtering system (powered by Spatie Laravel Query Builder v6).

Applies to:
- **HTTP**: `GET {basePath}/orders`
- **MCP**: `work.list` tool

---

## Table of Contents

- [Filters](#filters)
- [Includes](#includes)
- [Fields](#fields)
- [Sorts](#sorts)
- [Pagination](#pagination)
- [Request Methods](#request-methods)
- [Error Responses](#error-responses)

---

## Filters

### Exact Filters

Match exact values. Support comma-separated lists for `IN` queries.

| Parameter                    | Type                      | Values/Example                    | Notes                         |
|------------------------------|---------------------------|-----------------------------------|-------------------------------|
| `filter[id]`                 | UUID or comma-list        | `019a...,019b...`                 | Multi-value for OR            |
| `filter[state]`              | enum                      | `queued`, `in_progress`, ...      | Order state                   |
| `filter[type]`               | string                    | `user.data.sync`                  | Order type identifier         |
| `filter[requested_by_type]`  | enum                      | `agent`, `user`, `system`         | Actor type                    |
| `filter[requested_by_id]`    | string                    | User/agent ID                     | Actor identifier              |

**HTTP Syntax**: `?filter[{field}]={value}`

**MCP Syntax**:
```json
{
  "filter": {
    "{field}": "{value}"
  }
}
```

### Relation Filters

Filter by related model attributes using dot notation. Uses `whereHas()` internally.

| Parameter               | Type     | Example          | Notes                                        |
|-------------------------|----------|------------------|----------------------------------------------|
| `filter[items.state]`   | string   | `queued`         | Orders with at least one item in this state  |

**HTTP**: `?filter[items.state]=queued`

**MCP**:
```json
{
  "filter": {
    "items.state": "queued"
  }
}
```

### Operator Filters (Dynamic)

Numeric and date comparisons using operator prefixes.

**Supported Operators**: `=`, `!=`, `>`, `>=`, `<`, `<=`

| Parameter                       | Type             | Example                      | Notes                          |
|---------------------------------|------------------|------------------------------|--------------------------------|
| `filter[priority]`              | integer          | `>50`, `>=0`, `<=100`        | Compare priority values        |
| `filter[created_at]`            | ISO 8601 date    | `>=2025-01-01T00:00:00Z`     | Date/time comparison           |
| `filter[last_transitioned_at]`  | ISO 8601 date    | `>2025-01-15`                | Last state change              |
| `filter[applied_at]`            | ISO 8601 date    | `<2025-01-20`                | When order was applied         |
| `filter[completed_at]`          | ISO 8601 date    | `!=null`                     | Completion timestamp           |

**Operator Syntax**:
- Prefix: `filter[priority]=>=50`
- Space-separated: `filter[priority]=>= 50` (URL-encoded: `%3E%3D`)

**HTTP**: `?filter[priority]=>50&filter[created_at]>=2025-01-01`

**MCP**:
```json
{
  "filter": {
    "priority": ">50",
    "created_at": ">=2025-01-01T00:00:00Z"
  }
}
```

**Date Format**: ISO 8601 recommended (`YYYY-MM-DDTHH:MM:SSZ`). Simplified dates (`YYYY-MM-DD`) also supported.

### Callback Filters

Custom filter logic implemented via callbacks.

| Parameter                     | Type            | Values        | Behavior                                                   |
|-------------------------------|-----------------|---------------|------------------------------------------------------------|
| `filter[meta]`                | string or JSON  | `key:value`   | Matches JSON contains on meta column                       |
| `filter[has_available_items]` | boolean         | `true`/`1`    | Orders with queued items (not leased or expired leases)    |

**Meta Filter**:

Supports two syntaxes:

1. **Key:value string**: `filter[meta]=batch_id:42`
2. **JSON object**: `filter[meta]={"batch_id":42}` (HTTP body or MCP)

**Has Available Items**:

Returns orders with items in `queued` state where:
- `lease_expires_at` is NULL, OR
- `lease_expires_at` is in the past

**HTTP**: `?filter[has_available_items]=true&filter[meta]=batch_id:42`

**MCP**:
```json
{
  "filter": {
    "has_available_items": true,
    "meta": "batch_id:42"
  }
}
```

---

## Includes

Load related models or aggregates.

| Parameter       | Type             | Loads                              | Notes                                    |
|-----------------|------------------|------------------------------------|------------------------------------------|
| `items`         | relationship     | Full items collection              | Preloaded by default                     |
| `events`        | relationship     | Work events (max 20)               | Recent events only                       |
| `itemsCount`    | aggregate count  | Number of items                    | Efficient `COUNT(*)` query               |
| `itemsExists`   | aggregate exists | Boolean if items exist             | Efficient `EXISTS` check                 |

**Syntax**: Comma-separated list.

**HTTP**: `?include=events,itemsCount,itemsExists`

**MCP**:
```json
{
  "include": "events,itemsCount,itemsExists"
}
```

**Default**: `items` relationship is always preloaded (for backward compatibility). Explicitly including `itemsCount` adds the count attribute.

---

## Fields

Sparse fieldsets - select only needed columns to reduce payload size.

**Syntax**: `fields[{model}]={field1},{field2},...`

### Work Orders

| Model         | Available Fields                                                                                               |
|---------------|----------------------------------------------------------------------------------------------------------------|
| `work_orders` | `id`, `type`, `state`, `priority`, `requested_by_type`, `requested_by_id`, `created_at`, `updated_at`, `last_transitioned_at`, `applied_at`, `completed_at`, `payload`, `meta` |

### Related Models (when included)

| Model    | Available Fields                                                                                          |
|----------|-----------------------------------------------------------------------------------------------------------|
| `items`  | `id`, `type`, `state`, `input`, `result`, `lease_expires_at`, `leased_by_agent_id`, `attempts`, `max_attempts` |
| `events` | `id`, `event`, `payload`, `created_at`, `actor_type`, `actor_id`                                          |

**HTTP**: `?fields[work_orders]=id,type,state&fields[items]=id,state&include=items`

**MCP**:
```json
{
  "fields": {
    "work_orders": "id,type,state,priority",
    "items": "id,state"
  },
  "include": "items"
}
```

**Important**: Fields must be specified **before** includes in query construction (Spatie requirement). Work Manager handles this internally.

---

## Sorts

Order results by one or more fields.

| Parameter          | Direction                 | Notes                                      |
|--------------------|---------------------------|--------------------------------------------|
| `priority`         | Ascending or descending   | Order priority value                       |
| `created_at`       | Ascending or descending   | Creation timestamp                         |
| `last_transitioned_at` | Ascending or descending | Last state change                          |
| `applied_at`       | Ascending or descending   | When order was applied                     |
| `completed_at`     | Ascending or descending   | Completion timestamp                       |
| `items_count`      | Ascending or descending   | Count of items (requires `include=itemsCount`) |

**Syntax**:
- Ascending: `sort=created_at`
- Descending: `sort=-created_at` (prefix with `-`)
- Multi-sort: `sort=-priority,created_at`

**Default Sort**: `-priority,created_at` (highest priority first, oldest first within same priority)

**HTTP**: `?sort=-priority,created_at`

**MCP**:
```json
{
  "sort": "-priority,created_at"
}
```

---

## Pagination

Use JSON:API style pagination:

| Parameter      | Type    | Default (HTTP) | Default (MCP) | Max | Description              |
|----------------|---------|----------------|---------------|-----|--------------------------|
| `page[size]`   | integer | 50             | 20            | 100 | Results per page         |
| `page[number]` | integer | 1              | 1             | -   | Page number (1-indexed)  |

**HTTP**: `?page[size]=25&page[number]=2`

**MCP**:
```json
{
  "page": {
    "size": 25,
    "number": 2
  }
}
```

---

## Request Methods

### HTTP

Supports parameters via:
- **Query String**: `GET /orders?filter[state]=queued`
- **Request Body**: JSON body with filter/include/fields/sort/page keys

### MCP

All parameters passed in tool arguments object:
```json
{
  "name": "work.list",
  "arguments": {
    "filter": {...},
    "include": "...",
    "fields": {...},
    "sort": "...",
    "page": {...}
  }
}
```

---

## Error Responses

### Invalid Filter

**HTTP** (400):
```json
{
  "message": "The filter 'unknown_field' is not allowed.",
  "errors": {
    "filter": ["unknown_field is not an allowed filter"]
  }
}
```

**MCP**:
```json
{
  "success": false,
  "error": "The filter 'unknown_field' is not allowed.",
  "code": "invalid_filter"
}
```

### Invalid Sort

**HTTP** (400):
```json
{
  "message": "The sort 'unknown_field' is not allowed."
}
```

**MCP**:
```json
{
  "success": false,
  "error": "The sort 'unknown_field' is not allowed.",
  "code": "invalid_sort"
}
```

### Invalid Include

**HTTP** (400):
```json
{
  "message": "The include 'unknown_relation' is not allowed."
}
```

**MCP**:
```json
{
  "success": false,
  "error": "The include 'unknown_relation' is not allowed.",
  "code": "invalid_include"
}
```

### Invalid Fields

**HTTP** (400):
```json
{
  "message": "Requested field(s) 'unknown_field' are not allowed."
}
```

---

## Implementation Notes

- **Query Builder**: Powered by [Spatie Laravel Query Builder v6](https://spatie.be/docs/laravel-query-builder)
- **Shared Configuration**: Both HTTP and MCP use identical filter/sort/include/field definitions from `WorkOrderQuery` class
- **Validation**: All parameters validated against allowed lists; unknown parameters return 400/error
- **Performance**: Relation filters use `whereHas()` which may require indexes on large datasets
- **Extensibility**: Filter/sort/include lists can be extended in custom implementations

---

## See Also

- [Filtering Orders Guide](../guides/filtering-orders.md) - Task-oriented guide with examples
- [Routes Reference](routes-reference.md#GET-/orders) - HTTP endpoint documentation
- [HTTP API Guide](../guides/http-api.md) - Complete HTTP API documentation
- [MCP Server Integration](../guides/mcp-server-integration.md) - MCP tools documentation
