<?php

declare(strict_types=1);

use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\CounterNotStored;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Cache\ThrowingStore;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\CheckGateStage;

/*
 * 01-17 Part D (Sina, 2026-10-28): past a threshold of denied calls per principal per minute, a denied
 * entry is still written, but without params. Spec 5.7 holds; one key cannot fill the disk.
 */

/** @param  array<string, mixed>  $config */
function deniedCallsServe(int $threshold = 3, array $config = []): void
{
    AuditLog::clear();
    FixtureTools::serve($config, gates: false);
    app()->scoped(AuditLogger::class, fn (Application $app): AuditLogger => new AuditLogger($app, $app->make(Repository::class), $threshold));
    Exceptions::fake();
    currentTest()->freezeTime();
}

/** @return array<array-key, mixed> the envelope */
function deniedCallsEcho(string $tool = 'echo'): array
{
    return FixtureTools::envelope(FixtureTools::call($tool, ['text' => 'hello']));
}

/** @return list<mixed> the params of every audit entry, in order */
function deniedCallsParams(): array
{
    return array_map(fn (array $entry): mixed => $entry['params'] ?? null, AuditLog::entries());
}

/** @return array{_omitted: string} */
function deniedCallsMarker(int $threshold): array
{
    return ['_omitted' => sprintf(AuditLogger::PARAMS_OMITTED, $threshold)];
}

afterEach(fn () => AuditLog::clear());

it('LD1 keeps the params of the first denied entries and drops them past the threshold', function () {
    deniedCallsServe();
    Event::fake([ToolDenied::class]);

    $envelopes = array_map(fn (): array => deniedCallsEcho(), range(1, 5));
    $entries = AuditLog::entries();
    $records = AuditLog::records();

    expect(deniedCallsParams())->toBe([['text' => 'hello'], ['text' => 'hello'], ['text' => 'hello'], deniedCallsMarker(3), deniedCallsMarker(3)])
        ->and(deniedCallsMarker(3))->toBe(['_omitted' => '[params omitted: more than 3 denied calls in 1 minute]'])
        ->and(array_column($entries, 'reason'))->toBe(array_fill(0, 5, 'no_gate'))
        ->and(array_column($entries, 'decision'))->toBe(array_fill(0, 5, 'denied'))
        ->and(array_column($records, 'level_name'))->toBe(array_fill(0, 5, 'WARNING'))
        // The agent sees the same denial before and after the threshold.
        ->and($envelopes[4])->toBe($envelopes[0])
        ->and($envelopes[0]['note'])->toBe(sprintf(CheckGateStage::NO_GATE, 'runtime-mcp.echo'));
    Event::assertDispatched(ToolDenied::class, 5);
});

it('LD2 counts calls to an unknown tool', function () {
    deniedCallsServe(2);

    deniedCallsEcho();
    currentTest()->mcpPost('tools/call', ['name' => 'nope', 'arguments' => (object) ['q' => 'x']]);
    currentTest()->mcpPost('tools/call', ['name' => 'nope', 'arguments' => (object) ['q' => 'y']]);
    $entries = AuditLog::entries();

    expect(deniedCallsParams())->toBe([['text' => 'hello'], ['q' => 'x'], deniedCallsMarker(2)])
        ->and($entries[2]['reason'])->toBe('unknown_tool')
        ->and($entries[2]['tool'])->toBe('nope');
});

it('LD3 counts calls that the rate limit denied', function () {
    deniedCallsServe(2, ['runtime-mcp.rate_limits.per_principal' => '1,1']);
    Gates::allow('echo');

    array_map(fn (): array => deniedCallsEcho(), range(1, 4));
    $entries = AuditLog::entries();

    expect(array_map(fn (array $entry): mixed => $entry['reason'], $entries))->toBe([null, 'rate_limited', 'rate_limited', 'rate_limited'])
        ->and(deniedCallsParams())->toBe([['text' => 'hello'], ['text' => 'hello'], ['text' => 'hello'], deniedCallsMarker(2)]);
});

it('LD4 never counts or changes an allowed call', function () {
    deniedCallsServe(1);
    Gates::allow('echo');

    deniedCallsEcho('second_echo');
    deniedCallsEcho('second_echo');
    deniedCallsEcho();

    expect(deniedCallsParams())->toBe([['text' => 'hello'], deniedCallsMarker(1), ['text' => 'hello']])
        ->and(app(RateLimiter::class)->attempts(AuditLogger::deniedKey('testing', 'tests')))->toBe(2);
});

it('LD5 writes the short entry when the counter cache is down, and the denial is unchanged', function () {
    deniedCallsServe();
    ThrowingStore::limiter();

    $envelope = deniedCallsEcho();

    expect(deniedCallsParams())->toBe([deniedCallsMarker(3)])
        ->and($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe(sprintf(CheckGateStage::NO_GATE, 'runtime-mcp.echo'));
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === ThrowingStore::MESSAGE);
});

it('LD6 uses 120 per minute per principal and environment by default, and starts again after a minute', function () {
    currentTest()->bootWith();
    AuditLog::clear();
    currentTest()->freezeTime();
    $audit = app(AuditLogger::class);
    $deny = fn (?string $principal, int $n) => $audit->toolCall(AuditEntry::toolCall(environment: 'testing', tool: 'echo', params: ['n' => $n], allowed: false, reason: Reason::NoGate, principal: $principal));

    foreach (range(1, 121) as $n) {
        $deny('tests', $n);
    }

    $deny('other', 122);
    $deny(null, 123);
    $none = app(RateLimiter::class)->attempts('runtime-mcp:testing:denied:[none]');
    currentTest()->travel(61)->seconds();
    $deny('tests', 124);
    $params = deniedCallsParams();

    expect(AuditLogger::DENIED_CALLS_PER_MINUTE)->toBe(120)
        ->and($params[119])->toBe(['n' => 120])
        ->and($params[120])->toBe(deniedCallsMarker(120))
        ->and($params[121])->toBe(['n' => 122])
        ->and($params[122])->toBe(['n' => 123])
        ->and($params[123])->toBe(['n' => 124])
        ->and(AuditLogger::deniedKey('testing', 'tests'))->toBe('runtime-mcp:testing:denied:tests')
        ->and(AuditLogger::deniedKey('testing', null))->toBe('runtime-mcp:testing:denied:[none]')
        ->and($none)->toBe(1);
});

it('LD7 writes the short entry when the counter cache loses the count without an error', function () {
    deniedCallsServe();
    // The null store, or a Memcached server that is down: no exception, and hit() counts 0.
    app()->instance(RateLimiter::class, new RateLimiter(new CacheRepository(new NullStore)));

    $envelope = deniedCallsEcho();

    expect(deniedCallsParams())->toBe([deniedCallsMarker(3)])
        ->and($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe(sprintf(CheckGateStage::NO_GATE, 'runtime-mcp.echo'));
    Exceptions::assertReported(CounterNotStored::class);
});
