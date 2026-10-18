<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\StdioTransport;
use SinaSaeedi\RuntimeMcp\Mcp\McpServer;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\LinkedUserMissing;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;

/*
 * Never call the registered closure or run mcp:start with a registered server: StdioTransport::run()
 * blocks on STDIN. Building McpServer with a StdioTransport touches no stream.
 */

it('does not start the STDIO server without a valid stdio_principal', function (mixed $name) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.stdio_principal' => $name]);

    expect(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(Artisan::call('mcp:start', ['handle' => 'runtime-mcp']))->toBe(1)
        ->and(Artisan::output())->toContain('MCP Server with name [runtime-mcp] not found');
})->with([
    'unset' => null,
    'empty' => '',
    'space' => 'local dev',
    'trailing newline' => "local-dev\n",
    'not a string' => [['local-dev']],
]);

it('registers the STDIO server for a valid stdio_principal, without API keys', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
        'runtime-mcp.auth.api_keys' => null,
    ]);

    expect(Mcp::getLocalServer('runtime-mcp'))->toBeCallable();
});

it('needs the endpoint to be enabled', function () {
    currentTest()->bootWith(['runtime-mcp.auth.stdio_principal' => 'local-dev']);

    expect(Mcp::getLocalServer('runtime-mcp'))->toBeNull();
});

it('registers the STDIO server even when an HTTP setting is bad', function (string $key, mixed $value) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.stdio_principal' => 'local-dev', $key => $value]);

    expect(Mcp::getLocalServer('runtime-mcp'))->toBeCallable()
        ->and(Route::has('runtime-mcp'))->toBeFalse();
})->with([
    'bad path' => ['runtime-mcp.path', '/'],
    'bad middleware' => ['runtime-mcp.middleware', 'not-a-list'],
]);

it('runs as stdio_principal when built with a StdioTransport', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.stdio_principal' => 'local-dev']);

    app()->make(McpServer::class, ['transport' => new StdioTransport]);

    $principal = app(CurrentPrincipal::class)->get();
    expect($principal?->name)->toBe('local-dev')
        ->and($principal?->method)->toBe(AuthMethod::Stdio)
        ->and($principal?->user)->toBeNull();
});

it('applies link_users to the STDIO principal', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
        'runtime-mcp.auth.link_users' => ['local-dev' => 'dev@example.com'],
    ]);
    $user = Users::create('dev@example.com');

    app()->make(McpServer::class, ['transport' => new StdioTransport]);

    expect(app(CurrentPrincipal::class)->get()?->user?->getAuthIdentifier())->toBe($user->getAuthIdentifier());
});

it('refuses to build a STDIO server whose linked user is missing', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
        'runtime-mcp.auth.link_users' => ['local-dev' => 'dev@example.com'],
    ]);
    Users::create('other@example.com');

    expect(fn () => app()->make(McpServer::class, ['transport' => new StdioTransport]))
        ->toThrow(LinkedUserMissing::class);
    expect(app(CurrentPrincipal::class)->get())->toBeNull();
});

it('refuses to build a STDIO server when stdio_principal is gone (a cached registration)', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.stdio_principal' => 'local-dev']);
    config(['runtime-mcp.auth.stdio_principal' => null]);

    expect(fn () => app()->make(McpServer::class, ['transport' => new StdioTransport]))
        ->toThrow(LogicException::class, 'The STDIO server needs a valid auth.stdio_principal.');
    expect(app(CurrentPrincipal::class)->get())->toBeNull();
});

it('sets no principal for another transport', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.stdio_principal' => 'local-dev']);

    app()->make(McpServer::class, ['transport' => new FakeTransporter]);

    expect(app(CurrentPrincipal::class)->get())->toBeNull();
});
