# Security and Permissions

## Introduction

Laravel Work Manager implements a comprehensive security model covering authentication, authorization, idempotency, audit trails, and best practices for safe AI agent integration. This document explains the security architecture and how to implement proper access controls.

---

## Security Layers

```
┌────────────────────────────────────────────────────────┐
│ Layer 1: Network Security                              │
│ • HTTPS/TLS                                            │
│ • Rate limiting                                        │
│ • IP whitelisting (optional)                           │
└────────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────────┐
│ Layer 2: Authentication                                │
│ • Laravel Sanctum (API tokens)                         │
│ • Laravel Passport (OAuth2)                            │
│ • Session-based (web)                                  │
└────────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────────┐
│ Layer 3: Authorization                                 │
│ • Laravel Policies                                     │
│ • Gate checks                                          │
│ • Ability-based permissions                            │
└────────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────────┐
│ Layer 4: Idempotency & Replay Protection              │
│ • Idempotency keys                                     │
│ • Request fingerprinting                               │
│ • Cached responses                                     │
└────────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────────┐
│ Layer 5: Audit & Provenance                           │
│ • Event logging                                        │
│ • Agent metadata capture                               │
│ • Diff recording                                       │
└────────────────────────────────────────────────────────┘
```

---

## Authentication

### Overview

Work Manager uses Laravel's authentication system. All API endpoints require authentication by default.

### Sanctum (Recommended for AI Agents)

**Setup**:
```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

**Configuration**:
```php
// config/work-manager.php
'routes' => [
    'guard' => 'sanctum',
    'middleware' => ['api', 'auth:sanctum'],
],
```

**Token Generation**:
```php
// For an agent user
$user = User::find(1);
$token = $user->createToken('agent-1', ['work:*'])->plainTextToken;
```

**Agent Request**:
```http
POST /api/agent/work/propose
Authorization: Bearer {token}
X-Agent-ID: agent-1
Content-Type: application/json

{
    "type": "user.data.sync",
    "payload": { ... }
}
```

**Token Abilities** (Scoping):
```php
// Create token with specific abilities
$token = $user->createToken('agent-1', [
    'work:propose',
    'work:checkout',
    'work:submit',
])->plainTextToken;

// Check ability in controller
if ($request->user()->tokenCan('work:propose')) {
    // Allow
}
```

---

### Passport (OAuth2)

**Setup**:
```bash
composer require laravel/passport
php artisan migrate
php artisan passport:install
```

**Configuration**:
```php
'routes' => [
    'guard' => 'passport',
    'middleware' => ['api', 'auth:passport'],
],
```

**Use Cases**:
- Third-party integrations
- Federated systems
- Multi-tenant SaaS with OAuth

---

### Session-Based (Web)

**Configuration**:
```php
'routes' => [
    'guard' => 'web',
    'middleware' => ['web', 'auth'],
],
```

**Use Cases**:
- Backend admin interfaces
- Internal tools
- Human approval workflows

---

## Authorization

### Laravel Policies

Work Manager uses Laravel policies for fine-grained authorization.

#### WorkOrderPolicy

**Default Policy**:
```php
namespace App\Policies;

use App\Models\User;
use GregPriday\WorkManager\Models\WorkOrder;

class WorkOrderPolicy
{
    public function propose(?User $user): bool
    {
        // Check if user can propose work
        return $user && $user->hasPermission('work.propose');
    }

    public function view(User $user, WorkOrder $order): bool
    {
        // Check if user can view order
        return $user->hasPermission('work.view') ||
               $order->created_by_user_id === $user->id;
    }

    public function checkout(User $user, WorkOrder $order): bool
    {
        // Check if user can checkout items
        return $user->hasPermission('work.checkout');
    }

    public function approve(User $user, WorkOrder $order): bool
    {
        // Check if user can approve
        return $user->hasPermission('work.approve') &&
               $order->state === OrderState::SUBMITTED;
    }

