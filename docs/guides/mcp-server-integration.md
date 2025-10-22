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
- Bind to `127.0.0.1` for local-only
- Use SSL/TLS (via Nginx proxy)
- Implement rate limiting
- Use firewall rules to restrict access
- Enable Laravel authentication
- Monitor for unusual activity

### Authentication

MCP server uses Laravel's auth system:

```php
// In routes/api.php or config
WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum']  // Enforces auth
);
```

Ensure agents provide valid tokens.

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
