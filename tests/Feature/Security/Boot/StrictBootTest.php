<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 11.2 "Boot validation: every rule in section 5.11, in a strict … environment", and
 * "Environments: an unknown environment name gets the strict rules". Rule 15: never a throw.
 */

/**
 * Each failing config, with a valid STDIO principal where the rule allows it, so "nothing is
 * registered" also proves that STDIO stays off. plugins.installed needs a fixture plugin.
 *
 * @return array<string, array{string, array<string, mixed>, bool}>
 */
function strictBootCases(): array
{
    $principal = ['runtime-mcp.auth.stdio_principal' => 'local-dev'];

    return [
        'api_keys.present' => ['api_keys.present', ['runtime-mcp.auth.api_keys' => null], false],
        'api_keys.format' => ['api_keys.format', ['runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().',sina:abc', ...$principal], false],
        'database.connection unset' => ['database.connection', ['runtime-mcp.database.connection' => null, ...$principal], false],
        'database.connection is the default' => ['database.connection', ['runtime-mcp.database.connection' => 'testing', ...$principal], false],
        'plugins.installed' => ['plugins.installed', ['runtime-mcp.plugins.fake_optional' => true, ...$principal], true],
    ];
}

dataset('strict boot cases', strictBootCases());

it('keeps both transports off, logs one critical line and dispatches one event, and never throws', function (string $rule, array $config, bool $optionalPlugin, string $environment) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot(
        $config,
        $environment,
        $optionalPlugin ? BootCapture::plugins([CorePlugin::class, ModelsPlugin::class, DatabasePlugin::class, FakeOptionalPlugin::class]) : null,
    );

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and($boot->events)->toHaveCount(1);

    $event = $boot->events[0];
    expect($event->environment)->toBe($environment)
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $event->issues))->toContain($rule);

    currentTest()->mcpPost('initialize')->assertNotFound();
})->with('strict boot cases')->with(['production', 'prod-eu']);

it('puts the environment and every issue in the critical line context, with no secret', function () {
    $entry = 'sina:'.str_repeat('f', 63);
    $boot = BootCapture::boot([
        'runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().','.$entry,
        'runtime-mcp.database.connection' => null,
    ], 'production');

    $critical = array_values(array_filter($boot->log->records, fn (array $record): bool => $record['level'] === 'critical'));

    expect($critical)->toHaveCount(1)
        ->and($critical[0]['context'] ?? null)->toBe([
            'environment' => 'production',
            'issues' => [
                ['rule' => 'api_keys.format', 'severity' => 'error', 'message' => 'RUNTIME_MCP_API_KEYS entry 2 is not in the name:sha256hex format.'],
                ['rule' => 'database.connection', 'severity' => 'error', 'message' => 'RUNTIME_MCP_DB_CONNECTION is not set. The `models` and `database` plugins need a separate read-only connection.'],
            ],
        ])
        ->and(str_contains((string) json_encode($boot->log->records), $entry))->toBeFalse();
});

it('passes every issue, warnings too, in the event', function () {
    $boot = BootCapture::boot([
        'runtime-mcp.auth.api_keys' => null,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
        'runtime-mcp.database.connection' => 'testing',
    ], 'staging');

    expect(array_map(fn (BootIssue $issue): array => $issue->toArray(), $boot->events[0]->issues ?? []))->toBe([
        ['rule' => 'api_keys.present', 'severity' => 'warning', 'message' => 'RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `local-dev`.'],
        ['rule' => 'database.connection', 'severity' => 'error', 'message' => 'RUNTIME_MCP_DB_CONNECTION names `testing`, the default connection. The `models` and `database` plugins need a separate read-only connection.'],
    ])
        ->and($boot->warnings())->toBe(['Runtime MCP: RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `local-dev`.'])
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull();
});

it('treats an environment that is not in environments.relaxed as strict', function () {
    $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.environments.relaxed' => ['dev']], 'local');

    expect($boot->thrown)->toBeNull()
        ->and($boot->criticals())->toHaveCount(1)
        ->and(Route::has('runtime-mcp'))->toBeFalse();
});

it('validates nothing and logs nothing while the endpoint is disabled', function () {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => false, 'runtime-mcp.auth.api_keys' => null, 'runtime-mcp.database.connection' => null], 'production');

    expect($boot->thrown)->toBeNull()
        ->and($boot->log->records)->toBe([])
        ->and($boot->events)->toBe([])
        ->and(app(BootState::class)->report())->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse();
});

it('registers the endpoint and logs nothing when every rule passes', function () {
    $boot = BootCapture::boot(['runtime-mcp.auth.stdio_principal' => 'local-dev'], 'production');

    expect($boot->log->records)->toBe([])
        ->and($boot->events)->toBe([])
        ->and(Route::has('runtime-mcp'))->toBeTrue()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeCallable();

    currentTest()->mcpPost('initialize')->assertOk();
});
