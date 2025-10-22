<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the package routes are registered. You can disable
    | auto-registration and manually register routes in your api.php file.
    |
    */
    'routes' => [
        'enabled' => false,          // if true, auto-register default routes under base_path
        'base_path' => 'agent/work', // only used if routes.enabled = true
        'middleware' => ['api'],     // middleware for mounted routes
        'guard' => 'sanctum',        // auth guard name for agent endpoints
    ],

    /*
    |--------------------------------------------------------------------------
    | Lease Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the default lease duration and heartbeat interval for
    | work items. Agents must heartbeat before the lease expires.
    |
    */
    'lease' => [
        'ttl_seconds' => 600,        // default 10 minutes
        'heartbeat_every_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the default retry behavior for failed work items,
    | including backoff and jitter settings.
    |
    */
    'retry' => [
        'default_max_attempts' => 3,
        'backoff_seconds' => 60,
        'jitter_seconds' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency Configuration
    |--------------------------------------------------------------------------
    |
    | Configure idempotency enforcement. The header name and which
    | endpoints require idempotency keys.
    |
    */
    'idempotency' => [
        'header' => 'X-Idempotency-Key',
        'enforce_on' => ['submit', 'propose', 'approve', 'reject'],
    ],

    /*
    |--------------------------------------------------------------------------
    | State Machine Configuration
    |--------------------------------------------------------------------------
    |
    | Define allowed state transitions. This is the default configuration
    | but can be overridden for advanced workflows.
    |
    */
    'state_machine' => [
        'order_transitions' => [
            'queued' => ['checked_out', 'submitted', 'rejected', 'failed'],
            'checked_out' => ['in_progress', 'queued', 'failed'],
            'in_progress' => ['submitted', 'failed', 'queued'],
            'submitted' => ['approved', 'rejected', 'failed'],
            'approved' => ['applied', 'failed'],
            'applied' => ['completed', 'failed'],
            'rejected' => ['queued', 'dead_lettered'],
            'failed' => ['queued', 'dead_lettered'],
            'completed' => [],
            'dead_lettered' => [],
        ],
        'item_transitions' => [
            'queued' => ['leased', 'failed'],
            'leased' => ['in_progress', 'queued', 'failed'],
            'in_progress' => ['submitted', 'failed', 'queued'],
            'submitted' => ['accepted', 'rejected', 'failed'],
            'accepted' => ['completed'],
            'rejected' => ['queued', 'failed'],
            'completed' => [],
            'failed' => ['queued', 'dead_lettered'],
            'dead_lettered' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which queue connection and queue names to use for
    | background jobs.
    |
    */
    'queues' => [
        'connection' => env('WORK_MANAGER_QUEUE_CONNECTION', 'redis'),
        'maintenance_queue' => 'work:maintenance',
        'planning_queue' => 'work:planning',
        'agent_job_queue_prefix' => 'agents:', // e.g. agents:research
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Configure metrics collection and reporting. Supports multiple
    | drivers for different monitoring systems.
    |
    */
    'metrics' => [
        'enabled' => true,
        'driver' => 'log', // 'prometheus', 'statsd', 'log'
        'namespace' => 'work_manager',
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies Configuration
    |--------------------------------------------------------------------------
    |
    | Map package abilities to your application's gates/permissions.
    | These are used for authorization checks.
    |
    */
    'policies' => [
        'propose' => 'work.propose',
        'checkout' => 'work.checkout',
        'submit' => 'work.submit',
        'approve' => 'work.approve',
        'reject' => 'work.reject',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Configuration
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for maintenance tasks like dead-lettering
    | and alerting on stale work orders.
    |
    */
    'maintenance' => [
        'dead_letter_after_hours' => 48,
        'stale_order_threshold_hours' => 24,
        'enable_alerts' => true,
    ],
];
