<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Mcp\Server\Middleware\ValidateMcpHeaders;
use SinaSaeedi\RuntimeMcp\Mcp\Http\EnsureEndpointEnabled;
use SinaSaeedi\RuntimeMcp\Mcp\McpServer;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateApiKey;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Answer401;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\PassThrough;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

it('registers nothing while the endpoint is off (the default)', function () {
    $response = currentTest()->mcpPost('initialize')->assertNotFound();

    expect(Route::has('runtime-mcp'))->toBeFalse()
        ->and($response->json('jsonrpc'))->toBeNull();
});

it('registers the route at the configured path', function (string $path, string $uri) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.path' => $path]);

    $route = Route::getRoutes()->getByName('runtime-mcp');

    expect($route)->toBeInstanceOf(RoutingRoute::class)
        ->and($route?->uri())->toBe($uri);
    currentTest()->mcpPost('initialize', path: $path)->assertOk();
})->with([
    'default' => ['/mcp', 'mcp'],
    'custom' => ['/internal/mcp', 'internal/mcp'],
]);

it('answers 405 to GET and DELETE with a key', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    $key = ['Authorization' => 'Bearer '.TestCase::TEST_KEY];

    currentTest()->get('/mcp', $key)->assertStatus(405)->assertHeader('Allow', 'POST');
    currentTest()->delete('/mcp', [], $key)->assertStatus(405)->assertHeader('Allow', 'POST');
});

// 01-06: GET and DELETE sit in the same route group, so auth runs first.
it('answers 401 to GET and DELETE without a key', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    AuditLog::clear();

    try {
        currentTest()->get('/mcp')->assertUnauthorized();
        currentTest()->delete('/mcp')->assertUnauthorized();
    } finally {
        AuditLog::clear();
    }
});

it('registers nothing for a bad path or middleware entry (fail closed)', function (string $key, mixed $value) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, $key => $value]);

    expect(Route::has('runtime-mcp'))->toBeFalse();
})->with([
    'path is not a string' => ['runtime-mcp.path', ['/mcp']],
    'path is empty' => ['runtime-mcp.path', '/'],
    'middleware is a string' => ['runtime-mcp.middleware', PassThrough::class],
    'middleware holds a non-string' => ['runtime-mcp.middleware', [PassThrough::class, 1]],
    'middleware is a map' => ['runtime-mcp.middleware', ['ip' => PassThrough::class]],
]);

it('runs our middleware before laravel/mcp and drops AddWwwAuthenticateHeader', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.middleware' => [PassThrough::class]]);

    $route = Route::getRoutes()->getByName('runtime-mcp');
    assert($route instanceof RoutingRoute);

    expect(app('router')->gatherRouteMiddleware($route))->toBe([
        EnsureEndpointEnabled::class,
        PassThrough::class,
        AuthenticateApiKey::class,
        ReorderJsonAccept::class,
        ValidateMcpHeaders::class,
    ]);
});

it('sends no laravel/mcp WWW-Authenticate header, also when the app uses Mcp::oauthRoutes()', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.middleware' => [Answer401::class]]);

    Mcp::oauthRoutes();
    Mcp::web('/control-mcp', McpServer::class)->middleware(Answer401::class);
    app('router')->getRoutes()->refreshNameLookups();

    // Control: the OAuth branch is live, so the assertion below can fail.
    expect(Route::has('mcp.oauth.protected-resource.nested'))->toBeTrue();
    $control = currentTest()->mcpPost('initialize', path: '/control-mcp')->assertUnauthorized();
    expect($control->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');

    currentTest()->mcpPost('initialize')->assertUnauthorized()->assertHeaderMissing('WWW-Authenticate');
});
