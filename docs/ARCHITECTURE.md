# Laravel Work Manager Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         AI AGENT / CLIENT                        │
│  (Proposes work, checks out items, submits results)             │
└────────────────┬────────────────────────────────────────────────┘
                 │ HTTP/MCP
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                      HTTP API LAYER                              │
│  WorkOrderApiController                                          │
│  • propose, checkout, heartbeat, submit, approve, reject         │
│  • Idempotency enforcement                                       │
│  • Authorization via policies                                    │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     SERVICE LAYER                                │
├──────────────────────────────────────────────────────────────────┤
│  WorkAllocator         │  WorkExecutor       │  LeaseService    │
│  • propose()           │  • submit()         │  • acquire()     │
│  • plan()              │  • approve()        │  • extend()      │
│                        │  • reject()         │  • release()     │
│                        │  • apply()          │  • reclaim()     │
├──────────────────────────────────────────────────────────────────┤
│  StateMachine          │  IdempotencyService │  Registry        │
│  • transitionOrder()   │  • guard()          │  • register()    │
│  • transitionItem()    │  • check()          │  • get()         │
│  • recordEvent()       │  • store()          │                  │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                    ORDER TYPE LAYER                              │
│  Your Custom Types (extend AbstractOrderType)                   │
├──────────────────────────────────────────────────────────────────┤
│  type()                    │ Lifecycle Hooks:                   │
│  schema()                  │ • beforeApply()                    │
│  plan()                    │ • apply() ⭐                       │
│  acceptancePolicy()        │ • afterApply()                     │
│                            │                                     │
│  Validation Hooks:         │ Verification:                      │
│  • submissionValidationRules()  • afterValidateSubmission()    │
│  • validationMessages()    │ • canApprove()                     │
└────────────────┬────────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────────┐
│                     DATA/MODEL LAYER                             │
├──────────────────────────────────────────────────────────────────┤
│  WorkOrder                 │  WorkItem                           │
│  • type, state, payload    │  • state, input, result             │
│  • priority, meta          │  • lease info, attempts             │
│                            │                                     │
│  WorkEvent                 │  WorkProvenance                     │
│  • event, actor, payload   │  • agent metadata                   │
│  • diff, message           │  • request fingerprint              │
│                            │                                     │
│  WorkIdempotencyKey        │                                     │
│  • scope, key_hash         │                                     │
└─────────────────────────────────────────────────────────────────┘
```

## Data Flow: Complete Lifecycle

### 1. Proposal Flow
```
Agent/System
    │
    ├─> POST /propose {type, payload}
    │
    ├─> WorkAllocator::propose()
    │   ├─> Validate against schema()
    │   ├─> Create WorkOrder (state: queued)
    │   └─> Emit WorkOrderProposed event
    │
    └─> OrderType::plan(order)
        ├─> Create WorkItems
        └─> Emit WorkOrderPlanned event
```

### 2. Agent Execution Flow
```
Agent
    │
    ├─> POST /orders/{order}/checkout
    │   ├─> LeaseService::acquire(item)
    │   ├─> Lock item with TTL
    │   ├─> Return item.input to agent
    │   └─> Emit WorkItemLeased event
    │
    ├─> [Agent processes work...]
    │
    ├─> POST /items/{item}/heartbeat (every 2 min)
    │   ├─> LeaseService::extend()
    │   └─> Emit WorkItemHeartbeat event
    │
    └─> POST /items/{item}/submit {result}
        ├─> WorkExecutor::submit(item, result)
        ├─> AcceptancePolicy::validateSubmission()
        │   ├─> submissionValidationRules() [Laravel validation]
        │   └─> afterValidateSubmission() [Custom checks]
        ├─> Save item.result
        └─> Emit WorkItemSubmitted event
```

### 3. Verification & Approval Flow
```
System/Backend User
    │
    ├─> AcceptancePolicy::readyForApproval(order)
    │   ├─> Check all items submitted
    │   └─> canApprove() [Custom logic]
    │
    ├─> POST /orders/{order}/approve
    │   ├─> WorkExecutor::approve(order)
    │   ├─> StateMachine::transitionOrder(approved)
    │   ├─> Emit WorkOrderApproved event
    │   │
    │   └─> WorkExecutor::apply(order)
    │       ├─> beforeApply() hook
    │       ├─> OrderType::apply(order) ⭐ [Your domain logic]
    │       ├─> StateMachine::transitionOrder(applied)
    │       ├─> Record diff in WorkEvent
    │       ├─> Emit WorkOrderApplied event
    │       ├─> afterApply() hook
    │       └─> Mark items as accepted/completed
    │
    └─> StateMachine::transitionOrder(completed)
        └─> Emit WorkOrderCompleted event