    public function reject(User $user, WorkOrder $order): bool
    {
        // Check if user can reject
        return $user->hasPermission('work.reject') &&
               $order->state === OrderState::SUBMITTED;
    }
}
```

**Registration**:
```php
// app/Providers/AuthServiceProvider.php
use GregPriday\WorkManager\Models\WorkOrder;
use App\Policies\WorkOrderPolicy;

protected $policies = [
    WorkOrder::class => WorkOrderPolicy::class,
];
```

---

### Gate Definitions

**Define Gates**:
```php
// app/Providers/AuthServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot()
{
    Gate::define('work.propose', function (User $user) {
        return $user->hasRole('agent') || $user->hasRole('admin');
    });

    Gate::define('work.approve', function (User $user) {
        return $user->hasRole('admin') || $user->hasRole('supervisor');
    });

    Gate::define('work.checkout', function (User $user) {
        return $user->hasRole('agent');
    });
}
```

**Map to Config**:
```php
// config/work-manager.php
'policies' => [
    'propose' => 'work.propose',
    'checkout' => 'work.checkout',
    'submit' => 'work.submit',
    'approve' => 'work.approve',
    'reject' => 'work.reject',
],
```

---

### Controller Authorization

**In WorkOrderApiController**:
```php
public function propose(Request $request)
{
    Gate::authorize('propose', WorkOrder::class);

    // ... proceed with proposal
}

public function approve(WorkOrder $order, Request $request)
{
    Gate::authorize('approve', $order);

    // ... proceed with approval
}
```

---

### Agent-Specific Authorization

**Check Agent Ownership**:
```php
public function submit(WorkItem $item, Request $request)
{
    $agentId = $request->header('X-Agent-ID');

    // Verify agent owns the lease
    if ($item->leased_by_agent_id !== $agentId) {
        abort(403, 'You do not own this work item lease');
    }

    // ... proceed with submission
}
```

---

## Idempotency

### Purpose

Idempotency ensures that retrying a request multiple times has the same effect as making it once.

### How It Works

1. Client includes idempotency key in header
2. System hashes and stores key with scope
3. Response is cached
4. Retry with same key returns cached response

### Configuration

```php
'idempotency' => [
    'header' => 'X-Idempotency-Key',
    'enforce_on' => [
        'submit',
        'propose',
        'approve',
        'reject',
        'submit-part',
        'finalize',
    ],
],
```

### Usage

**Agent Request**:
```http
POST /api/agent/work/propose
Authorization: Bearer {token}
X-Idempotency-Key: prop-{uuid}
Content-Type: application/json

{
    "type": "user.data.sync",
    "payload": { ... }
}
```

**First Request**:
- Processes normally
- Stores key hash and response
- Returns response

**Retry with Same Key**:
- Detects duplicate key
- Returns cached response
- No duplicate work created

### Key Generation Best Practices

**UUIDs (Recommended)**:
```javascript
// Agent generates UUID
const key = `propose-${crypto.randomUUID()}`;
```

**Timestamp + Hash**:
```javascript
const key = `submit-${itemId}-${Date.now()}-${hash(payload)}`;
```

**Deterministic (For Exact Retries)**:
```javascript
// Same payload = same key
const key = `propose-${hash(JSON.stringify(payload))}`;
```

### IdempotencyService API

```php
public function guard(
    string $scope,
    string $key,
    callable $callback
): mixed;
```

**Example**:
```php
return $this->idempotencyService->guard(
    'propose',
    $request->header('X-Idempotency-Key'),
    function () use ($type, $payload) {
        return $this->workAllocator->propose($type, $payload);
    }
);
```

---

## Provenance Tracking

### Agent Metadata

**Headers**:
```http
X-Agent-ID: agent-1
X-Agent-Name: ResearchAgent
X-Agent-Version: 1.0.0
X-Model-Name: claude-3.5-sonnet
X-Runtime: python-3.11
X-Request-ID: req-abc-123
```

**Captured by WorkProvenance**:
```php
class WorkProvenance extends Model
{
    protected $casts = [
        'agent_metadata' => 'array',
        'request_fingerprint' => 'array',
    ];

