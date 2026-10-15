<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SinaSaeedi\RuntimeMcp\RuntimeMcpServiceProvider;

$packageConfig = dirname(__DIR__, 2).'/config/runtime-mcp.php';

it('registers the service provider', function () {
    expect(app()->getProvider(RuntimeMcpServiceProvider::class))->not->toBeNull();
});

it('merges the package config', function () use ($packageConfig) {
    expect(config('runtime-mcp'))->toBe(require $packageConfig);
});

it('has exactly the top-level keys of spec 4.5, in order', function () {
    $config = config('runtime-mcp');
    assert(is_array($config));

    expect(array_keys($config))->toBe([
        'enabled', 'path', 'middleware', 'allowed_hosts', 'allowed_origins', 'auth', 'environments',
        'rate_limits', 'output', 'read_only_transactions', 'database', 'models', 'queue', 'logs',
        'redaction', 'audit', 'plugins', 'tools', 'wrapped_tools', 'prompts', 'catalog',
    ]);
});

it('is off by default and denies the eight tables of spec 4.5', function () {
    expect(config('runtime-mcp.enabled'))->toBeFalse()
        ->and(config('runtime-mcp.database.denied_tables'))->toBe([
            'password_reset_tokens', 'personal_access_tokens', 'sessions', 'oauth_access_tokens',
            'jobs', 'job_batches', 'cache', 'cache_locks',
        ]);
});

it('publishes the config file with the runtime-mcp-config tag', function () use ($packageConfig) {
    // The file lands in the Testbench skeleton, and a file left there replaces the package config
    // in every later boot. So delete it before and after.
    $published = config_path('runtime-mcp.php');
    @unlink($published);

    try {
        Artisan::call('vendor:publish', ['--tag' => 'runtime-mcp-config']);

        expect($published)->toBeFile()
            ->and(file_get_contents($published))->toBe(file_get_contents($packageConfig));
    } finally {
        @unlink($published);
    }
});