```

## State Transitions

### WorkOrder States
```
queued ──> checked_out ──> in_progress ──> submitted ──> approved
                                                            │
                                                            ▼
                                                         applied
                                                            │
                                                            ▼
                                                        completed

Failed/Rejected paths:
submitted ──> rejected ──> queued (if rework allowed)
                      └──> dead_lettered
any state ──> failed ──> dead_lettered
```

### WorkItem States
```
queued ──> leased ──> in_progress ──> submitted ──> accepted ──> completed

Failed path:
leased ──> (lease expires) ──> queued (retry)
                           └──> failed (max attempts) ──> dead_lettered
```

## Key Concepts

### 1. Order Types
Custom classes defining:
- **Schema**: What data is required
- **Planning**: How to break work into items
- **Validation**: How to verify agent work
- **Execution**: How to apply changes

### 2. Leasing
- Single agent can lease an item at a time
- TTL-based with heartbeat requirement
- Automatic reclaim on expiry
- Prevents concurrent processing

### 3. Idempotency
- Request deduplication via header
- Cached responses for retries
- Scope-based (propose, submit, approve, reject)

### 4. State Machine
- Enforced transitions
- Automatic event recording
- Timestamps for each transition
- Actor tracking

### 5. Verification
Two-phase validation:
1. **Agent Submission**: Laravel rules + custom checks
2. **Approval Readiness**: Cross-item validation

### 6. Auditability
Complete trail via:
- **WorkEvent**: Every state change, action, decision
- **WorkProvenance**: Agent metadata, request fingerprints
- **Diffs**: Before/after snapshots of changes

## Integration Points

### Laravel Events
```php
Event::listen(WorkOrderApplied::class, function($event) {
    // React to order application
});
```

### Laravel Jobs/Queues
```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    ProcessFollowUp::dispatch($order)->onQueue('work');
}
```

### Laravel Validation
```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'user_id' => 'required|exists:users,id',
        'email' => 'required|email|unique:users',
    ];
}
```

### Laravel Policies
```php
Gate::allows('approve', $order); // Uses WorkOrderPolicy
```

### Scheduled Commands
```php
$schedule->command('work-manager:generate')->everyFifteenMinutes();
$schedule->command('work-manager:maintain')->everyMinute();
```

## Extending the System

### Create a New Order Type

1. **Extend AbstractOrderType**
```php
class MyType extends AbstractOrderType { }
```

2. **Implement required methods**
```php
public function type(): string { }
public function schema(): array { }
public function apply(WorkOrder $order): Diff { }
```

3. **Add validation**
```php
protected function submissionValidationRules(WorkItem $item): array { }
protected function afterValidateSubmission(WorkItem $item, array $result): void { }
```

4. **Add lifecycle hooks**
```php
protected function beforeApply(WorkOrder $order): void { }
protected function afterApply(WorkOrder $order, Diff $diff): void { }
```

5. **Register**
```php
WorkManager::registry()->register(new MyType());
```

## Security

- **Authentication**: Required for all endpoints (configurable guard)
- **Authorization**: Policy-based per endpoint
- **Idempotency**: Prevents replay attacks
- **Leasing**: Prevents race conditions
- **State Machine**: Prevents invalid transitions
- **Validation**: Multi-layer verification

## Performance Considerations

- **Database Locking**: Uses `FOR UPDATE` to prevent race conditions
- **Transactions**: All mutations wrapped in DB transactions
- **Indexes**: Optimized queries with composite indexes
- **Eager Loading**: Relationships loaded efficiently
- **Queue Integration**: Optional async processing
- **Lease Reclaim**: Batch processing in maintenance

## Monitoring

Track these metrics:
- Orders created per type
- Items checked out
- Submissions received
- Orders approved/rejected
- Lease expirations
- Time in each state
- Failure reasons

Available via events or direct queries on models.

## Recommended Improvements & Future Enhancements

Based on production usage patterns and scaling requirements, the following enhancements are recommended for high-volume or high-criticality deployments:

### 1. Enhanced JSON Schema Validation

**Current State**: Basic JSON schema validation using internal helpers.

**Recommendation**: Integrate a full-featured JSON Schema validator (e.g., `opis/json-schema` or `justinrainbow/json-schema`) for strict payload enforcement.

**Benefits**:
- Comprehensive schema validation with complex rules
- Better error messages for agents
- Industry-standard schema support
- Reusable schema definitions

**Implementation**:
```php
composer require opis/json-schema