    // Fields:
    // - order_id
    // - agent_id (from X-Agent-ID)
    // - agent_name (from X-Agent-Name)
    // - agent_version (from X-Agent-Version)
    // - agent_metadata (JSON: model, runtime, etc.)
    // - request_fingerprint (JSON: IP, user agent, etc.)
}
```

**Creation**:
```php
WorkProvenance::create([
    'order_id' => $order->id,
    'agent_id' => $request->header('X-Agent-ID'),
    'agent_name' => $request->header('X-Agent-Name'),
    'agent_version' => $request->header('X-Agent-Version'),
    'agent_metadata' => [
        'model_name' => $request->header('X-Model-Name'),
        'runtime' => $request->header('X-Runtime'),
    ],
    'request_fingerprint' => [
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'request_id' => $request->header('X-Request-ID'),
    ],
]);
```

---

### Audit Trail

**WorkEvent Records**:
Every action creates an event with:
- Who (actor_type, actor_id)
- What (event type, payload)
- When (timestamp)
- Why (message)
- Changes (diff for apply operations)

**Query Audit Trail**:
```php
// All events for an order
$events = WorkEvent::where('order_id', $orderId)
    ->orderBy('created_at')
    ->get();

// Events by actor
$agentEvents = WorkEvent::where('actor_id', 'agent-1')
    ->where('actor_type', ActorType::AGENT)
    ->get();

// Approval events
$approvals = WorkEvent::where('event', EventType::APPROVED)
    ->with('order')
    ->get();
```

---

## Rate Limiting

### Laravel Throttle Middleware

**Apply to Work Manager Routes**:
```php
// routes/api.php
use GregPriday\WorkManager\Facades\WorkManager;

WorkManager::routes(
    basePath: 'agent/work',
    middleware: ['api', 'auth:sanctum', 'throttle:60,1']
);
```

**Per-Route Rate Limits**:
```php
Route::post('propose', [WorkOrderApiController::class, 'propose'])
    ->middleware('throttle:10,1');  // 10 per minute

Route::post('checkout', [WorkOrderApiController::class, 'checkout'])
    ->middleware('throttle:100,1'); // 100 per minute

Route::post('submit', [WorkOrderApiController::class, 'submit'])
    ->middleware('throttle:60,1');  // 60 per minute
```

---

### Concurrency Limits

**Lease-Level Limits**:
```php
// config/work-manager.php
'lease' => [
    'max_leases_per_agent' => 10,   // Max concurrent leases per agent
    'max_leases_per_type' => 50,    // Max concurrent leases per type
],
```

**Enforcement**:
```php
// In LeaseService::acquire()
$currentLeases = WorkItem::where('leased_by_agent_id', $agentId)
    ->where('state', ItemState::LEASED)
    ->count();

if ($currentLeases >= config('work-manager.lease.max_leases_per_agent')) {
    throw new TooManyConcurrentLeasesException();
}
```

---

## Work-Order-Only Enforcement

### Purpose

Prevent direct mutations that bypass the work order system.

### EnforceWorkOrderOnly Middleware

**Apply to Legacy Routes**:
```php
// routes/api.php
use GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly;

Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);

Route::put('/users/{user}', [UserController::class, 'update'])
    ->middleware(EnforceWorkOrderOnly::class);

Route::delete('/users/{user}', [UserController::class, 'destroy'])
    ->middleware(EnforceWorkOrderOnly::class);
