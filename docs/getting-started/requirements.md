# Requirements

This page details the system requirements and dependencies for Laravel Work Manager.

## Server Requirements

### PHP

**Minimum**: PHP 8.2

**Recommended**: PHP 8.3+

**Required Extensions:**
- `ext-json` - JSON encoding/decoding
- `ext-mbstring` - Multibyte string handling
- `ext-pdo` - Database connectivity
- `ext-openssl` - Secure communication
- `ext-tokenizer` - PHP tokenization

**Optional Extensions:**
- `ext-redis` - For Redis lease backend and caching
- `ext-pcntl` - For graceful signal handling in MCP server
- `ext-posix` - For process management

### Laravel

**Minimum**: Laravel 11.0

**Supported Versions:**
- Laravel 11.x
- Laravel 12.x

The package uses Laravel's auto-discovery feature, so no manual service provider registration is needed.

### Database

Laravel Work Manager stores work orders, items, events, and provenance data in your application's database.

**Supported Databases:**
- **MySQL** 8.0+ (recommended 8.0.28+)
- **PostgreSQL** 13+ (recommended 14+)
- **SQLite** 3.8.8+ (for testing only, not recommended for production)

**Database Features Used:**
- JSON columns (for payloads, metadata, events)
- UUID support (for work order and item IDs)
- Foreign keys and cascading deletes
- Row-level locking (`FOR UPDATE`) for lease backend
- Composite indexes for query performance

**Database Configuration:**

For optimal performance, ensure:
- Maximum connections sufficient for concurrent agents
- JSON column support enabled
- Query cache disabled for real-time lease queries
- Appropriate transaction isolation level (READ COMMITTED recommended)

### Redis (Optional)

**Version**: Redis 5.0+ (recommended 6.2+)

**When Needed:**
- Using Redis lease backend (`config/work-manager.php`: `'lease.backend' => 'redis'`)
- Queue workers configured to use Redis
- Caching idempotency keys in Redis

**Required Redis Features:**
- `SET NX EX` commands (atomic lease acquisition)
- Keyspace expiration
- Pipelining (for batch operations)

## Development Environment

### Local Development

**Recommended Tools:**
- Laravel Herd, Valet, or Homestead
- MySQL or PostgreSQL via Docker
- Redis via Docker (optional)
- Composer 2.x

### Docker (Optional)

If using Docker, ensure:
- Docker 20.10+
- Docker Compose 2.x
- Sufficient resources (2GB+ RAM for typical workloads)

### Testing Environment

For running the package test suite:
- PHPUnit 10.x or 11.x
- Pest 2.x or 3.x (included as dev dependency)
- Orchestra Testbench (included as dev dependency)

## Production Environment

### Application Server

**PHP-FPM** (recommended):
- Worker processes: Scale based on agent concurrency
- Max execution time: 300s+ (for long-running apply operations)
- Memory limit: 256MB+ per worker

**Or PHP CLI + Supervisor**:
- For running MCP server in HTTP mode
- For queue workers

### Web Server

**Supported:**
- Nginx 1.18+ (recommended)
- Apache 2.4+
- Caddy 2.x

**Requirements:**
- HTTPS/TLS support (required for production)
- WebSocket support (for MCP HTTP SSE endpoint)
- Configurable timeouts (for long-running requests)

### Queue Workers

**Required If Using:**
- Background job processing
- Async maintenance tasks
- Event-driven workflows

**Queue Backends:**
- Redis (recommended)
- Database (fallback)
- SQS, Beanstalkd, etc. (supported via Laravel)

### Scheduled Tasks

Requires cron or Laravel's scheduler for:
- `work-manager:generate` - Generate new work orders
- `work-manager:maintain` - Reclaim expired leases, dead-letter stuck work

## Agent Requirements

### For AI Agents Using MCP

**Local Agents** (Cursor, Claude Code, etc.):
- MCP client support
- Ability to invoke stdio processes
- Access to PHP binary and artisan command

**Remote Agents** (production deployments):
- HTTP client with SSE support
- JSON-RPC 2.0 support
- Ability to send authentication headers

### For Custom Agent Integrations

**HTTP Client Requirements:**
- Support for HTTP/1.1 or HTTP/2
- JSON request/response handling
- Custom header support:
  - `X-Idempotency-Key` for request deduplication
  - `X-Agent-ID` for agent identification
  - `Authorization` for authentication
- Retry logic with exponential backoff
- Timeout handling (recommended 60s for submit, 300s for approve/apply)

## Networking

### Firewall Rules

**Inbound:**
- Port 443 (HTTPS) for HTTP API
- Port 8090 (or configured) for MCP HTTP server (if using remote mode)

**Outbound:**
- Database server (MySQL/PostgreSQL)
- Redis server (if using Redis backend)
- External APIs (if your order types call external services)

### Load Balancer (If Applicable)

**Requirements:**
- Sticky sessions NOT required (API is stateless)
- Health check endpoint: `GET /` on API base path
- Timeout: 300s for approve/apply operations
- WebSocket/SSE support for MCP HTTP mode

## Resource Estimates

### Small Deployments (< 100 orders/day)

- **CPU**: 2 cores
- **RAM**: 2GB
- **Database**: 20GB SSD
- **Agents**: 1-5 concurrent

### Medium Deployments (100-1000 orders/day)

- **CPU**: 4 cores
- **RAM**: 8GB
- **Database**: 50GB SSD
- **Redis**: 1GB
- **Agents**: 5-20 concurrent

### Large Deployments (1000+ orders/day)

- **CPU**: 8+ cores
- **RAM**: 16GB+
- **Database**: 100GB+ SSD (or dedicated RDS/managed instance)
- **Redis**: 4GB+
- **Agents**: 20-100+ concurrent
- **Load Balancer**: Recommended
- **Horizontal Scaling**: Multiple app servers

## Dependency Summary

Run `composer info` after installation to see exact versions:

```json
{
  "require": {
    "php": "^8.2",
    "illuminate/support": "^11.0|^12.0",
    "illuminate/database": "^11.0|^12.0",
    "illuminate/console": "^11.0|^12.0",
    "illuminate/http": "^11.0|^12.0",
    "illuminate/validation": "^11.0|^12.0",
    "ramsey/uuid": "^4.7",
    "php-mcp/laravel": "^1.0"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0|^10.0",
    "pestphp/pest": "^2.0|^3.0",
    "pestphp/pest-plugin-laravel": "^2.0|^3.0"
  }
}
```

## Verification Checklist

Before proceeding with installation, verify:

- [ ] PHP 8.2+ installed with required extensions
- [ ] Laravel 11+ project initialized
- [ ] Database server running and accessible
- [ ] Database supports JSON columns and UUIDs
- [ ] Composer 2.x available
- [ ] Redis running (if using Redis lease backend)
- [ ] Sufficient disk space for database (estimate: 10MB per 1000 orders)
- [ ] Production server meets resource requirements for expected load

---

## See Also

- [Installation](installation.md) - Install the package
- [Deployment & Production](../guides/deployment-and-production.md) - Production deployment guide
- [Configuration](../guides/configuration.md) - Configuration options
- [Troubleshooting](../troubleshooting/common-errors.md) - Common setup issues
