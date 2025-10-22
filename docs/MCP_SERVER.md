# MCP Server Guide

## Overview

The Laravel Work Manager MCP (Model Context Protocol) server provides AI agents with direct access to the work order control plane through standardized MCP tools. This is the **recommended integration method** for AI IDEs and agents like Claude/Claude Code, Cursor, and other MCP-compatible clients.

## Why MCP?

- **Standardized Protocol**: MCP is a standard protocol for AI-application integration
- **Tool Discovery**: Agents automatically discover available work management tools
- **Type Safety**: Full parameter validation and schema support
- **Dual Mode**: Supports both local (stdio) and remote (HTTP) operation
- **Lightweight**: Thin wrapper around existing Work Manager functionality

## Installation

The MCP server is included with the package. No additional installation needed beyond the base package.

```bash
composer require gregpriday/laravel-work-manager
```

**Note**: This package already depends on `php-mcp/laravel`, so it's installed automatically. You typically don't need to require it separately unless you want to pin a specific MCP version in your application.

## Running the Server

### Local Mode (STDIO) - Recommended for AI IDEs

Perfect for Cursor, Claude Code, and other local AI development tools:

```bash
php artisan work-manager:mcp --transport=stdio
```

**⚠️ Important**: When using stdio mode, avoid writing to stdout in your handlers, as it interferes with the JSON-RPC communication.

### Remote Mode (HTTP) - For Production/Remote Agents

Start a dedicated HTTP server for remote agent access:

```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

Options:
- `--host`: Host to bind to (default: `127.0.0.1`)
- `--port`: Port to listen on (default: `8090`)

HTTP Endpoints:
- `GET http://host:port/mcp/sse` - Server-sent events endpoint
- `POST http://host:port/mcp/message` - Message endpoint

## Available MCP Tools

All tools mirror the HTTP API functionality and are automatically discovered by MCP clients:

### 1. `work.propose`

Create a new work order.

**Parameters:**
- `type` (string, required) - The work order type (e.g., "user.data.sync")
- `payload` (object, required) - The payload data matching the type schema
- `meta` (object, optional) - Optional metadata
- `priority` (integer, optional) - Priority level (0-100)
- `idempotencyKey` (string, optional) - For preventing duplicate proposals

**Returns:**
```json
{
  "success": true,
  "order": {
    "id": "uuid",
    "type": "user.data.sync",
    "state": "queued",
    "priority": 0
  },
  "items_count": 3
}
```

### 2. `work.list`

List work orders with filtering.

**Parameters:**
- `state` (string, optional) - Filter by state
- `type` (string, optional) - Filter by type
- `limit` (integer, optional) - Max results (1-100, default: 20)

**Returns:**
```json
{
  "success": true,
  "count": 5,
  "orders": [...]
}
```

### 3. `work.get`

Get detailed information about a specific work order.

**Parameters:**
- `orderId` (string, required) - UUID of the work order

**Returns:**
```json
{
  "success": true,
  "order": {...},
  "items": [...],
  "recent_events": [...]
}
```

### 4. `work.checkout`

Checkout (lease) the next available work item.

**Parameters:**
- `orderId` (string, required) - UUID of the work order
- `agentId` (string, optional) - Agent identifier for tracking

**Returns:**
```json
{
  "success": true,
  "item": {
    "id": "uuid",
    "order_id": "uuid",
    "type": "user.data.sync",
    "input": {...},
    "lease_expires_at": "2025-01-22T12:00:00Z",
    "heartbeat_every_seconds": 120
  }
}
```

### 5. `work.heartbeat`

Extend the lease on a work item.

**Parameters:**
- `itemId` (string, required) - UUID of the work item
- `agentId` (string, optional) - Agent identifier

**Returns:**
```json
{
  "success": true,
  "lease_expires_at": "2025-01-22T12:02:00Z",
  "heartbeat_every_seconds": 120
}
```

### 6. `work.submit`

Submit completed work item results.