```

**How It Works**:
```php
class EnforceWorkOrderOnly
{
    public function handle($request, Closure $next)
    {
        // Check if request is from an applied work order
        $workOrderContext = $request->header('X-Work-Order-Context');

        if (!$workOrderContext) {
            abort(403, 'Direct mutations not allowed. Use work order system.');
        }

        return $next($request);
    }
}
```

**Set Context in apply()**:
```php
public function apply(WorkOrder $order): Diff
{
    // Set work order context for downstream requests
    request()->headers->set('X-Work-Order-Context', $order->id);

    DB::transaction(function () use ($order) {
        // Your mutations here
        // These will pass the EnforceWorkOrderOnly middleware
    });

    return $this->makeDiff($before, $after);
}
```

---

## Security Best Practices

### 1. Always Use HTTPS

```nginx
# nginx configuration
server {
    listen 443 ssl http2;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location /api/agent/work {
        proxy_pass http://laravel-app;
    }
}
```

---

### 2. Rotate API Tokens

```php
// Revoke old token
$user->tokens()->where('name', 'agent-1')->delete();

// Issue new token
$newToken = $user->createToken('agent-1', ['work:*'])->plainTextToken;
```

**Schedule Rotation**:
```php
// app/Console/Kernel.php
$schedule->command('tokens:rotate')->monthly();
```

---

### 3. Validate Payload Schemas

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['source', 'user_ids'],
        'properties' => [
            'source' => [
                'type' => 'string',
                'enum' => ['crm', 'analytics'],  // Whitelist values
            ],
            'user_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'minItems' => 1,
                'maxItems' => 1000,  // Prevent abuse
            ],
        ],
    ];
}
```

---

### 4. Validate Agent Submissions

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'email' => 'required|email|max:255',
        'url' => 'required|url|max:500',
        'data' => 'required|array|max:100',  // Limit array size
        'data.*.verified' => 'required|boolean|accepted',
    ];
}
```

---

### 5. Sanitize Sensitive Data

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Redact sensitive data before storing
    if (isset($result['password'])) {
        unset($result['password']);
    }

    if (isset($result['ssn'])) {
        $result['ssn'] = '***-**-****';
    }

    $item->result = $result;
}
```

---

### 6. Detect PII/Secrets

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $patterns = [
        'credit_card' => '/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'api_key' => '/\b[A-Za-z0-9]{32,}\b/',
    ];

    $json = json_encode($result);

    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $json)) {
            throw ValidationException::withMessages([
                'result' => ["Detected {$type} in submission. Remove sensitive data."],
            ]);
        }
    }
}
```

---

### 7. Verify External Data

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify data with external system
    foreach ($result['users'] as $user) {
        $verified = Http::get("https://api.crm.com/verify/{$user['id']}")->json();

        if (!$verified['exists']) {
            throw ValidationException::withMessages([
                'users' => ["User {$user['id']} does not exist in CRM"],
            ]);
        }
    }
}
```

---

### 8. Check robots.txt Compliance

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    foreach ($result['evidence'] ?? [] as $evidence) {
        $url = $evidence['url'];
        $domain = parse_url($url, PHP_URL_HOST);

        if (!$this->isAllowedByRobotsTxt($domain, $url)) {
            throw ValidationException::withMessages([
                'evidence.url' => ["Agent violated robots.txt for {$domain}"],
            ]);
        }
    }
}

protected function isAllowedByRobotsTxt(string $domain, string $url): bool
{
    $robotsTxt = Http::get("https://{$domain}/robots.txt")->body();
    $parser = new RobotsTxtParser($robotsTxt);

    return $parser->isAllowed('*', $url);
}
```

---

### 9. Log Accessed Domains

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $domains = collect($result['evidence'] ?? [])
        ->pluck('url')
        ->map(fn($url) => parse_url($url, PHP_URL_HOST))
        ->unique()
        ->values()
        ->toArray();

    WorkProvenance::updateOrCreate(
        ['order_id' => $item->order_id],
        ['accessed_domains' => $domains]
    );
}
```

---

### 10. Monitor for Anomalies

