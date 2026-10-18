<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\Methods\CallTool as LaravelCallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Mcp\Methods\CallTool;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * Spec 5.7: every tool call is audited, also a call to an unknown tool. Our tools/call handler
 * (01-09) answers with laravel/mcp's own JSON-RPC error and writes one denied entry: unknown_tool.
 * laravel/mcp's HttpTransport sends a JSON-RPC error with code -32602 as HTTP 400
 * (HttpTransport::statusCode()).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @return array{message: string, code: int} the JSON-RPC error a handler throws for this request */
function unknownToolError(object $handler, JsonRpcRequest $request): array
{
    assert($handler instanceof CallTool || $handler instanceof LaravelCallTool);
    $context = new ServerContext(
        supportedProtocolVersions: [], serverCapabilities: [], implementation: new Implementation('t', '1'),
        instructions: '', maxPaginationLength: 50, defaultPaginationLength: 15, tools: [], resources: [], prompts: [],
    );

    try {
        $handler->handle($request, $context);
    } catch (JsonRpcException $e) {
        $error = $e->toJsonRpcResponse()->toArray()['error'] ?? null;
        assert(is_array($error) && is_string($error['message'] ?? null) && is_int($error['code'] ?? null));

        return ['message' => $error['message'], 'code' => $error['code']];
    }

    throw new LogicException('The handler did not throw.');
}

function unknownToolRequest(mixed $name): JsonRpcRequest
{
    return new JsonRpcRequest(id: 7, method: 'tools/call', params: ['name' => $name, 'arguments' => ['q' => 1]]);
}

it('answers an unknown name with the JSON-RPC error of laravel/mcp, and audits it', function (ProtocolEra $era) {
    FixtureTools::serve();
    Event::fake([ToolDenied::class]);

    $response = currentTest()->mcpPost('tools/call', ['name' => 'nope_tool', 'arguments' => ['q' => 'x']], era: $era)->assertStatus(400);
    $entries = AuditLog::entries();

    expect($response->json('error.code'))->toBe(-32602)
        ->and($response->json('error.message'))->toBe('Tool [nope_tool] not found.')
        ->and($response->json('result'))->toBeNull()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['kind'])->toBe('tool_call')
        ->and($entries[0]['tool'])->toBe('nope_tool')
        ->and($entries[0]['params'])->toBe(['q' => 'x'])
        ->and($entries[0]['decision'])->toBe('denied')
        ->and($entries[0]['reason'])->toBe('unknown_tool')
        ->and($entries[0]['outcome'])->toBeNull()
        ->and($entries[0]['principal'])->toBe('tests')
        ->and($entries[0]['auth_method'])->toBe('api_key')
        ->and($entries[0]['ip'])->toBe('127.0.0.1')
        ->and($entries[0]['environment'])->toBe('testing')
        ->and($entries[0]['client'])->toBe($era === ProtocolEra::Modern ? ['name' => 'runtime-mcp-tests', 'version' => '1.0.0'] : null);

    Event::assertDispatched(ToolDenied::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('gives exactly the text and code of laravel/mcp\'s own handler', function (mixed $name) {
    expect(unknownToolError(app(CallTool::class), unknownToolRequest($name)))
        ->toBe(unknownToolError(new LaravelCallTool, unknownToolRequest($name)));
})->with(['nope_tool', 'db_query']);

it('keeps the whole name in the error and cuts it to 64 characters in the audit', function () {
    FixtureTools::serve();
    $name = str_repeat('a', 100);

    currentTest()->mcpPost('tools/call', ['name' => $name, 'arguments' => (object) []])
        ->assertStatus(400)
        ->assertJsonPath('error.message', "Tool [{$name}] not found.");

    expect(AuditLog::entries()[0]['tool'] ?? null)->toBe(str_repeat('a', 64));
});

it('refuses a call without a name as laravel/mcp does, with no audit entry', function () {
    FixtureTools::serve();

    currentTest()->mcpPost('tools/call', ['arguments' => (object) []])
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Missing [name] parameter.');

    expect(AuditLog::entries())->toBe([]);
});

it('shows a name that is not a string by its type, and audits it', function () {
    FixtureTools::serve();

    // No arguments: the params of the audit entry are then []. (laravel/mcp refuses arguments that
    // are not an object before any handler runs: JsonRpcRequest::toRequest().)
    currentTest()->mcpPost('tools/call', ['name' => ['echo']])
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool [array] not found.');

    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['tool'])->toBe('array')
        ->and($entries[0]['params'])->toBe([]);
});

it('still answers not found, and reports why, when the audit cannot be written', function () {
    FixtureTools::serve(['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log', // the parent is a file: no directory can be made
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]]);
    Exceptions::fake();

    currentTest()->mcpPost('tools/call', ['name' => 'nope_tool', 'arguments' => (object) []])
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool [nope_tool] not found.');

    Exceptions::assertReported(AuditUnavailable::class);
});

it('records no IP for a STDIO principal, and no principal when none is set', function () {
    app(CurrentPrincipal::class)->set(new Principal('cli', AuthMethod::Stdio));
    unknownToolError(app(CallTool::class), unknownToolRequest('nope_tool'));

    app()->forgetScopedInstances();
    unknownToolError(app(CallTool::class), unknownToolRequest('nope_tool'));

    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['principal'])->toBe('cli')
        ->and($entries[0]['auth_method'])->toBe('stdio')
        ->and(array_key_exists('ip', $entries[0]))->toBeTrue()
        ->and($entries[0]['ip'])->toBeNull()
        ->and($entries[1]['principal'])->toBeNull()
        ->and($entries[1]['ip'])->toBe('127.0.0.1');
});

it('finds a registered tool that the Gate hides, and lets the pipeline deny it', function () {
    FixtureTools::serve(gates: false);

    $response = FixtureTools::call('echo', ['text' => 'hi']);

    expect(FixtureTools::envelope($response)['status'])->toBe('unavailable')
        ->and(AuditLog::entries()[0]['reason'] ?? null)->toBe('no_gate');
});
