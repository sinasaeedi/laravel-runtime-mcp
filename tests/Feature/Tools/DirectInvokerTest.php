<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;

/*
 * Rule 3: the pipeline lives in the tool, not in HTTP middleware. A direct ToolInvoker call (no
 * HTTP, no middleware) still runs it. laravel/mcp fills the Request from the mcp.request binding,
 * as Server::runMethodHandle() does, so the test binds it too.
 */

beforeEach(function () {
    AuditLog::clear();
    Gates::allowFixtureTools(); // default deny (01-09)
});
afterEach(function () {
    app()->forgetInstance('mcp.request');
    AuditLog::clear();
});

/** @return array<array-key, mixed> the result of one direct invoke() of the echo adapter */
function directInvokerCall(bool $bindRequest = true): array
{
    $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => 'echo', 'arguments' => ['text' => 'direct']]);

    if ($bindRequest) {
        app()->instance('mcp.request', $request->toRequest());
    }

    $response = (new ToolInvoker)->invoke(new McpToolAdapter(new EchoTool, app()), $request);
    assert($response instanceof JsonRpcResponse);
    $result = $response->toArray()['result'] ?? null;
    assert(is_array($result));

    return $result;
}

/**
 * @param  array<array-key, mixed>  $result
 * @return array<array-key, mixed>
 */
function directInvokerEnvelope(array $result): array
{
    $envelope = $result['structuredContent'] ?? null;
    assert(is_array($envelope));

    return $envelope;
}

it('denies and audits a call with no principal, with no middleware in the way', function () {
    $result = directInvokerCall();
    $entries = AuditLog::entries();

    expect($result['isError'])->toBeTrue()
        ->and($result['structuredContent'])->toBe([
            'environment' => 'testing',
            'status' => 'unavailable',
            'note' => 'No authenticated principal.',
            'truncated' => false,
            'data' => null,
        ])
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['decision'])->toBe('denied')
        ->and($entries[0]['reason'])->toBe('no_principal')
        ->and($entries[0]['outcome'])->toBeNull()
        ->and($entries[0]['params'])->toBe(['text' => 'direct']);
});

it('runs the tool for a bound principal', function () {
    app(CurrentPrincipal::class)->set(new Principal('sina', AuthMethod::ApiKey));

    $result = directInvokerCall();

    expect($result['isError'])->toBeFalse()
        ->and(directInvokerEnvelope($result)['data'])->toBe(['text' => 'direct'])
        ->and(AuditLog::entries()[0]['principal'] ?? null)->toBe('sina');
});

it('gives the tool no arguments when mcp.request is not bound (the trap)', function () {
    app(CurrentPrincipal::class)->set(new Principal('sina', AuthMethod::ApiKey));

    expect(directInvokerEnvelope(directInvokerCall(bindRequest: false))['note'])
        ->toBe('Invalid input: The text field is required.');
});

it('records the user id of a linked principal', function () {
    app(CurrentPrincipal::class)->set(new Principal('sina', AuthMethod::ApiKey, new GenericUser(['id' => 42])));

    directInvokerCall();

    expect(AuditLog::entries()[0]['user_id'] ?? null)->toBe(42);
});

it('records no IP for a STDIO principal', function () {
    app(CurrentPrincipal::class)->set(new Principal('cli', AuthMethod::Stdio));

    directInvokerCall();

    $entry = AuditLog::entries()[0];

    expect(array_key_exists('ip', $entry))->toBeTrue()
        ->and($entry['ip'])->toBeNull()
        ->and($entry['auth_method'])->toBe('stdio');
});
