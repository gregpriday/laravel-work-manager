# MCP Server Integration Guide

**By the end of this guide, you'll be able to:** Set up the MCP server, integrate with AI IDEs (Cursor, Claude Desktop), deploy in production, and troubleshoot common issues.

---

## What is MCP?

**Model Context Protocol (MCP)** is a standard protocol for AI-application integration. The Work Manager MCP server exposes work order management as tools that AI agents can automatically discover and use.

**Benefits**:
- Standardized protocol for AI integration
- Automatic tool discovery
- Type-safe parameter validation
- Dual transport: stdio (local) and HTTP (remote)

---

## Quick Start

### Local Mode (for AI IDEs)

```bash
php artisan work-manager:mcp --transport=stdio
```

Use this for:
- Cursor IDE
- Claude Desktop
- Local development
- Direct process communication

### HTTP Mode (for Production)

```bash
php artisan work-manager:mcp --transport=http --host=0.0.0.0 --port=8090
```

Use this for:
- Remote agents
- Production deployments
- Multiple concurrent clients
- Behind load balancer/proxy

---

## Available MCP Tools

The server exposes 13 tools for complete work order management:

### Work Management
- `work.propose` - Create new work orders
- `work.list` - List orders with filtering
- `work.get` - Get order details
- `work.checkout` - Lease work items
- `work.heartbeat` - Maintain leases
- `work.release` - Release leases

### Submission
- `work.submit` - Submit complete results
- `work.submit_part` - Submit partial results
- `work.list_parts` - List all parts
- `work.finalize` - Finalize from parts

### Approval
- `work.approve` - Approve and apply
- `work.reject` - Reject with errors
- `work.logs` - View event history

---

## Listing Work with Filters

The `work.list` tool supports the same powerful filtering capabilities as the HTTP `GET /orders` endpoint, allowing agents to precisely discover work they can process.

### Quick Examples

**Find high-priority work**:
```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "state": "queued",
      "priority": ">50"
    },
    "sort": "-priority"
  }
}
```

**Orders with available items**:
```json
{
  "name": "work.list",
  "arguments": {
    "filter": {
      "has_available_items": true,
      "state": "queued"
    },
    "include": "itemsCount"
  }
}
```

**Minimal payload for efficiency**:
```json
{
  "name": "work.list",
  "arguments": {
    "fields": {
      "work_orders": "id,type,state,priority"
    },
    "page": {
      "size": 20
    }
  }
}
```

### Available Filters

- **Exact**: `state`, `type`, `requested_by_type`, `id`
- **Operator**: `priority` (`>50`, `>=25`), dates with ISO 8601
- **Relation**: `items.state` (orders with items in specific state)
- **JSON**: `meta` (e.g., `batch_id:42`)
- **Custom**: `has_available_items` (true/false)

### Includes & Aggregates

- `items` - Full items collection (included by default)
- `events` - Recent events
- `itemsCount` - Efficient count
- `itemsExists` - Boolean check

### Field Selection

Select only needed fields to reduce payload:
```json
{
  "fields": {
    "work_orders": "id,type,state,priority",
    "items": "id,state"
  },
  "include": "items"
}
```

### Sorting & Pagination

**Sort**: Use `sort` parameter:
- Single: `"sort": "created_at"` or `"sort": "-created_at"`
- Multi: `"sort": "-priority,created_at"`
- Default: `-priority,created_at`

**Pagination**:
```json
{
  "page": {
    "size": 25,
    "number": 2
  }
}
```

### Response Format

```json
{
  "success": true,
  "count": 2,
  "orders": [
    {
      "id": "019a...",
      "type": "user.data.sync",
      "state": "queued",
      "priority": 90,
      "items_count": 5,
      "created_at": "2025-01-15T08:30:00Z",
      "last_transitioned_at": "2025-01-15T08:30:00Z"
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

### Complete Documentation

For comprehensive filtering documentation with all parameters and examples:

- **[Filtering Orders Guide](filtering-orders.md)** - Complete guide
- **[Query Parameters Reference](../reference/query-parameters.md)** - Parameter specification
- **[Orders Filtering Examples](../examples/orders-filtering.md)** - Copy-paste scenarios

---

## Cursor IDE Integration

### Step 1: Create MCP Config

Add to `.cursorrules` or Cursor settings:

```json
{
  "mcp": {
    "servers": {
      "work-manager": {
        "command": "php",
        "args": ["artisan", "work-manager:mcp", "--transport=stdio"],
        "cwd": "/absolute/path/to/your/laravel/app"
      }
    }
  }
}
```

### Step 2: Restart Cursor

Close and reopen Cursor to load the MCP server.

### Step 3: Verify Tools

In Cursor, the AI should now have access to work.* tools. Test by asking:

```
"List all queued work orders"
```

Cursor will automatically use `work.list` tool.

### Troubleshooting Cursor

**Tools not appearing**:
- Check `.cursorrules` JSON syntax
- Verify `cwd` path is absolute and correct
- Check Laravel logs: `storage/logs/laravel.log`
- Restart Cursor completely

**Connection errors**:
- Ensure PHP is in PATH: `which php`
- Test command manually: `php artisan work-manager:mcp`
- Check no stdout/dd() in your code

---

## Claude Desktop Integration

### Step 1: Locate Config File

**Mac**: `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
**Linux**: `~/.config/Claude/claude_desktop_config.json`