```php
// Detect suspicious patterns
Event::listen(WorkOrderProposed::class, function ($event) {
    $recentOrders = WorkOrder::where('created_by_user_id', $event->order->created_by_user_id)
        ->where('created_at', '>=', now()->subHour())
        ->count();

    if ($recentOrders > 100) {
        // Alert on unusual activity
        Log::warning('High order creation rate detected', [
            'user_id' => $event->order->created_by_user_id,
            'count' => $recentOrders,
        ]);

        // Optionally block
        $event->order->update(['state' => OrderState::REJECTED]);
    }
});
```

---

## Multi-Tenant Security

### Tenant Isolation

**Add tenant_id to Models**:
```php
// Migration
Schema::table('work_orders', function (Blueprint $table) {
    $table->unsignedBigInteger('tenant_id')->nullable();
    $table->index('tenant_id');
});

Schema::table('work_items', function (Blueprint $table) {
    $table->unsignedBigInteger('tenant_id')->nullable();
    $table->index('tenant_id');
});
```

**Global Scope**:
```php
// app/Models/WorkOrder.php
use Illuminate\Database\Eloquent\Builder;

protected static function booted()
{
    static::addGlobalScope('tenant', function (Builder $builder) {
        if (auth()->check() && auth()->user()->tenant_id) {
            $builder->where('tenant_id', auth()->user()->tenant_id);
        }
    });
}
```

**Enforce Tenant in Policies**:
```php
public function view(User $user, WorkOrder $order): bool
{
    return $user->tenant_id === $order->tenant_id &&
           $user->hasPermission('work.view');
}
```

---

### Tenant Quotas

```php
public function propose(?User $user): bool
{
    // Check tenant quota
    $tenantOrders = WorkOrder::withoutGlobalScope('tenant')
        ->where('tenant_id', $user->tenant_id)
        ->where('created_at', '>=', now()->subMonth())
        ->count();

    $quota = $user->tenant->work_order_quota;

    return $tenantOrders < $quota;
}
```

---

## Compliance & Regulations

### GDPR Considerations

**Data Minimization**:
```php
// Only store necessary data
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|integer',
        'action' => 'required|string',
        // Don't require PII unless absolutely necessary
    ];
}
```

**Right to Erasure**:
```php
public function deletePersonalData(User $user): void
{
    // Delete user's work orders
    WorkOrder::where('created_by_user_id', $user->id)->delete();

    // Anonymize provenance
    WorkProvenance::where('agent_id', "user-{$user->id}")
        ->update(['agent_id' => 'anonymized']);
}
```

---

### SOC 2 / Audit Requirements

**Comprehensive Logging**:
```php
// All operations logged to work_events
$events = WorkEvent::where('created_at', '>=', $startDate)
    ->where('created_at', '<=', $endDate)
    ->get();
```

**Immutable Audit Trail**:
```php
// Prevent modification of events
class WorkEvent extends Model
{
    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \Exception('WorkEvent records are immutable');
        });
    }
}
```

---

## Security Checklist

### Production Deployment

- [ ] HTTPS/TLS enabled
- [ ] Authentication configured (Sanctum/Passport)
- [ ] Policies enforced on all endpoints
- [ ] Rate limiting configured
- [ ] Idempotency enforced on mutating operations
- [ ] Sensitive data redacted in logs/events
- [ ] PII detection implemented
- [ ] External data verification enabled
- [ ] robots.txt compliance checked
- [ ] EnforceWorkOrderOnly middleware applied to legacy routes
- [ ] Tenant isolation configured (multi-tenant)
- [ ] Quotas enforced (multi-tenant)
- [ ] Anomaly detection monitoring active
- [ ] Audit logs backed up regularly
- [ ] Token rotation schedule established
- [ ] GDPR/compliance requirements met

---

## See Also

- [What It Does](what-it-does.md) - Core concepts
- [Architecture Overview](architecture-overview.md) - System design
- [Lifecycle and Flow](lifecycle-and-flow.md) - Work order lifecycle
- [Configuration Model](configuration-model.md) - Configuration options
- [State Management](state-management.md) - State machine
- [ARCHITECTURE.md](architecture-overview.md) - Advanced security patterns