// In OrderType
use Opis\JsonSchema\Validator;

protected function validatePayload(array $payload): void
{
    $validator = new Validator();
    $result = $validator->validate(
        json_decode(json_encode($payload)),
        $this->schema()
    );
    
    if (!$result->isValid()) {
        throw new ValidationException($result->error());
    }
}
```

---

### 2. Auto-Approval by Type

**Current State**: Auto-approval is implemented via `$autoApprove` property on your order type and `WorkExecutor::checkAutoApproval()`.

**Recommendation**: Enable auto-approval only for deterministic, well-validated types where manual review is unnecessary.

**Benefits**:
- Reduce manual approval overhead for safe operations
- Faster processing for trusted/validated types
- Configurable per order type

**Implementation**:
```php
// In AbstractOrderType
protected bool $autoApprove = false;

public function shouldAutoApprove(): bool
{
    return $this->autoApprove;
}

// In WorkExecutor::checkAutoApproval()
if ($policy->readyForApproval($order) && $orderType->shouldAutoApprove()) {
    $this->approve($order, ActorType::SYSTEM, null);
}

// In your order type
protected bool $autoApprove = true; // For safe, deterministic types
```

---

### 3. Concurrency Governance & Rate Limiting

**Current State**: Basic lease-based concurrency per item.

**Recommendation**: Add per-type and per-agent concurrency limits and rate limiting.

**Benefits**:
- Prevent resource exhaustion
- Fair distribution across agents
- Cost control for external API calls
- Tenant isolation in multi-tenant scenarios

**Implementation**:
```php
// config/work-manager.php
'concurrency' => [
    'max_leases_per_agent' => 10,
    'max_leases_per_type' => 50,
    'rate_limits' => [
        'agent' => ['max' => 100, 'per' => '1m'],
        'type' => ['max' => 500, 'per' => '1m'],
    ],
],

// LeaseService with Redis token bucket
public function acquire(int $itemId, string $agentId): WorkItem
{
    // Check agent concurrency
    if ($this->getAgentLeaseCount($agentId) >= config('work-manager.concurrency.max_leases_per_agent')) {
        throw new TooManyConcurrentLeasesException();
    }
    
    // Check type concurrency
    $item = WorkItem::findOrFail($itemId);
    if ($this->getTypeLeaseCount($item->type) >= config('work-manager.concurrency.max_leases_per_type')) {
        throw new TypeConcurrencyLimitException();
    }
    
    // Proceed with lease...
}
```

---

### 4. Redis Lease Backend

**Current State**: Database-backed leasing with row-level locks.

**Recommendation**: Implement Redis-backed lease mechanism for higher throughput.

**Benefits**:
- Reduced database contention
- Faster lease operations
- Better horizontal scaling
- Native TTL support

**Implementation**:
```php
// Contracts/LeaseBackend.php (already exists)
interface LeaseBackend
{
    public function acquire(string $key, string $owner, int $ttl): bool;
    public function extend(string $key, string $owner, int $ttl): bool;
    public function release(string $key, string $owner): bool;
    public function reclaim(array $expiredKeys): int;
}

// Services/Backends/RedisLeaseBackend.php
class RedisLeaseBackend implements LeaseBackend
{
    public function acquire(string $key, string $owner, int $ttl): bool
    {
        // SET NX EX pattern
        return Redis::set("lease:{$key}", $owner, 'EX', $ttl, 'NX');
    }
    
    public function extend(string $key, string $owner, int $ttl): bool
    {
        // Verify ownership then extend
        if (Redis::get("lease:{$key}") === $owner) {
            return Redis::expire("lease:{$key}", $ttl);
        }
        return false;
    }
}