### Step 2: Add Server Config

```json
{
  "mcpServers": {
    "work-manager": {
      "command": "php",
      "args": [
        "/absolute/path/to/your/app/artisan",
        "work-manager:mcp",
        "--transport=stdio"
      ],
      "env": {
        "APP_ENV": "local"
      }
    }
  }
}
```

### Step 3: Restart Claude Desktop

Completely quit and relaunch Claude Desktop.

### Step 4: Verify

In a new conversation, ask:

```
"What work management tools do you have access to?"
```

Claude should list the work.* tools.

### Troubleshooting Claude Desktop

**Server not starting**:
- Check JSON syntax in config file
- Use absolute paths for command and args
- Verify `php artisan work-manager:mcp` works manually

**Permission errors**:
- Ensure Laravel app has correct permissions
- Check `.env` file exists and is readable
- Verify database connection works

---

## HTTP Mode Setup

### Basic HTTP Server

```bash
php artisan work-manager:mcp \
  --transport=http \
  --host=127.0.0.1 \
  --port=8090
```

**Endpoints**:
- `GET http://localhost:8090/mcp/sse` - Server-sent events
- `POST http://localhost:8090/mcp/message` - Message endpoint

### HTTP Mode with Authentication

To enable Bearer token authentication for the HTTP transport, configure the following in your `.env`:

```env
# Enable MCP HTTP authentication
WORK_MANAGER_MCP_HTTP_AUTH=true

# Choose auth guard (default: sanctum)
WORK_MANAGER_MCP_AUTH_GUARD=sanctum

# Optional: Static tokens for simple setup (comma-separated)
# Useful for development or when not using Sanctum
WORK_MANAGER_MCP_STATIC_TOKENS=your-secret-token-1,your-secret-token-2
```

**Using Sanctum Tokens** (recommended for production):

```bash
# Clients must include Authorization header with valid Sanctum token
curl -H "Authorization: Bearer 1|abc123..." \
  http://localhost:8090/mcp/sse
```

**Using Static Tokens** (for development):

```bash
# Clients use configured static tokens
curl -H "Authorization: Bearer your-secret-token-1" \
  http://localhost:8090/mcp/sse
```

When auth is enabled, the server will:
- Validate all incoming requests with Bearer token
- Return 401 Unauthorized if token is missing or invalid
- Integrate with Laravel's authentication system
- Support both Sanctum and static token authentication

**CORS Configuration**:

The HTTP server includes CORS support for browser-based clients:

```env
# Enable CORS (default: true)
WORK_MANAGER_MCP_CORS=true

# Allowed origins (default: *)
WORK_MANAGER_MCP_CORS_ORIGINS=https://yourdomain.com

# Or allow all origins (development only)
WORK_MANAGER_MCP_CORS_ORIGINS=*
```

### Production with Supervisor

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
stopwaitsecs=10
```

Start the service:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start work-manager-mcp
```

### Production with systemd

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

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable work-manager-mcp
sudo systemctl start work-manager-mcp
sudo systemctl status work-manager-mcp
```

---

## Nginx Reverse Proxy

To expose MCP server with SSL:

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

        # Long timeouts for SSE
        proxy_connect_timeout 3600;
        proxy_send_timeout 3600;
        proxy_read_timeout 3600;
    }
}
```

---

## Security Considerations

### stdio Mode

**Risks**:
- Runs with same permissions as PHP process
- Can access entire filesystem
- No authentication layer

**Recommendations**:
- Only use locally or in trusted environments
- Don't expose to network
- Use dedicated user account with limited permissions

### HTTP Mode

**Risks**:
- Network accessible
- Potential for unauthorized access
- DDoS attacks

**Recommendations**:
- **ALWAYS enable authentication in production** via `WORK_MANAGER_MCP_HTTP_AUTH=true`
- Use Sanctum tokens for production, static tokens only for development
- Bind to `127.0.0.1` for local-only access
- Use SSL/TLS (via Nginx proxy)
- Implement rate limiting at proxy level
- Use firewall rules to restrict access
- Monitor for unusual activity and failed auth attempts
- Rotate static tokens regularly if used

