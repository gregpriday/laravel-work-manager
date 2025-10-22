# Security Policy

## Supported Versions

We actively support the following versions with security updates:

| Version | Supported          | Status      |
| ------- | ------------------ | ----------- |
| 1.x     | :white_check_mark: | Active      |
| < 1.0   | :x:                | Unsupported |

**Note**: Pre-1.0 releases are beta versions and do not receive security updates. Please upgrade to 1.x for production use.

---

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability in Laravel Work Manager, please report it responsibly.

### How to Report

**DO NOT** create a public GitHub issue for security vulnerabilities.

Instead, please email: **greg@siteorigin.com**

Include in your report:
1. **Description**: Clear description of the vulnerability
2. **Impact**: Potential impact and attack scenarios
3. **Reproduction**: Step-by-step instructions to reproduce
4. **Environment**: Affected versions, configurations
5. **Suggested Fix**: If you have one (optional)

### Example Report

```
Subject: [SECURITY] SQL Injection in WorkOrderApiController

Description:
A SQL injection vulnerability exists in the order filtering logic...

Impact:
An authenticated attacker could execute arbitrary SQL queries...

Reproduction Steps:
1. Authenticate as any user
2. Send request to /api/work/orders?filter[type]=...
3. Observe SQL error...

Affected Versions:
1.0.0 - 1.0.5

Suggested Fix:
Use parameterized queries instead of string concatenation...
```

### Response Timeline

We aim to respond to security reports within:
- **24 hours**: Initial acknowledgment
- **7 days**: Assessment and severity classification
- **30 days**: Fix released (for high-severity issues)
- **90 days**: Fix released (for lower-severity issues)

### Disclosure Policy

- We follow **coordinated disclosure**
- Security issues are fixed before public disclosure
- We will credit reporters (unless they prefer anonymity)
- Public disclosure happens after fix is released and users have time to upgrade

---

## Security Best Practices

### For Package Users

When using Laravel Work Manager in production, follow these security best practices:

#### 1. Authentication & Authorization

**Always require authentication:**
```php
WorkManager::routes(
    middleware: ['api', 'auth:sanctum'] // Always include auth
);
```

**Configure authorization policies:**
```php
// config/work-manager.php
'policies' => [
    'propose' => 'work.propose',
    'approve' => 'work.approve',
],

// app/Policies/WorkOrderPolicy.php
public function propose(User $user): bool
{
    return $user->hasPermission('work.propose');
}

public function approve(User $user, WorkOrder $order): bool
{
    return $user->hasRole('admin');
}
```

#### 2. Protect Legacy Routes

**Block direct mutations:**
```php
use GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly;

Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);
```

This ensures all mutations go through the work order system with proper audit trails.

#### 3. Use Idempotency Keys

**Prevent replay attacks:**
```bash
curl -X POST /api/work/propose \
  -H "X-Idempotency-Key: unique-key-$(date +%s)" \
  -d '{...}'
```

Idempotency keys prevent duplicate operations and replay attacks.

#### 4. Validate Agent Submissions

**Never trust agent input:**
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|exists:users,id',
        'email' => 'required|email|max:255',
        'data' => 'required|array|max:100', // Limit size
    ];
}

protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Additional business logic validation
    if ($this->detectSuspiciousPattern($result)) {
        throw ValidationException::withMessages([
            'data' => ['Suspicious pattern detected'],
        ]);
    }
}
```

#### 5. Sanitize and Limit Payloads

**Prevent large payload attacks:**
```php
// config/work-manager.php
'partials' => [
    'max_parts_per_item' => 100,
    'max_payload_bytes' => 1048576, // 1MB
],
```

**Sanitize user-provided data:**
```php
public function apply(WorkOrder $order): Diff
{
    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            User::create([
                'name' => strip_tags($item->result['name']), // Sanitize
                'email' => filter_var($item->result['email'], FILTER_VALIDATE_EMAIL),
            ]);
        }
    });
}
```

#### 6. Rate Limiting

**Prevent abuse:**
```php
use Illuminate\Routing\Middleware\ThrottleRequests;

WorkManager::routes(
    middleware: ['api', 'auth:sanctum', 'throttle:60,1'] // 60 req/min per IP
);
```

**Or per-agent rate limiting:**
```php
// Custom middleware
public function handle($request, Closure $next)
{
    $agentId = $request->header('X-Agent-ID');

    if (RateLimiter::tooManyAttempts($agentId, 100)) {
        return response()->json(['error' => 'Too many requests'], 429);
    }

    RateLimiter::hit($agentId, 60);

    return $next($request);
}
```

#### 7. Secure MCP Server

**HTTP transport security:**
```nginx
# Use HTTPS only
server {
    listen 443 ssl http2;
    server_name mcp.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # Strong SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    location / {
        proxy_pass http://127.0.0.1:8090;

        # Rate limiting
        limit_req zone=mcp burst=20 nodelay;
    }
}
```

**Firewall rules:**
```bash
# Only allow from known IPs
ufw allow from 203.0.113.0/24 to any port 8090 proto tcp
```

#### 8. Audit Logging

**Monitor all operations:**
```php
use GregPriday\WorkManager\Events\WorkOrderProposed;
use GregPriday\WorkManager\Events\WorkOrderApplied;

Event::listen(WorkOrderProposed::class, function ($event) {
    Log::info('Work order proposed', [
        'user_id' => auth()->id(),
        'order_id' => $event->order->id,
        'type' => $event->order->type,
        'ip' => request()->ip(),
    ]);
});

