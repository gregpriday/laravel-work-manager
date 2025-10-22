# Exceptions Reference

Complete documentation of all custom exceptions thrown by Laravel Work Manager.

## Table of Contents

- [Exception Hierarchy](#exception-hierarchy)
- [Base Exception](#base-exception)
- [State Machine Exceptions](#state-machine-exceptions)
- [Lease Exceptions](#lease-exceptions)
- [Idempotency Exceptions](#idempotency-exceptions)
- [Registry Exceptions](#registry-exceptions)
- [Security Exceptions](#security-exceptions)
- [Handling Exceptions](#handling-exceptions)
- [HTTP Status Codes](#http-status-codes)

---

## Exception Hierarchy

All package exceptions extend the base `WorkManagerException`, which extends PHP's standard `Exception`.

```
Exception (PHP)
└── WorkManagerException (GregPriday\WorkManager\Exceptions)
    ├── IllegalStateTransitionException
    ├── LeaseConflictException
    ├── LeaseExpiredException
    ├── IdempotencyConflictException
    ├── OrderTypeNotFoundException
    └── ForbiddenDirectMutationException
```

This hierarchy allows you to catch all package exceptions with a single catch block or handle specific exceptions individually.

---

## Base Exception

### WorkManagerException

**Namespace:** `GregPriday\WorkManager\Exceptions\WorkManagerException`

**Description:** Base exception class for all Work Manager exceptions.

**Extends:** `Exception`

**Properties:** None (inherits from `Exception`)

**Usage:**

Catch all package exceptions:

```php
use GregPriday\WorkManager\Exceptions\WorkManagerException;

try {
    WorkManager::allocator()->propose('invalid.type', []);
} catch (WorkManagerException $e) {
    // Handle any Work Manager exception
    Log::error('Work Manager error', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
}
```

---

## State Machine Exceptions

### IllegalStateTransitionException

**Namespace:** `GregPriday\WorkManager\Exceptions\IllegalStateTransitionException`

**Description:** Thrown when attempting an illegal state transition that violates the configured state machine rules.

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $from,      // Current state
    string $to,        // Attempted new state
    string $entityType = 'order'  // 'order' or 'item'
)
```

**Public Properties:** None (all data in message)

**When Thrown:**
- Attempting to transition a work order to a state not allowed by `config/work-manager.php`
- Attempting to transition a work item to a state not allowed by configuration
- Manually calling `StateMachine::transitionOrder()` or `StateMachine::transitionItem()` with invalid state

**HTTP Status Code:** 409 Conflict

**Example Message:**
```
Illegal state transition for order: cannot transition from 'queued' to 'applied'
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\IllegalStateTransitionException;

try {
    // Attempt to skip approval and go directly to applied
    $stateMachine->transitionOrder(
        $order,
        OrderState::APPLIED,
        ActorType::SYSTEM,
        null
    );
} catch (IllegalStateTransitionException $e) {
    Log::error('Invalid state transition', [
        'message' => $e->getMessage(),
        // "Illegal state transition for order: cannot transition from 'submitted' to 'applied'"
    ]);

    return response()->json([
        'error' => 'Cannot apply order without approval',
    ], 409);
}
```

**Prevention:**

Check if transition is allowed before attempting:

```php
if ($order->state->canTransitionTo(OrderState::APPLIED)) {
    $stateMachine->transitionOrder($order, OrderState::APPLIED, ...);
} else {
    // Handle invalid transition gracefully
}
```

---

## Lease Exceptions

### LeaseConflictException

**Namespace:** `GregPriday\WorkManager\Exceptions\LeaseConflictException`

**Description:** Thrown when attempting to acquire or modify a lease that is already held by another agent or in an incompatible state.

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $message = 'Work item is already leased by another agent'
)
```

**Public Properties:** None

**When Thrown:**
- Attempting to acquire a lease on an already-leased work item
- Attempting to extend/release a lease owned by a different agent
- Attempting to lease a work item in a non-leasable state (e.g., completed)

**HTTP Status Code:** 409 Conflict

**Example Messages:**
```
Work item is already leased by another agent
This item is leased by a different agent
Item is not in a leasable state
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\LeaseConflictException;

try {
    $item = $leaseService->acquire($itemId, $agentId);
} catch (LeaseConflictException $e) {
    Log::warning('Lease conflict', [
        'item_id' => $itemId,
        'agent_id' => $agentId,
        'message' => $e->getMessage(),
    ]);

    return response()->json([
        'error' => [
            'code' => 'lease_conflict',
            'message' => $e->getMessage(),
        ],
    ], 409);
}
```

**Prevention:**

Check lease status before attempting operations:

```php
if (!$item->isLeased()) {
    $leaseService->acquire($item->id, $agentId);
} else {
    // Handle already leased
}
```

---

### LeaseExpiredException

**Namespace:** `GregPriday\WorkManager\Exceptions\LeaseExpiredException`

**Description:** Thrown when attempting operations on a work item whose lease has expired.

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $message = 'The lease on this work item has expired'
)
```

**Public Properties:** None

**When Thrown:**
- Attempting to extend an expired lease
- Attempting to submit results with an expired lease
- Attempting to submit parts with an expired lease

**HTTP Status Code:** 409 Conflict

**Example Message:**
```
The lease on this work item has expired
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\LeaseExpiredException;

try {
    $item = $leaseService->extend($itemId, $agentId);
} catch (LeaseExpiredException $e) {
    Log::warning('Lease expired', [
        'item_id' => $itemId,
        'agent_id' => $agentId,
    ]);

    // Agent must re-acquire the lease
    return response()->json([
        'error' => [
            'code' => 'lease_expired',
            'message' => 'Your lease has expired. Please checkout the item again.',
            'action' => 'reacquire',
        ],
    ], 409);
}
```

**Prevention:**

Heartbeat regularly and check expiration before operations:

```php
if ($item->isLeaseExpired()) {
    // Reacquire lease or handle gracefully
} else {
    $executor->submit($item, $result, $agentId);
}
```

**Agent Best Practices:**

1. Heartbeat every `config('work-manager.lease.heartbeat_every_seconds')` seconds
2. Check `lease_expires_at` before long-running operations
3. Handle expiration gracefully by re-acquiring lease

---

## Idempotency Exceptions

### IdempotencyConflictException

**Namespace:** `GregPriday\WorkManager\Exceptions\IdempotencyConflictException`

**Description:** Thrown when an idempotency key conflict is detected (key reused with different request data).

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $message = 'Idempotency key conflict detected',
    public readonly ?array $previousResponse = null
)
```

**Public Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$previousResponse` | array\|null | The cached response from the original request |

**When Thrown:**
- Reusing an idempotency key with different request payload (rare, should not happen in normal use)
- Internal consistency check failure in idempotency service

**HTTP Status Code:** 409 Conflict

**Example Message:**
```
Idempotency key conflict detected
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\IdempotencyConflictException;

try {
    $result = $idempotency->guard(
        'submit:item:' . $itemId,
        $idempotencyKey,
        fn() => $executor->submit($item, $result, $agentId)
    );
} catch (IdempotencyConflictException $e) {
    Log::error('Idempotency conflict', [
        'key' => $idempotencyKey,
        'previous_response' => $e->previousResponse,
    ]);

    // This typically indicates a bug in the client
    return response()->json([
        'error' => [
            'code' => 'idempotency_conflict',
            'message' => 'Idempotency key was reused with different data',
        ],
    ], 409);
}
```

**Note:** In most cases, duplicate idempotency keys return the cached response successfully. This exception is only thrown when data integrity issues are detected.

---

## Registry Exceptions

### OrderTypeNotFoundException

**Namespace:** `GregPriday\WorkManager\Exceptions\OrderTypeNotFoundException`

**Description:** Thrown when attempting to use an order type that is not registered in the system.

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $type  // The unregistered type identifier
)
```

**Public Properties:** None (type is in message)

**When Thrown:**
- Proposing a work order with an unregistered type
- Manually accessing an unregistered type via `OrderTypeRegistry::get()`

**HTTP Status Code:** 404 Not Found

**Example Message:**
```
Order type 'user.data.sync' is not registered
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\OrderTypeNotFoundException;

try {
    $order = WorkManager::allocator()->propose(
        type: 'nonexistent.type',
        payload: []
    );
} catch (OrderTypeNotFoundException $e) {
    Log::error('Invalid order type', [
        'message' => $e->getMessage(),
    ]);

    return response()->json([
        'error' => [
            'code' => 'order_type_not_found',
            'message' => 'The specified order type does not exist',
            'available_types' => WorkManager::registry()->all(),
        ],
    ], 404);
}
```

**Prevention:**

Check if type is registered before using:

```php
if (WorkManager::registry()->has('user.data.sync')) {
    $order = WorkManager::allocator()->propose('user.data.sync', []);
} else {
    // Handle unregistered type
}
```

**Fix:**

Register the order type in `AppServiceProvider::boot()`:

```php
WorkManager::registry()->register(new UserDataSyncType());
```

---

## Security Exceptions

### ForbiddenDirectMutationException

**Namespace:** `GregPriday\WorkManager\Exceptions\ForbiddenDirectMutationException`

**Description:** Thrown when attempting direct mutations that must go through the work order system.

**Extends:** `WorkManagerException`

**Constructor:**
```php
public function __construct(
    string $message = 'Direct mutations must go through the work order system'
)
```

**Public Properties:** None

**When Thrown:**
- Accessing a route protected by `EnforceWorkOrderOnly` middleware without proper work order context

**HTTP Status Code:** 403 Forbidden

**Example Message:**
```
Direct mutations must go through the work order system
```

**Example:**

```php
use GregPriday\WorkManager\Exceptions\ForbiddenDirectMutationException;
use GregPriday\WorkManager\Http\Middleware\EnforceWorkOrderOnly;

// In routes/api.php
Route::post('/users', [UserController::class, 'store'])
    ->middleware(EnforceWorkOrderOnly::class);

// When accessed directly (without work order context)
try {
    // POST /api/users without X-Work-Order-Context header
} catch (ForbiddenDirectMutationException $e) {
    return response()->json([
        'error' => [
            'code' => 'direct_mutation_forbidden',
            'message' => $e->getMessage(),
            'hint' => 'This operation must be performed through a work order',
        ],
    ], 403);
}
```

**Bypassing for Work Orders:**

Include the work order context header:

```php
// In OrderType::apply() method
$response = Http::withHeaders([
    'X-Work-Order-Context' => $order->id,
])->post('/api/users', $userData);
```

**Usage:**

Apply middleware to routes that should only be accessible via work orders:

```php
Route::middleware([EnforceWorkOrderOnly::class])->group(function () {
    Route::post('/users/bulk-create', ...);
    Route::delete('/users/bulk-delete', ...);
    Route::put('/sensitive-config', ...);
});
```

---

## Handling Exceptions

### Global Exception Handler

Add Work Manager exceptions to your `app/Exceptions/Handler.php`:

```php
use GregPriday\WorkManager\Exceptions\WorkManagerException;
use GregPriday\WorkManager\Exceptions\OrderTypeNotFoundException;
use GregPriday\WorkManager\Exceptions\LeaseConflictException;
use GregPriday\WorkManager\Exceptions\IllegalStateTransitionException;

public function register(): void
{
    $this->reportable(function (WorkManagerException $e) {
        // Log all Work Manager exceptions
        Log::channel('work-manager')->error($e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString(),
        ]);
    });

    $this->renderable(function (OrderTypeNotFoundException $e, $request) {
        return response()->json([
            'error' => [
                'code' => 'order_type_not_found',
                'message' => $e->getMessage(),
            ],
        ], 404);
    });

    $this->renderable(function (LeaseConflictException $e, $request) {
        return response()->json([
            'error' => [
                'code' => 'lease_conflict',
                'message' => $e->getMessage(),
            ],
        ], 409);
    });

    $this->renderable(function (IllegalStateTransitionException $e, $request) {
        return response()->json([
            'error' => [
                'code' => 'illegal_state_transition',
                'message' => $e->getMessage(),
            ],
        ], 409);
    });
}
```

---

### Try-Catch Patterns

**Specific Exception Handling:**

```php
use GregPriday\WorkManager\Exceptions\{
    LeaseConflictException,
    LeaseExpiredException,
    OrderTypeNotFoundException
};

try {
    $item = $leaseService->acquire($itemId, $agentId);
} catch (LeaseConflictException $e) {
    // Item already leased, retry or skip
    return response()->json(['error' => 'Item unavailable'], 409);
} catch (LeaseExpiredException $e) {
    // Lease expired, reacquire
    return response()->json(['error' => 'Lease expired'], 409);
}
```

**Catch All Work Manager Exceptions:**

```php
use GregPriday\WorkManager\Exceptions\WorkManagerException;

try {
    // Work Manager operations
} catch (WorkManagerException $e) {
    Log::error('Work Manager error', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);

    return response()->json([
        'error' => 'An error occurred processing your request',
    ], 500);
}
```

---

### Retrying on Exceptions

**Retry Lease Conflicts:**

```php
use Illuminate\Support\Facades\Retry;
use GregPriday\WorkManager\Exceptions\LeaseConflictException;

$item = Retry::times(3)
    ->sleep(100)  // 100ms between retries
    ->catch(LeaseConflictException::class)
    ->throw()
    ->attempt(fn() => $leaseService->acquire($itemId, $agentId));
```

**Exponential Backoff:**

```php
$item = Retry::times(5)
    ->exponentialBackoff(100)  // Start at 100ms
    ->catch([LeaseConflictException::class, LeaseExpiredException::class])
    ->attempt(fn() => $leaseService->acquire($itemId, $agentId));
```

---

## HTTP Status Codes

Map of exceptions to HTTP status codes for API responses:

| Exception | HTTP Status | Code | Description |
|-----------|-------------|------|-------------|
| `WorkManagerException` | 500 | Internal Server Error | Generic package error |
| `IllegalStateTransitionException` | 409 | Conflict | Invalid state transition |
| `LeaseConflictException` | 409 | Conflict | Lease already held by another agent |
| `LeaseExpiredException` | 409 | Conflict | Lease has expired |
| `IdempotencyConflictException` | 409 | Conflict | Idempotency key conflict |
| `OrderTypeNotFoundException` | 404 | Not Found | Order type not registered |
| `ForbiddenDirectMutationException` | 403 | Forbidden | Direct mutation not allowed |

---

## Exception Context

### Additional Context in Logs

Add context when logging exceptions:

```php
try {
    // Work Manager operation
} catch (WorkManagerException $e) {
    Log::error('Work Manager exception', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'order_id' => $order->id ?? null,
        'item_id' => $item->id ?? null,
        'agent_id' => $agentId ?? null,
        'trace' => $e->getTraceAsString(),
    ]);
}
```

### Monitoring and Alerts

Set up alerts for critical exceptions:

```php
use Illuminate\Support\Facades\Log;

public function register(): void
{
    $this->reportable(function (IllegalStateTransitionException $e) {
        // Alert on state machine violations
        Log::channel('slack')->critical('State machine violation', [
            'message' => $e->getMessage(),
        ]);
    });

    $this->reportable(function (ForbiddenDirectMutationException $e) {
        // Alert on security violations
        Log::channel('security')->alert('Direct mutation attempt', [
            'message' => $e->getMessage(),
            'ip' => request()->ip(),
            'user' => auth()->id(),
        ]);
    });
}
```

---

## Related Documentation

- [API Surface](./api-surface.md) - Complete API reference
- [Routes Reference](./routes-reference.md) - HTTP endpoint documentation
- [Events Reference](./events-reference.md) - Event documentation
- [Config Reference](./config-reference.md) - Configuration options