// config/work-manager.php
'lease' => [
    'backend' => 'redis', // or 'database'
],
```

---

### 5. Event Outbox Pattern

**Current State**: Direct Laravel event dispatching.

**Recommendation**: Implement outbox pattern for at-least-once delivery guarantees to external systems.

**Benefits**:
- Reliable event delivery
- Decouples database from external systems
- Supports Kafka/SNS/SQS integration
- Transaction-safe event publishing

**Implementation**:
```php
// Migration: add outbox table
Schema::create('work_event_outbox', function (Blueprint $table) {
    $table->id();
    $table->string('event_type');
    $table->json('payload');
    $table->timestamp('created_at');
    $table->timestamp('published_at')->nullable();
    $table->index(['published_at', 'created_at']);
});

// In StateMachine::recordOrderEvent()
DB::transaction(function () use ($order, $event, ...) {
    // Record event
    $workEvent = WorkEvent::create([...]);
    
    // Add to outbox
    EventOutbox::create([
        'event_type' => $event->value,
        'payload' => [
            'order_id' => $order->id,
            'event_id' => $workEvent->id,
            // ... event data
        ],
    ]);
});

// Scheduled job to process outbox
public function handle()
{
    EventOutbox::whereNull('published_at')
        ->oldest()
        ->chunk(100, function ($events) {
            foreach ($events as $event) {
                $this->publisher->publish($event);
                $event->update(['published_at' => now()]);
            }
        });
}
```

---

### 6. Multi-Tenancy Support

**Current State**: Global work orders across all users/teams.

**Recommendation**: Add first-class multi-tenancy with data isolation.

**Benefits**:
- SaaS-ready architecture
- Tenant data isolation
- Per-tenant quotas and limits
- Simplified hosting for multiple customers

**Implementation**:
```php
// Migration: add tenant_id
Schema::table('work_orders', function (Blueprint $table) {
    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
    $table->index('tenant_id');
});

Schema::table('work_items', function (Blueprint $table) {
    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
    $table->index('tenant_id');
});

// Global scope for automatic filtering
class WorkOrder extends Model
{
    protected static function booted()
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (auth()->check() && auth()->user()->tenant_id) {
                $builder->where('tenant_id', auth()->user()->tenant_id);
            }
        });
    }
}

// In WorkOrderPolicy
public function propose(?User $user): bool
{
    // Check tenant quotas
    $tenantOrders = WorkOrder::withoutGlobalScope('tenant')
        ->where('tenant_id', $user->tenant_id)
        ->where('created_at', '>=', now()->subMonth())
        ->count();
    
    return $tenantOrders < $user->tenant->order_quota;
}
```

---

### 7. Evidence & Provenance Standards

**Current State**: Open-ended evidence structure in submissions.

**Recommendation**: Standardize evidence format and auto-enrich provenance.

**Benefits**:
- Consistent evidence quality
- Better auditability
- Compliance-ready (SOC 2, GDPR)
- Automated provenance capture

**Implementation**:
```php
// Evidence schema standard
{
    "url": "https://...",
    "retrieved_at": "2025-01-15T10:30:00Z",
    "retrieved_via": "agent_browser|api|scraper",
    "quote": "...",
    "source_name": "...",
    "source_type": "official|research|news|community",
    "credibility": "high|medium|low",
    "license": "public|fair_use|proprietary",
    "hash": "sha256:...", // Content hash for verification
}