**Parameters:**
- `itemId` (string, required) - UUID of the work item
- `result` (object, required) - Result data from processing
- `evidence` (object, optional) - Verification/proof data
- `notes` (string, optional) - Notes about the work
- `agentId` (string, optional) - Agent identifier
- `idempotencyKey` (string, optional) - For preventing duplicates

**Returns:**
```json
{
  "success": true,
  "item": {
    "id": "uuid",
    "state": "submitted"
  },
  "order_state": "submitted"
}
```

Or on validation failure:
```json
{
  "success": false,
  "error": "Validation failed",
  "code": "validation_failed",
  "details": {...}
}
```

### 7. `work.approve`

Approve a work order and apply changes.

**Parameters:**
- `orderId` (string, required) - UUID of the work order
- `idempotencyKey` (string, optional) - For preventing duplicates

**Returns:**
```json
{
  "success": true,
  "order": {
    "id": "uuid",
    "state": "completed"
  },
  "diff": {
    "before": {...},
    "after": {...},
    "changes": {...}
  }
}
```

### 8. `work.reject`

Reject a work order with errors.

**Parameters:**
- `orderId` (string, required) - UUID of the work order
- `errors` (array, required) - Error details
- `allowRework` (boolean, optional) - Allow rework (default: false)
- `idempotencyKey` (string, optional) - For preventing duplicates

**Returns:**
```json
{
  "success": true,
  "order": {
    "id": "uuid",
    "state": "rejected"
  }
}
```

### 9. `work.release`

Release a lease on a work item.

**Parameters:**
- `itemId` (string, required) - UUID of the work item
- `agentId` (string, optional) - Agent identifier

**Returns:**
```json
{
  "success": true,
  "item": {
    "id": "uuid",
    "state": "queued"
  }
}
```

### 10. `work.logs`

Get event logs for a work item or order.

**Parameters:**
- `itemId` (string, optional) - UUID of work item
- `orderId` (string, optional) - UUID of work order
- `limit` (integer, optional) - Max events (1-100, default: 50)

Note: Either `itemId` or `orderId` must be provided.

**Returns:**
```json
{
  "success": true,
  "count": 25,
  "events": [...]
}
```

## Example: Agent Workflow

Here's a complete example of an agent using MCP tools to process work:

```javascript
// 1. Discover available work
const orders = await mcp.call('work.list', {
  state: 'queued',
  limit: 10
});

// 2. Checkout a work item
const checkout = await mcp.call('work.checkout', {
  orderId: orders.orders[0].id,
  agentId: 'my-agent-123'
});

// 3. Process the work (agent does its job here)
const workResult = await processWork(checkout.item.input);

// 4. Maintain lease with heartbeats (if long-running)
setInterval(async () => {
  await mcp.call('work.heartbeat', {
    itemId: checkout.item.id,
    agentId: 'my-agent-123'
  });
}, 100000); // Every 100 seconds

// 5. Submit results
const submission = await mcp.call('work.submit', {
  itemId: checkout.item.id,
  result: workResult,
  evidence: { verified: true },
  agentId: 'my-agent-123',
  idempotencyKey: `submit-${checkout.item.id}-1`
});

// 6. Get order status
const order = await mcp.call('work.get', {
  orderId: checkout.item.order_id
});

// 7. View logs
const logs = await mcp.call('work.logs', {
  itemId: checkout.item.id,
  limit: 20
});
```

## Cursor IDE Integration

To use with Cursor IDE, add to your `.cursorrules` or workspace settings:

```json
{
  "mcp": {
    "servers": {
      "work-manager": {
        "command": "php",
        "args": ["artisan", "work-manager:mcp", "--transport=stdio"],
        "cwd": "/path/to/your/laravel/app"
      }
    }
  }
}
```

## Claude Desktop Integration

Add to your Claude Desktop config (`~/Library/Application Support/Claude/claude_desktop_config.json` on Mac):

