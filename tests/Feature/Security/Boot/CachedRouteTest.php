<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootReport;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;

/*
 * With route:cache, the route exists even though McpRoutes::register() skipped it. Then
 * EnsureEndpointEnabled is the second lock: it reads BootState on every request.
 */

/** A passing boot whose route answers. In each test, not in beforeEach: a failure in a hook stops PHPUnit's JUnit log. */
function cachedRouteServe(): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    currentTest()->mcpPost('initialize')->assertOk();
}

it('answers 404 on a registered route when the boot report failed', function () {
    cachedRouteServe();
    app(BootState::class)->record(new BootReport([BootIssue::error('api_keys.present', 'x')], true, true));

    $response = currentTest()->mcpPost('initialize')->assertNotFound();
    expect(Route::has('runtime-mcp'))->toBeTrue()
        ->and($response->json('jsonrpc'))->toBeNull();
});

it('answers 404 when the report passed but does not allow HTTP (STDIO only)', function () {
    cachedRouteServe();
    app(BootState::class)->record(new BootReport([BootIssue::warning('api_keys.present', 'x')], false, true));

    currentTest()->mcpPost('initialize')->assertNotFound();
});

it('answers 404 when BootState holds no report', function () {
    cachedRouteServe();
    app()->instance(BootState::class, new BootState);

    currentTest()->mcpPost('initialize')->assertNotFound();
});

it('keeps answering when the report allows HTTP', function () {
    cachedRouteServe();
    app(BootState::class)->record(new BootReport([BootIssue::warning('database.connection', 'x')], true, false));

    currentTest()->mcpPost('initialize')->assertOk();
});

it('keeps the report for every request of one worker (Octane)', function () {
    cachedRouteServe();

    // Octane forgets the scoped instances between requests; BootState is a singleton, so it stays.
    app()->forgetScopedInstances();

    currentTest()->mcpPost('initialize')->assertOk();
});
