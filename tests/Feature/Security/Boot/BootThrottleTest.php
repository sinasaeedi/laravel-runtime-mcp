<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use SinaSaeedi\RuntimeMcp\Audit\Events\BootValidationFailed;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootThrottle;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\ThrowingLogger;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Under PHP-FPM, boot runs on every request: the critical line, the event and the warnings go out at
 * most once per 10 minutes per set of rule ids. Each boot here is a new app, as each FPM request is;
 * the throttle files persist on disk between them (TestCase clears them per test).
 */

it('writes the critical line and dispatches the event once for three boots with the same failure', function () {
    $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'production');
    currentTest()->rebootApplication();
    currentTest()->rebootApplication();

    expect($boot->criticals())->toHaveCount(1)
        ->and($boot->events)->toHaveCount(1)
        // Every boot still keeps the endpoint off.
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and(Route::has('runtime-mcp'))->toBeFalse();
});

it('reports again when the set of failing rules changes', function () {
    $first = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'production');
    $second = BootCapture::boot(['runtime-mcp.database.connection' => null], 'production');
    $both = BootCapture::boot(['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.database.connection' => null], 'production');
    $firstAgain = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'production');

    expect($first->criticals())->toHaveCount(1)
        ->and($second->criticals())->toHaveCount(1)
        ->and($both->criticals())->toHaveCount(1)
        ->and($firstAgain->criticals())->toBe([])
        ->and($firstAgain->events)->toBe([]);
});

it('throttles the warnings the same way, apart from the critical line', function () {
    $boot = BootCapture::boot(['runtime-mcp.database.connection' => null], 'local');
    currentTest()->rebootApplication();
    currentTest()->rebootApplication();

    expect($boot->warnings())->toHaveCount(1);

    // A failure with the same warning: the critical line is a separate report.
    $failure = BootCapture::boot([
        'runtime-mcp.auth.api_keys' => null,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
        'runtime-mcp.database.connection' => null,
    ], 'production');

    expect($failure->criticals())->toHaveCount(1)
        ->and($failure->warnings())->toHaveCount(1);
});

it('keeps its files in the package directory under storage, never in the app cache store', function () {
    BootCapture::boot(['runtime-mcp.auth.api_keys' => null, 'cache.default' => 'array'], 'production');

    expect(File::allFiles(storage_path(BootThrottle::DIRECTORY)))->toHaveCount(1)
        ->and(BootThrottle::SECONDS)->toBe(600);
});

it('never throttles the relaxed throw', function () {
    $first = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'local');
    $second = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'local');

    expect($first->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and($second->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class);
});

it('still reports on every boot when the throttle store cannot write', function () {
    // A plain file where the directory should be: the file store throws.
    File::ensureDirectoryExists(dirname(storage_path(BootThrottle::DIRECTORY)));
    File::put(storage_path(BootThrottle::DIRECTORY), 'not a directory');

    $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'production');
    currentTest()->rebootApplication();

    expect($boot->criticals())->toHaveCount(2)
        ->and($boot->events)->toHaveCount(2);
});

it('does not throw in a strict environment when the log or a listener throws (rule 15)', function () {
    $broken = function (Application $app): void {
        $app->instance(LoggerInterface::class, new ThrowingLogger);
        $app->make(Dispatcher::class)->listen(BootValidationFailed::class, function (): void {
            throw new RuntimeException('The pager is down.');
        });
    };

    $boot = BootCapture::boot([
        'runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().',x',
        'runtime-mcp.database.connection' => null,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
    ], 'production', $broken);

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        // The capture's listener ran before the broken one.
        ->and($boot->events)->toHaveCount(1);

    currentTest()->mcpPost('initialize')->assertNotFound();
});