// ProvenanceEnricher implementation
class ProvenanceEnricher implements ProvenanceEnricherContract
{
    public function enrich(Request $request, array $context = []): array
    {
        return [
            'agent_id' => $request->header('X-Agent-ID'),
            'agent_name' => $request->header('X-Agent-Name'),
            'agent_version' => $request->header('X-Agent-Version'),
            'model_name' => $request->header('X-Model-Name'),
            'runtime' => $request->header('X-Runtime'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->header('X-Request-ID'),
            'fingerprint' => $this->generateFingerprint($request),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

---

### 8. Observability & Metrics

**Current State**: Metrics configuration exists but no implementations.

**Recommendation**: Implement Prometheus metrics and OpenTelemetry tracing.

**Benefits**:
- Production-ready monitoring
- Performance insights
- Proactive alerting
- Distributed tracing

**Implementation**:
```php
// composer require prometheus/client_php
// composer require open-telemetry/sdk

// Services/Metrics/PrometheusDriver.php
class PrometheusDriver implements MetricsDriver
{
    public function recordOrderCreated(WorkOrder $order): void
    {
        $this->counter('work_orders_created_total', [
            'type' => $order->type,
            'priority' => $order->priority,
        ])->inc();
    }
    
    public function recordLeaseAcquired(WorkItem $item): void
    {
        $this->counter('work_items_leased_total', [
            'type' => $item->type,
        ])->inc();
        
        $this->gauge('work_items_leased_active', [
            'type' => $item->type,
        ])->set($this->getActiveLeaseCount($item->type));
    }
    
    public function recordApplyDuration(WorkOrder $order, float $duration): void
    {
        $this->histogram('work_order_apply_duration_seconds', [
            'type' => $order->type,
        ])->observe($duration);
    }
}

// Add to WorkExecutor
public function apply(WorkOrder $order, ?OrderType $orderType = null): Diff
{
    $startTime = microtime(true);
    
    try {
        $diff = /* ... existing logic ... */;
        
        $duration = microtime(true) - $startTime;
        $this->metrics->recordApplyDuration($order, $duration);
        
        return $diff;
    } catch (\Exception $e) {
        $this->metrics->recordApplyFailure($order, $e);
        throw $e;
    }
}

// Expose Prometheus metrics endpoint
Route::get('/metrics', function () {
    $registry = app(CollectorRegistry::class);
    $renderer = new RenderTextFormat();
    return response($renderer->render($registry->getMetricFamilySamples()))
        ->header('Content-Type', RenderTextFormat::MIME_TYPE);
})->middleware('auth:metrics');
```

---

### 9. Safety & Compliance Guardrails

**Current State**: Basic validation and policies.

**Recommendation**: Enhanced safety checks and compliance tooling.

**Benefits**:
- PII/secret protection
- Compliance readiness (GDPR, SOC 2)
- Audit trail integrity
- Safe agent behavior

**Implementation**:
```php
// Middleware for legacy route protection
Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);

// PII/Secret detection in event serialization
class WorkEvent extends Model
{
    protected $casts = [
        'payload' => 'encrypted:array', // Encrypt at rest
    ];
    
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Redact sensitive patterns
        $array['payload'] = $this->redactSensitive($array['payload']);
        $array['diff'] = $this->redactSensitive($array['diff'] ?? []);
        
        return $array;
    }
    
    protected function redactSensitive($data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Redact keys matching sensitive patterns
                if (preg_match('/(password|secret|token|key|ssn|credit_card)/i', $key)) {
                    $data[$key] = '[REDACTED]';
                } else {
                    $data[$key] = $this->redactSensitive($value);
                }
            }
        }
        
        return $data;
    }
}

// Agent behavior validation
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Verify agents respect robots.txt
    foreach ($result['evidence'] ?? [] as $evidence) {
        $domain = parse_url($evidence['url'], PHP_URL_HOST);
        
        if (!$this->isAllowedByRobotsTxt($domain, $evidence['url'])) {
            throw ValidationException::withMessages([
                'evidence.url' => ["Agent violated robots.txt for {$domain}"],
            ]);
        }
    }
    
    // Log accessed domains for audit
    WorkProvenance::create([
        'order_id' => $item->order_id,
        'item_id' => $item->id,
        'accessed_domains' => collect($result['evidence'] ?? [])
            ->pluck('url')
            ->map(fn($url) => parse_url($url, PHP_URL_HOST))
            ->unique()
            ->values()
            ->toArray(),
    ]);
}
```

---

### 10. Enhanced Failure Handling

**Current State**: Basic retry with max_attempts and dead-lettering.

**Recommendation**: Sophisticated failure classification and recovery strategies.

**Benefits**:
- Better retry logic (exponential backoff with jitter)
- Transient vs permanent failure distinction
- Dead-letter queue with requeue capability
- Failure pattern analysis

**Implementation**:
```php
// Enhanced error classification
class WorkItemFailure extends Model
{
    protected $casts = [
        'error' => 'array',
        'is_transient' => 'boolean',
        'retry_after' => 'datetime',
    ];
}

// In WorkExecutor::fail()
public function fail(WorkItem $item, array $error, bool $isTransient = false): WorkItem
{
    return DB::transaction(function () use ($item, $error, $isTransient) {
        $item->state = ItemState::FAILED;
        $item->error = $error;
        $item->save();
        
        WorkItemFailure::create([
            'item_id' => $item->id,
            'error' => $error,
            'is_transient' => $isTransient,
            'retry_after' => $isTransient 
                ? now()->addSeconds($this->calculateBackoff($item->attempt_count))
                : null,
        ]);
        
        // Transient failures: retry with backoff
        if ($isTransient && $item->attempt_count < $item->max_attempts) {
            $this->scheduleRetry($item);
        } else {
            // Permanent or exhausted: dead letter
            $this->deadLetter($item, $error);
        }
        
        return $item;
    });
}

// Admin action to requeue from dead letter
public function requeueDeadLetter(WorkItem $item, string $reason): void
{
    if ($item->state !== ItemState::DEAD_LETTERED) {
        throw new \Exception('Item is not dead-lettered');
    }
    
    DB::transaction(function () use ($item, $reason) {
        $item->state = ItemState::QUEUED;
        $item->attempt_count = 0;
        $item->error = null;
        $item->save();
        
        $this->stateMachine->recordItemEvent(
            $item,
            EventType::REQUEUED,
            ActorType::USER,
            auth()->id(),
            ['reason' => $reason],
            'Item requeued from dead letter'
        );
    });
}
```

---

## Deployment Checklist

When deploying to production, ensure:

- [ ] **Authentication configured** (Sanctum, Passport, or custom guard)
- [ ] **Policies enforced** on all protected endpoints
- [ ] **Idempotency enforced** on mutating operations
- [ ] **Rate limiting** configured for agent requests
- [ ] **Redis configured** for lease backend (if using)
- [ ] **Scheduled commands** registered in Kernel
- [ ] **Monitoring/alerting** set up for queue health
- [ ] **Database indexes** created (check migration)
- [ ] **Backup strategy** for work_events and work_orders
- [ ] **Tenant isolation** if multi-tenant (global scopes)
- [ ] **EnforceWorkOrderOnly middleware** applied to legacy routes
- [ ] **MCP server** secured (authentication, rate limits)
- [ ] **Documentation** reviewed by team
- [ ] **Load testing** completed for expected throughput
- [ ] **Failure recovery** procedures documented

---

## Scaling Patterns

### Horizontal Scaling

The system is designed for horizontal scaling:

**Web/API Tier**:
- Stateless controllers enable load balancing
- Redis-backed leases eliminate DB bottleneck
- Idempotency prevents duplicate processing across instances

**Queue/Worker Tier**:
- `work-manager:maintain` can run on multiple nodes
- Lease reclaim is idempotent and distributed
- Queue workers scale independently

**Database Tier**:
- Read replicas for order/item queries
- Write master for mutations
- Partitioning by tenant_id or created_at for large deployments

### Vertical Scaling

For single-instance deployments:

- Increase PHP workers (FPM pool size)
- Increase database connections
- Increase Redis memory for lease backend
- Tune TTL/heartbeat based on agent workload

### Performance Targets

Recommended targets for production:

- **Lease acquisition**: < 50ms (p99)
- **Submit validation**: < 200ms (p99)
- **Apply execution**: < 5s (p99, depends on business logic)
- **Heartbeat processing**: < 20ms (p99)
- **Order proposal**: < 100ms (p99)

Monitor these via metrics and adjust infrastructure accordingly.

---

## Future Roadmap

Potential enhancements for future versions:

1. **GraphQL API** alongside REST for flexible agent queries
2. **WebSocket support** for real-time order/item status updates
3. **Plugin system** for third-party order types and backends
4. **Admin UI** for order management, metrics dashboards, dead-letter requeue
5. **Agent SDK** (Python, JavaScript) for easier integration
6. **Workflow orchestration** (sequential orders, dependencies, conditional branches)
7. **Cost tracking** (API calls, compute time) per order/agent
8. **A/B testing framework** for order type variations
9. **Simulation mode** for testing types without side effects
10. **Time-travel debugging** (replay order history)

---

## References

- [Use Cases Documentation](USE_CASES.md) - Concrete examples and patterns
- [MCP Server Guide](MCP_SERVER.md) - Agent integration via MCP
- [Lifecycle Hooks](../examples/LIFECYCLE.md) - Complete hook documentation
- [Quick Start](../examples/QUICK_START.md) - 5-minute getting started guide
- [Example Order Types](../examples/) - CustomerResearch, ContentFactCheck, CityTierGeneration

---

For questions, issues, or contributions, please visit:
https://github.com/gregpriday/laravel-work-manager
