<?php

declare(strict_types=1);

return [
    // Spec 4.5, 5.4. Used first by 01-02 (server registration) and 01-10 (boot).
    'enabled' => env('RUNTIME_MCP_ENABLED', false),
    // Spec 4.5, 5.4. Used first by 01-02 (server registration) and 01-10 (boot).
    'path' => '/mcp',
    // Spec 4.5, 5.4. Used first by 01-02 (server registration) and 01-10 (boot).
    'middleware' => [], // extra route middleware, for example an IP allow-list or a VPN-only check
    // Spec 5.4. Used first by 01-07.
    'allowed_hosts' => null, // null = the host of config('app.url')
    // Spec 5.4. Used first by 01-07.
    'allowed_origins' => [], // a request with an Origin header must match one of these

    // Spec 5.3. Used first by 01-06 (api_key, stdio_principal, link_users) and 01-35 (sanctum).
    'auth' => [
        'driver' => 'api_key', // 'api_key' or 'sanctum' (section 5.3); 'passport' from v1.12.0
        // api_key driver only:
        // "name:sha256hex,name:sha256hex". Each server has its own .env, so its own keys.
        'api_keys' => env('RUNTIME_MCP_API_KEYS'),
        // api_key driver only. Optional: key name => user email. A linked key acts as that User (section 5.3).
        'link_users' => [],
        // The principal name for the STDIO transport. Unset = the STDIO server does not start.
        'stdio_principal' => env('RUNTIME_MCP_STDIO_PRINCIPAL'),
    ],

    // Spec 4.6. Used first by 01-02.
    'environments' => [
        'relaxed' => ['local', 'testing'], // every other environment is strict
    ],

    // Spec 5.8. Used first by 01-17.
    'rate_limits' => [
        'per_principal' => '120,1', // agents send 10 to 20 queries in a burst
        'per_tool' => ['db_query' => '60,1'],
    ],

    // Spec 5.8. Used first by 01-17.
    'output' => [
        'max_bytes' => 100_000,
    ],

    // Spec 5.9. Used first by 01-16.
    'read_only_transactions' => [
        'connections' => null, // null = the default connection
    ],

    // Spec 5.1, 5.2. Used first by 01-11 (grants), 01-14 (timeout), 01-15 (max_rows) and 01-10 (connection boot rule).
    'database' => [
        'connection' => env('RUNTIME_MCP_DB_CONNECTION'), // the read-only connection
        'statement_timeout_ms' => 5000,
        'max_rows' => 500,
        // `install` prints the grants from these two lists. `doctor` checks them.
        'denied_tables' => [
            'password_reset_tokens', 'personal_access_tokens', 'sessions', 'oauth_access_tokens',
            'jobs', 'job_batches', 'cache', 'cache_locks', // Sina, 2026-10-02: serialized job data and cached values
        ],
        // Secrets, and any PII that the agent must not see.
        'masked_columns' => ['password', 'remember_token', '*token*', '*secret*', '*api_key*'],
    ],

    // Spec 6.2. Used first by 01-21.
    'models' => [
        // alias => model settings
        // 'order' => ['class' => App\Models\Order::class, 'relations' => ['items', 'customer'], 'appends' => []],
    ],

    // Spec 6.5. Used first by 01-22.
    'queue' => [
        'names' => null, // null = the default queue of each connection
    ],

    // Spec 6.9. Used first by 01-25.
    'logs' => [
        'channels' => ['stack'],
        'max_bytes' => 200_000,
    ],

    // Spec 5.6. Used first by 01-04.
    'redaction' => [
        'extra_patterns' => [], // user patterns, added to the built-in ones
    ],

    // Spec 5.7. Used first by 01-05.
    'audit' => [
        'channel' => 'runtime-mcp', // registered by the package as a daily channel if the app has none
        'days' => 90,
    ],

    // Spec 4.2. Used first by 01-03.
    'plugins' => [
        'core' => true,
        'models' => true,
        'database' => true,
        'queue' => true,
        'logs' => true,
        // Later releases add their plugins here, off by default.
    ],

    // Spec 7.4. Used first by 01-29.
    // Your own tools (php artisan runtime-mcp:make-tool). Each extends RuntimeTool (Sina, 2026-10-02).
    'tools' => [],

    // Spec 5.16. Used first by 01-38.
    // Official laravel/mcp tools that run behind the guard pipeline (section 5.16).
    // ['class' => App\Mcp\Tools\OrderLookup::class, 'name' => 'app_order_lookup', 'tier' => 'read_sensitive', 'open_world' => false]
    'wrapped_tools' => [],

    // Spec 5.14. Used first by 01-36.
    // Your own prompts. Each extends RuntimePrompt (section 5.14).
    'prompts' => [],

    // Spec 5.17. Used first by 01-40.
    'catalog' => [
        'enabled' => false, // true = every tool sits behind search_tools and execute_tools (section 5.17)
    ],
];