```json
{
  "mcpServers": {
    "work-manager": {
      "command": "php",
      "args": ["/path/to/your/app/artisan", "work-manager:mcp", "--transport=stdio"],
      "env": {
        "APP_ENV": "local"
      }
    }
  }
}
```

## Production Deployment (HTTP Mode)

For production deployments, run the HTTP server under a process manager.

### Using Supervisor

Create `/etc/supervisor/conf.d/work-manager-mcp.conf`:

```ini
[program:work-manager-mcp]
command=/usr/bin/php /var/www/app/artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/mcp-server.log
```

Then:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start work-manager-mcp
```

### Using systemd

Create `/etc/systemd/system/work-manager-mcp.service`:

```ini
[Unit]
Description=Work Manager MCP Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php /var/www/app/artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then:
```bash
sudo systemctl daemon-reload
sudo systemctl enable work-manager-mcp
sudo systemctl start work-manager-mcp
```

### Nginx Proxy (Optional)

If you want to expose the HTTP server through Nginx with SSL:

```nginx
server {
    listen 443 ssl http2;
    server_name mcp.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Timeouts for long-running requests
        proxy_connect_timeout 3600;
        proxy_send_timeout 3600;
        proxy_read_timeout 3600;
    }
}
```

## Security Considerations

1. **Authentication**: The MCP server uses Laravel's authentication system. Ensure agents are properly authenticated.

2. **Authorization**: Work order policies are enforced. Configure in `config/work-manager.php`.

3. **STDIO Mode**: Only use stdio mode locally or in trusted environments, as it runs with the same permissions as the PHP process.

4. **HTTP Mode**:
   - Bind to `127.0.0.1` for local-only access
   - Use SSL/TLS in production (via Nginx proxy)
   - Implement rate limiting
   - Use firewall rules to restrict access

5. **Idempotency**: Always use idempotency keys for `propose`, `submit`, `approve`, and `reject` operations.

## Troubleshooting

### STDIO Mode Issues

**Problem**: No output when starting server
- **Solution**: Check that you're not writing to stdout in your handlers

**Problem**: JSON parse errors
- **Solution**: Ensure no debug output (dd(), dump(), echo) in handlers

### HTTP Mode Issues

**Problem**: Connection refused
- **Solution**: Check firewall rules and binding address

**Problem**: Timeouts
- **Solution**: Increase proxy timeouts in Nginx/Apache

### General Issues

**Problem**: Tools not discovered
- **Solution**: Run `php artisan mcp:discover` to refresh tool cache

**Problem**: Authentication failures
- **Solution**: Check auth guard configuration in `config/work-manager.php`

## Performance

- **STDIO Mode**: Minimal overhead, direct process communication
- **HTTP Mode**: ~5-10ms additional latency per request
- **Concurrent Requests**: HTTP mode supports multiple concurrent clients
- **Tool Calls**: Each tool call is a single database transaction

## Comparison: HTTP API vs MCP

| Feature | HTTP API | MCP Server |
|---------|----------|------------|
| **Use Case** | Custom integrations | AI agents/IDEs |
| **Protocol** | REST/JSON | MCP/JSON-RPC |
| **Discovery** | Manual (docs) | Automatic |
| **Types** | OpenAPI (optional) | Built-in schemas |
| **Auth** | Bearer tokens | Laravel auth |
| **Transport** | HTTP only | STDIO or HTTP |
| **Best For** | Web apps, services | AI assistants |

**Recommendation**: Use MCP for AI agents, HTTP API for custom web integrations.

## Next Steps

1. **Start the server**: `php artisan work-manager:mcp`
2. **Connect your AI IDE**: Configure Cursor/Claude Desktop
3. **Define work types**: See `examples/` directory
4. **Monitor usage**: Check `storage/logs` for activity
5. **Production deploy**: Use supervisor/systemd for HTTP mode

For more information, see the main [README.md](README.md) and [ARCHITECTURE.md](ARCHITECTURE.md).