### Authentication

**MCP HTTP Server Authentication** (ReactPHP transport):

The MCP HTTP server supports optional Bearer token authentication:

```env
# Enable auth
WORK_MANAGER_MCP_HTTP_AUTH=true

# Use Sanctum (recommended)
WORK_MANAGER_MCP_AUTH_GUARD=sanctum

# Or use static tokens (development only)
WORK_MANAGER_MCP_STATIC_TOKENS=token1,token2
```

When enabled, clients must include `Authorization: Bearer <token>` header on all requests (both SSE and POST endpoints).

**REST API Authentication** (Laravel routes):

The HTTP REST API uses Laravel's standard auth:

```php
// In routes/api.php or config
WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum']  // Enforces auth
);
```

**Authentication Comparison**:

| Feature | MCP HTTP Server | REST API |
|---------|-----------------|----------|
| Transport | ReactPHP (dedicated server) | Laravel HTTP (web server) |
| Port | 8090 (configurable) | 80/443 (web server) |
| Auth Type | Bearer token (PSR-7 middleware) | Laravel middleware |
| Guards | Sanctum or static tokens | Any Laravel guard |
| Use Case | AI agents via MCP protocol | General HTTP clients |

**Important**: These are separate auth systems. MCP server auth protects the MCP endpoints (`/mcp/sse` and `/mcp/message`), while REST API auth protects the standard HTTP endpoints (`/agent/work/*`).

---

## Monitoring

### Log Files

**Laravel logs**: `storage/logs/laravel.log`
**MCP server logs**: `storage/logs/mcp-server.log` (if using supervisor)

### Health Checks

```bash
# Check if server is running
curl http://localhost:8090/mcp/sse

# Expected: SSE stream opens
```

### Metrics

Enable metrics in `config/work-manager.php`:

```php
'metrics' => [
    'enabled' => true,
    'driver' => 'statsd',
],
```

---

## Performance

### stdio Mode
- **Latency**: < 1ms (direct process communication)
- **Concurrency**: Single client
- **Use case**: Local development, AI IDEs

### HTTP Mode
- **Latency**: 5-10ms per request
- **Concurrency**: Unlimited clients
- **Use case**: Production, remote agents

### Optimization Tips

1. **Use Redis lease backend** for better concurrency
2. **Enable opcache** in production
3. **Use database connection pooling**
4. **Cache config**: `php artisan config:cache`

---

## Troubleshooting

### No Output When Starting

**Problem**: Command hangs with no output

**Solution**: Remove all `dd()`, `dump()`, `echo` from your code. MCP uses stdout for JSON-RPC communication.

### JSON Parse Errors

**Problem**: Client reports JSON parse errors

**Solution**: Same as above - ensure no debug output to stdout.

### Connection Refused (HTTP mode)

**Problem**: Client can't connect to HTTP server

**Solutions**:
- Check firewall rules
- Verify port not in use: `lsof -i :8090`
- Check binding address (0.0.0.0 vs 127.0.0.1)

### Tools Not Discovered

**Problem**: AI doesn't see tools

**Solutions**:
- Verify MCP server is running
- Check Laravel logs for errors
- Restart AI IDE/client
- Test server manually: `php artisan work-manager:mcp`

### Permission Denied

**Problem**: Can't write to logs or database

**Solutions**:
- Fix file permissions: `chmod -R 775 storage`
- Use correct user in supervisor/systemd config
- Check database credentials

---

## Testing the MCP Server

### Manual Testing (stdio)

```bash
# Start server
php artisan work-manager:mcp --transport=stdio

# In another terminal, send JSON-RPC request:
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | \
  php artisan work-manager:mcp --transport=stdio
```

### Manual Testing (HTTP)

```bash
# Start server
php artisan work-manager:mcp --transport=http --port=8090

# In another terminal:
curl -X POST http://localhost:8090/mcp/message \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

---

## Comparison: HTTP API vs MCP

| Feature | HTTP API | MCP Server |
|---------|----------|------------|
| Protocol | REST/JSON | JSON-RPC |
| Discovery | Manual (docs) | Automatic |
| Transport | HTTP only | stdio or HTTP |
| Best For | Web apps | AI agents |
| Auth | Token-based | Laravel auth |
| Concurrency | Unlimited | stdio: 1, HTTP: unlimited |

**Recommendation**: Use MCP for AI agents, HTTP API for custom integrations.

---

## See Also

- [HTTP API Guide](http-api.md) - REST API reference
- [Deployment Guide](deployment-and-production.md) - Production setup
- [Commands Reference](../reference/commands-reference.md) - work-manager:mcp command details
- Main [README.md](../../README.md) - Package overview
