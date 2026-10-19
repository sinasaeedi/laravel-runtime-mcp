<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * Sina, 2026-10-02: with no API keys and a valid stdio_principal, STDIO runs and the HTTP endpoint
 * stays off, in every environment. Every other error still stops both transports.
 */

/** @return array<string, mixed> */
function stdioOnlyConfig(): array
{
    return ['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.auth.stdio_principal' => 'local-dev'];
}

it('runs STDIO alone, with one warning and no HTTP route', function (string $environment) {
    $boot = BootCapture::boot(stdioOnlyConfig(), $environment);

    expect($boot->thrown)->toBeNull()
        ->and($boot->warnings())->toBe(['Runtime MCP: RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `local-dev`.'])
        ->and($boot->criticals())->toBe([])
        ->and($boot->events)->toBe([])
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeCallable();

    currentTest()->mcpPost('initialize')->assertNotFound();
})->with(['production', 'prod-eu', 'local', 'testing']);

it('stops STDIO too when another rule fails: off in a strict environment', function () {
    $boot = BootCapture::boot(
        [...stdioOnlyConfig(), 'runtime-mcp.plugins.fake_optional' => true],
        'production',
        BootCapture::plugins([CorePlugin::class, FakeOptionalPlugin::class]),
    );

    expect($boot->thrown)->toBeNull()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and($boot->criticals())->toHaveCount(1);
});

it('stops STDIO too when another rule fails: a throw in a relaxed environment', function () {
    $boot = BootCapture::boot(
        [...stdioOnlyConfig(), 'runtime-mcp.plugins.fake_optional' => true],
        'local',
        BootCapture::plugins([CorePlugin::class, FakeOptionalPlugin::class]),
    );

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and($boot->thrown?->getMessage())->toContain('[plugins.installed]')
        ->and($boot->thrown?->getMessage())->not->toContain('[api_keys.present]');
});
