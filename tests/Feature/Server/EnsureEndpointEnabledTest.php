<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('closes a registered route when the endpoint is turned off later (a cached route)', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    currentTest()->mcpPost('initialize')->assertOk();

    config(['runtime-mcp.enabled' => false]);

    $response = currentTest()->mcpPost('initialize')->assertNotFound();
    expect(Route::has('runtime-mcp'))->toBeTrue()
        ->and($response->json('jsonrpc'))->toBeNull();
});

it('answers a closed endpoint exactly as a path with no route', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => false, 'app.debug' => false]);
    $missing = currentTest()->mcpPost('initialize')->assertNotFound();

    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'app.debug' => false]);
    config(['runtime-mcp.enabled' => false]);
    $closed = currentTest()->mcpPost('initialize')->assertNotFound();

    expect(Route::has('runtime-mcp'))->toBeTrue()
        ->and($missing->json('message'))->toBe('The route mcp could not be found.')
        ->and($closed->json())->toBe($missing->json());
});

it('treats a non-boolean enabled value as off', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    config(['runtime-mcp.enabled' => 'true']);

    currentTest()->mcpPost('initialize')->assertNotFound();
});