Event::listen(WorkOrderApplied::class, function ($event) {
    Log::info('Work order applied', [
        'order_id' => $event->order->id,
        'diff' => $event->diff->toArray(),
        'applied_by' => $event->actor,
    ]);
});
```

**Ship logs to SIEM:**
```php
// config/logging.php
'channels' => [
    'work_manager' => [
        'driver' => 'syslog',
        'facility' => LOG_LOCAL0, // Send to SIEM
    ],
],
```

#### 9. Database Security

**Use read-only replicas for queries:**
```php
// For agent queries
$orders = WorkOrder::on('mysql::read')
    ->where('state', OrderState::QUEUED)
    ->get();

// For mutations (always on primary)
DB::transaction(function () use ($order) {
    $order->update(['state' => OrderState::APPLIED]);
});
```

**Encrypt sensitive data:**
```php
class WorkEvent extends Model
{
    protected $casts = [
        'payload' => 'encrypted:array', // Encrypt at rest
    ];
}
```

#### 10. Principle of Least Privilege

**Database users:**
```sql
-- Read-only user for agent queries
GRANT SELECT ON work_orders TO 'work_manager_read'@'%';
GRANT SELECT ON work_items TO 'work_manager_read'@'%';

-- Full access for mutations
GRANT ALL ON work_orders TO 'work_manager_write'@'%';
GRANT ALL ON work_items TO 'work_manager_write'@'%';
```

**Agent permissions:**
```php
public function checkout(User $user, WorkOrder $order): bool
{
    // Agent can only checkout orders they can see
    return $user->tenant_id === $order->payload['tenant_id'];
}
```

---

## Common Security Pitfalls

### 1. Trusting Agent Input

**Bad:**
```php
public function apply(WorkOrder $order): Diff
{
    // Dangerous: executing agent-provided SQL
    DB::statement($order->payload['query']);
}
```

**Good:**
```php
public function apply(WorkOrder $order): Diff
{
    // Safe: parameterized query
    User::where('id', $order->payload['user_id'])
        ->update($order->payload['data']);
}
```

### 2. Weak Authorization

**Bad:**
```php
public function approve(User $user, WorkOrder $order): bool
{
    return true; // Anyone can approve!
}
```

**Good:**
```php
public function approve(User $user, WorkOrder $order): bool
{
    return $user->hasRole('admin') ||
           $user->id === $order->created_by;
}
```

### 3. Missing Input Validation

**Bad:**
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return []; // No validation!
}
```

**Good:**
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|exists:users,id',
        'email' => 'required|email|unique:users',
        'amount' => 'required|numeric|min:0|max:10000',
    ];
}
```

### 4. Exposing Sensitive Data

**Bad:**
```php
public function toArray(): array
{
    return [
        'order' => $this->order,
        'user' => $this->user, // Includes password hash!
    ];
}
```

**Good:**
```php
public function toArray(): array
{
    return [
        'order' => $this->order->only(['id', 'type', 'state']),
        'user' => $this->user->only(['id', 'name', 'email']),
    ];
}
```

### 5. No Audit Trail

**Bad:**
```php
User::where('id', $userId)->delete(); // Who deleted it? When? Why?
```

**Good:**
```php
// All mutations through work orders = full audit trail
WorkEvent records every action with actor, timestamp, diff
```

---

## Security Checklist for Production

Before deploying to production:

- [ ] Authentication required on all endpoints
- [ ] Authorization policies configured and tested
- [ ] Rate limiting enabled
- [ ] Input validation comprehensive
- [ ] Idempotency keys enforced
- [ ] HTTPS/TLS configured (for MCP HTTP transport)
- [ ] Database credentials secured (secrets management)
- [ ] Audit logging enabled and monitored
- [ ] Error messages don't expose sensitive info
- [ ] Dependencies up to date (`composer update`)
- [ ] Security headers configured (CORS, CSP, etc.)
- [ ] EnforceWorkOrderOnly middleware on critical routes
- [ ] Backup and disaster recovery tested
- [ ] Security monitoring/alerting configured

---

## Known Security Considerations

### State Machine as Security Boundary

The state machine enforces valid transitions, preventing:
- Applying unapproved work
- Resubmitting completed items
- Bypassing validation

**This is a security feature.** Do not modify state directly in the database.

### Idempotency Keys

Idempotency keys are hashed and stored. This prevents:
- Replay attacks
- Duplicate operations
- Race conditions

**Use unique keys per operation.**

### Lease System

The TTL-based lease system prevents:
- Concurrent processing (race conditions)
- Lost work (with heartbeat)
- Zombie agents (lease expiration)

**Always heartbeat for long operations.**

---

## Compliance Considerations

### GDPR

For GDPR compliance:
1. **Right to erasure**: Implement order type that removes user data
2. **Audit trail**: WorkEvent provides complete history
3. **Data portability**: Export order/event data
4. **Encryption**: Encrypt sensitive payload data

### SOC 2

For SOC 2 compliance:
1. **Audit logging**: All mutations logged with actor
2. **Access control**: Policy-based authorization
3. **Data integrity**: State machine prevents invalid operations
4. **Provenance**: WorkProvenance tracks all agent metadata

### HIPAA

For HIPAA compliance (PHI):
1. **Encrypt at rest**: Use encrypted columns
2. **Encrypt in transit**: HTTPS/TLS required
3. **Access logging**: Monitor all PHI access
4. **Minimum necessary**: Limit data in payloads

---

## Security Updates

Security updates are released as:
- Patch versions for low/medium severity (1.0.x)
- Minor versions for high severity (1.x.0)

Subscribe to releases on GitHub to receive notifications.

---

## Contact

For security questions or to report vulnerabilities:
- Email: greg@siteorigin.com
- PGP key: [Available on request]

For general support:
- GitHub Issues: https://github.com/gregpriday/laravel-work-manager/issues
