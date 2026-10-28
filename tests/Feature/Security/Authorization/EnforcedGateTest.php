<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\CheckGateStage;

/*
 * Spec 11.2 "Authorization": the Gate enforced in the pipeline even when shouldRegister() is
 * bypassed. Stage 2 (CheckGateStage) denies, and the denial passes out through redaction and the
 * audit. Our tools/call handler (01-09) finds a hidden tool, so a client reaches stage 2 too.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(function () {
    app()->forgetInstance('mcp.request');
    AuditLog::clear();
});

/** @return array<array-key, mixed> the only audit entry */
function enforcedGateEntry(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);

    return $entries[0];
}

/** @return array<array-key, mixed> the result of a direct ToolInvoker call of the echo adapter */
function enforcedGateInvoke(): array
{
    $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => 'echo', 'arguments' => ['text' => 'direct']]);
    app()->instance('mcp.request', $request->toRequest());

    $response = (new ToolInvoker)->invoke(new McpToolAdapter(new EchoTool, app()), $request);
    assert($response instanceof JsonRpcResponse);
    $result = $response->toArray()['result'] ?? null;
    assert(is_array($result));

    return $result;
}

it('denies and audits a tools/call of a hidden tool over HTTP', function (ProtocolEra $era) {
    FixtureTools::serve(gates: false);
    Event::fake([ToolDenied::class]);
    Gate::define('runtime-mcp.echo', fn (): bool => false);

    $response = FixtureTools::call('echo', ['text' => 'hi'], $era);
    $entry = enforcedGateEntry();

    expect(FixtureTools::result($response)['isError'])->toBeTrue()
        ->and(FixtureTools::envelope($response))->toBe([
            'environment' => 'testing',
            'status' => 'unavailable',
            'note' => 'Access denied: the Gate `runtime-mcp.echo` does not allow this principal.',
            'truncated' => false,
            'data' => null,
        ])
        ->and($entry['tool'])->toBe('echo')
        ->and($entry['decision'])->toBe('denied')
        ->and($entry['reason'])->toBe('gate_denied')
        ->and($entry['outcome'])->toBeNull()
        ->and($entry['principal'])->toBe('tests')
        ->and($entry['params'])->toBe(['text' => 'hi']);

    Event::assertDispatched(ToolDenied::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('denies no_gate with its own note when the app defines no Gate', function (ProtocolEra $era) {
    FixtureTools::serve(gates: false);

    $response = FixtureTools::call('echo', ['text' => 'hi'], $era);

    expect(FixtureTools::envelope($response)['note'])
        ->toBe('Access denied: the app defines no Gate `runtime-mcp.echo`, so this tool is off (default deny).')
        ->and(enforcedGateEntry()['reason'])->toBe('no_gate');
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('adds the Gate\'s own message to the note, cut and redacted', function () {
    FixtureTools::serve(gates: false);
    Gate::define('runtime-mcp.echo', fn (): Response => Response::deny('Ask the platform team. Bearer abcdefgh12345678 '.str_repeat('x', 300)));

    $note = FixtureTools::envelope(FixtureTools::call('echo', ['text' => 'hi']))['note'];
    assert(is_string($note));

    expect(str_starts_with($note, 'Access denied: the Gate `runtime-mcp.echo` does not allow this principal. Ask the platform team. Bearer [redacted:bearer] '))->toBeTrue($note)
        ->and(str_contains($note, 'abcdefgh12345678'))->toBeFalse('The secret in the Gate message was not redacted.')
        ->and(mb_strlen($note))->toBe(mb_strlen('Access denied: the Gate `runtime-mcp.echo` does not allow this principal. ') + CheckGateStage::MAX_MESSAGE);
});

it('denies gate_error with a note that names no cause, and warns once', function () {
    FixtureTools::serve(gates: false);
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    $response = FixtureTools::call('echo', ['text' => 'hi']);

    expect(FixtureTools::envelope($response)['note'])
        ->toBe('Access denied: the Gate `runtime-mcp.echo` failed. The server log names the cause.')
        ->and(enforcedGateEntry()['reason'])->toBe('gate_error')
        ->and($log->messages())->toHaveCount(1);
});

it('denies and audits a direct ToolInvoker call, which never asks shouldRegister()', function () {
    app(CurrentPrincipal::class)->set(new Principal('sina', AuthMethod::ApiKey));
    $asked = 0;
    Gate::define('runtime-mcp.echo', function () use (&$asked): bool {
        $asked++;

        return false;
    });

    $result = enforcedGateInvoke();
    $entry = enforcedGateEntry();

    expect($result['isError'])->toBeTrue()
        ->and($asked)->toBe(1) // stage 2 only
        ->and($entry['decision'])->toBe('denied')
        ->and($entry['reason'])->toBe('gate_denied')
        ->and($entry['principal'])->toBe('sina');
});

it('denies and audits the exact call ToolInvoker makes, with no shouldRegister() on the path', function () {
    app(CurrentPrincipal::class)->set(new Principal('sina', AuthMethod::ApiKey));
    app()->instance('mcp.request', (new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => 'echo', 'arguments' => ['text' => 'x']]))->toRequest());

    app()->call([new McpToolAdapter(new EchoTool, app()), 'handle']);

    expect(enforcedGateEntry()['reason'])->toBe('no_gate');
});

it('runs the tool when the Gate allows, and asks it again on the next call', function () {
    FixtureTools::serve(gates: false);
    $open = true;
    Gate::define('runtime-mcp.echo', function () use (&$open): bool {
        return $open;
    });

    $first = FixtureTools::envelope(FixtureTools::call('echo', ['text' => 'hi']));
    $open = false;
    $second = FixtureTools::envelope(FixtureTools::call('echo', ['text' => 'hi']));

    expect($first['status'])->toBe('ok')
        ->and($first['data'])->toBe(['text' => 'hi'])
        ->and($second['status'])->toBe('unavailable');
});

it('redacts the whole Gate message before the cut, so a secret across the cut does not reach the agent in part', function (string $secret, string $marker, string $start) {
    // The secret starts at character 185 of the Gate message, so the 200-character cut splits it.
    FixtureTools::serve(gates: false);
    $prefix = str_repeat('x', 180).' at ';
    Gate::define('runtime-mcp.echo', fn (): Response => Response::deny($prefix.$secret.' and more'));

    $response = FixtureTools::call('echo', ['text' => 'hi']);

    expect(FixtureTools::envelope($response)['note'])->toBe('Access denied: the Gate `runtime-mcp.echo` does not allow this principal. '.mb_substr($prefix.$marker.' and more', 0, CheckGateStage::MAX_MESSAGE))
        ->and(str_contains((string) $response->getContent(), $start))->toBeFalse();
})->with([
    'a password URL' => ['redis://:Secr3tPassw0rdValue@cache.internal:6379', 'redis://:[redacted:password]@cache.internal:6379', 'Secr3t'],
    'a GitHub token' => ['ghp_'.str_repeat('A1b2C3d4E5', 4), '[redacted:github_token]', 'ghp_A1b2'],
]);
