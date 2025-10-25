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

        // Concurrency limits (optional)
        'max_leases_per_agent' => env('WORK_MANAGER_MAX_LEASES_PER_AGENT', null),
        'max_leases_per_type' => env('WORK_MANAGER_MAX_LEASES_PER_TYPE', null),
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
        'enforce_on' => ['submit', 'propose', 'approve', 'reject', 'submit-part', 'finalize'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Partial Submissions Configuration
    |--------------------------------------------------------------------------
    |
    | Configure partial submission settings including limits and validation.
    | Partial submissions allow agents to incrementally build up work item
    | results by submitting parts that are validated and assembled.
    |
    */
    'partials' => [
        'enabled' => true,
        'max_parts_per_item' => env('WORK_MANAGER_MAX_PARTS_PER_ITEM', 100),
        'max_payload_bytes' => env('WORK_MANAGER_MAX_PART_PAYLOAD_BYTES', 1048576), // 1MB default
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
    | Policies Configuration
    |--------------------------------------------------------------------------
    |
    | Map package abilities to your application's gates/permissions.
    | These are used for authorization checks.
    |
    */
    'policies' => [
        'propose' => 'work.propose',
        'view' => 'work.view',           // Permission to view orders created by others
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

    /*
    |--------------------------------------------------------------------------
    | Query & Filtering Configuration
    |--------------------------------------------------------------------------
    |
    | Configure default pagination and filtering settings for listing orders.
    | These apply to both HTTP API and MCP tool queries.
    |
    */
    'query' => [
        'default_page_size_http' => 50,
        'default_page_size_mcp' => 20,
        'max_page_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the MCP (Model Context Protocol) server settings.
    | The MCP server exposes work manager tools to AI agents.
    |
    */
    'mcp' => [
        // HTTP transport authentication
        'http' => [
            // Enable Bearer token authentication for HTTP transport
            // IMPORTANT: Set to true in production for security
            'auth_enabled' => env('WORK_MANAGER_MCP_HTTP_AUTH', true),

            // Laravel auth guard to use for token validation
            'auth_guard' => env('WORK_MANAGER_MCP_AUTH_GUARD', 'sanctum'),

            // Optional: Static tokens for simple authentication (comma-separated in env)
            // Use this for development or simple setups without Sanctum
            'static_tokens' => array_filter(
                explode(',', env('WORK_MANAGER_MCP_STATIC_TOKENS', ''))
            ),

            // CORS settings for HTTP transport
            'cors' => [
                'enabled' => env('WORK_MANAGER_MCP_CORS', true),
                // IMPORTANT: Set specific origins in production (e.g., 'https://yourdomain.com')
                // Use '*' only for local development
                'allowed_origins' => env('WORK_MANAGER_MCP_CORS_ORIGINS', '*'),
                'allowed_methods' => 'GET,POST,OPTIONS',
                'allowed_headers' => 'Content-Type,Authorization,Mcp-Session-Id',
            ],
        ],
    ],
];
