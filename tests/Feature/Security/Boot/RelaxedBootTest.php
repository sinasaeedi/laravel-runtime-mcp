<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 11.2 "Boot validation: every rule in section 5.11, … and in a relaxed environment", spec 4.6:
 * a failed rule throws at boot, except database.connection unset or default, which only warns.
 * After a throw, read only the BootCapture: the app did not finish its boot.
 */

/** @return array<string, array{string, array<string, mixed>, bool}> */
function relaxedBootErrorCases(): array
{
    return [
        'api_keys.present' => ['api_keys.present', ['runtime-mcp.auth.api_keys' => null], false],
        'api_keys.format' => ['api_keys.format', ['runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().',sina:abc'], false],
        'plugins.installed' => ['plugins.installed', ['runtime-mcp.plugins.fake_optional' => true], true],
    ];
}

it('throws RuntimeMcpMisconfigured at boot for an error rule', function (string $rule, array $config, bool $optionalPlugin, string $environment) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot(
        $config,
        $environment,
        $optionalPlugin ? BootCapture::plugins([CorePlugin::class, ModelsPlugin::class, DatabasePlugin::class, FakeOptionalPlugin::class]) : null,
    );

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and($boot->thrown?->getMessage())->toContain("in the `{$environment}` environment:")
        ->and($boot->thrown?->getMessage())->toContain("- [{$rule}] ")
        ->and($boot->criticals())->toBe([])
        ->and($boot->events)->toBe([]);
})->with(relaxedBootErrorCases())->with(['local', 'testing']);

it('lists every error in the one exception, and no key text', function () {
    $entry = 'sina:'.str_repeat('f', 63);
    $boot = BootCapture::boot(
        ['runtime-mcp.auth.api_keys' => $entry, 'runtime-mcp.plugins.fake_optional' => true],
        'local',
        BootCapture::plugins([CorePlugin::class, FakeOptionalPlugin::class]),
    );

    $message = $boot->thrown?->getMessage() ?? '';

    expect($message)->toBe(<<<'TEXT'
        The Runtime MCP security config is not valid in the `local` environment:
        - [api_keys.format] RUNTIME_MCP_API_KEYS entry 1 is not in the name:sha256hex format.
        - [plugins.installed] Plugin `fake_optional` is enabled, but `acme/not-installed` is not installed.
        Fix these, or set RUNTIME_MCP_ENABLED=false. Strict environments keep the endpoint off instead of throwing.
        TEXT)
        ->and(str_contains($message, $entry))->toBeFalse();
});

it('only warns, and registers the endpoint, when database.connection is unset or the default', function (mixed $connection, string $message) {
    $boot = BootCapture::boot(['runtime-mcp.database.connection' => $connection], 'local');

    expect($boot->thrown)->toBeNull()
        ->and($boot->warnings())->toBe(["Runtime MCP: {$message}"])
        ->and($boot->criticals())->toBe([])
        ->and($boot->events)->toBe([])
        ->and(Route::has('runtime-mcp'))->toBeTrue();

    currentTest()->mcpPost('initialize')->assertOk();
})->with([
    'unset' => [null, 'RUNTIME_MCP_DB_CONNECTION is not set. The `models` and `database` plugins need a separate read-only connection.'],
    'the default' => ['testing', 'RUNTIME_MCP_DB_CONNECTION names `testing`, the default connection. The `models` and `database` plugins need a separate read-only connection.'],
]);

it('writes the warnings before it throws for an error', function () {
    $boot = BootCapture::boot(['runtime-mcp.database.connection' => null, 'runtime-mcp.auth.api_keys' => null], 'local');

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and($boot->warnings())->toHaveCount(1)
        ->and($boot->thrown?->getMessage())->not->toContain('database.connection');
});

it('follows environments.relaxed: a listed name throws', function () {
    $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.environments.relaxed' => ['dev']], 'dev');

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class);
});
