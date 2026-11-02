<?php

declare(strict_types=1);

use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\CounterNotStored;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Cache\ThrowingStore;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\AuditStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RateLimitStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ReadOnlyStage;

/*
 * Spec 4.4 step 3, 5.8, 01-17 Part B: the count limits, over HTTP with the fixture tools. Time is frozen,
 * so "Try again in 60 seconds" is exact.
 */

/** A second developer's key (01-06 format). */
const RATE_LIMIT_SECOND_KEY = 'rmcp_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

/** @param  array<string, mixed>  $config */
function rateLimitServe(array $config = [], bool $gates = true): void
{
    AuditLog::clear();
    FixtureTools::serve([
        'runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().',second:'.hash('sha256', RATE_LIMIT_SECOND_KEY),
        ...$config,
    ], gates: $gates);
    Exceptions::fake();
    currentTest()->freezeTime();
}

/** @return array<array-key, mixed> the envelope of one echo call */
function rateLimitEcho(string $tool = 'echo', string $key = TestCase::TEST_KEY): array
{
    $response = currentTest()->mcpPost('tools/call', ['name' => $tool, 'arguments' => (object) ['text' => 'hello']], key: $key)->assertOk();

    return FixtureTools::envelope($response);
}

function rateLimitAttempts(string $key): mixed
{
    return app(RateLimiter::class)->attempts($key);
}

afterEach(fn () => AuditLog::clear());

it('LR1 denies the call past the per-key limit with a note, an audit entry and ToolDenied', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_principal' => '3,1']);
    Event::fake([ToolDenied::class]);

    $allowed = [rateLimitEcho()['status'], rateLimitEcho()['status'], rateLimitEcho()['status']];
    $denied = rateLimitEcho();
    $entries = AuditLog::entries();

    expect($allowed)->toBe(['ok', 'ok', 'ok'])
        ->and($denied['status'])->toBe('unavailable')
        ->and($denied['note'])->toBe('Rate limit reached: 3 calls per minute for this key. Try again in 60 seconds.')
        ->and($entries)->toHaveCount(4)
        ->and($entries[3]['decision'])->toBe('denied')
        ->and($entries[3]['reason'])->toBe('rate_limited')
        ->and($entries[3]['params'])->toBe(['text' => 'hello']);
    Event::assertDispatched(ToolDenied::class, 1);
});

it('LR2 counts each key on its own', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_principal' => '1,1']);

    rateLimitEcho();

    expect(rateLimitEcho()['status'])->toBe('unavailable')
        ->and(rateLimitEcho(key: RATE_LIMIT_SECOND_KEY)['status'])->toBe('ok');
});

it('LR3 allows the call again after the decay', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_principal' => '1,1']);

    rateLimitEcho();
    currentTest()->travel(30)->seconds();
    $early = rateLimitEcho();
    currentTest()->travel(31)->seconds();

    expect($early['note'])->toBe('Rate limit reached: 1 call per minute for this key. Try again in 30 seconds.')
        ->and(rateLimitEcho()['status'])->toBe('ok');
});

it('LR4 applies a per-tool limit to that tool only', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_tool' => ['echo' => '2,5']]);

    $echo = [rateLimitEcho()['status'], rateLimitEcho()['status']];
    $third = rateLimitEcho();

    expect($echo)->toBe(['ok', 'ok'])
        ->and($third['note'])->toBe('Rate limit reached: 2 calls per 5 minutes for echo. Try again in 300 seconds.')
        ->and(rateLimitEcho('second_echo')['status'])->toBe('ok')
        ->and(rateLimitEcho(key: RATE_LIMIT_SECOND_KEY)['status'])->toBe('ok');
});

it('LR5 allows a burst of 20 calls with the defaults', function () {
    rateLimitServe();

    $statuses = array_map(fn (): mixed => rateLimitEcho()['status'], range(1, 20));

    expect($statuses)->toBe(array_fill(0, 20, 'ok'))
        ->and(rateLimitAttempts(RateLimitStage::principalKey('testing', 'tests')))->toBe(20);
});

it('LR6 never counts a call that the Gate denied', function () {
    rateLimitServe(gates: false);
    Gates::allow('second_echo');

    $denied = rateLimitEcho();

    expect($denied['note'])->toStartWith('Access denied: the app defines no Gate')
        ->and(rateLimitAttempts(RateLimitStage::principalKey('testing', 'tests')))->toBe(0);

    rateLimitEcho('second_echo');

    expect(rateLimitAttempts(RateLimitStage::principalKey('testing', 'tests')))->toBe(1);
});

it('LR7 denies with rate_limit_unavailable when the cache is down, and reports it', function () {
    rateLimitServe();
    ThrowingStore::limiter();

    $envelope = rateLimitEcho();
    $entries = AuditLog::entries();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe(RateLimitStage::UNAVAILABLE)
        ->and($envelope['note'])->toBe('Rate limits could not be checked (cache unavailable), so the call was not run.')
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['decision'])->toBe('denied')
        ->and($entries[0]['reason'])->toBe('rate_limit_unavailable');
    // Two reports: this stage, and the audit's denied-call counter (Part D) on the same cache.
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === ThrowingStore::MESSAGE);
    Exceptions::assertReportedCount(2);
});

it('LR8 keys the counters by environment, principal and tool', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_tool' => ['echo' => '5,1']]);

    rateLimitEcho();

    expect(rateLimitAttempts('runtime-mcp:testing:principal:tests'))->toBe(1)
        ->and(rateLimitAttempts('runtime-mcp:testing:tool:echo:tests'))->toBe(1)
        ->and(rateLimitAttempts('runtime-mcp:production:principal:tests'))->toBe(0)
        ->and(RateLimitStage::principalKey('staging', 'ana'))->toBe('runtime-mcp:staging:principal:ana')
        ->and(RateLimitStage::toolKey('staging', 'db_query', 'ana'))->toBe('runtime-mcp:staging:tool:db_query:ana');
});

it('LR9 checks every limit before it counts any, so a denied call uses no budget', function () {
    rateLimitServe(['runtime-mcp.rate_limits' => ['per_principal' => '3,1', 'per_tool' => ['echo' => '1,1']]]);

    rateLimitEcho();
    $denied = rateLimitEcho();

    expect($denied['note'])->toStartWith('Rate limit reached: 1 call per minute for echo.')
        ->and(rateLimitAttempts(RateLimitStage::principalKey('testing', 'tests')))->toBe(1)
        ->and(rateLimitEcho('second_echo')['status'])->toBe('ok')
        ->and(rateLimitEcho('second_echo')['status'])->toBe('ok')
        ->and(rateLimitEcho('second_echo')['note'])->toStartWith('Rate limit reached: 3 calls per minute for this key.');
});

it('LR10 gives the per-key note when both limits are reached', function () {
    rateLimitServe(['runtime-mcp.rate_limits' => ['per_principal' => '1,1', 'per_tool' => ['echo' => '1,1']]]);

    rateLimitEcho();

    expect(rateLimitEcho()['note'])->toBe('Rate limit reached: 1 call per minute for this key. Try again in 60 seconds.');
});

it('LR11 does not turn an error of an inner stage into rate_limit_unavailable', function () {
    rateLimitServe();
    // RunTool catches every tool exception; an inner stage that cannot be built is what reaches stage 3.
    app()->bind(ReadOnlyStage::class, fn (): never => throw new LogicException('The stage cannot be built.'));

    $envelope = rateLimitEcho();
    $entry = AuditLog::entries()[0];

    expect($envelope['note'])->toBe(sprintf(AuditStage::INNER_FAILURE, LogicException::class))
        ->and($entry['decision'])->toBe('allowed')
        ->and($entry['error_class'])->toBe(LogicException::class);
});

it('LR12 denies with rate_limit_unavailable when the cache store loses the counter without an error', function () {
    rateLimitServe(['runtime-mcp.rate_limits.per_principal' => '1,1']);
    // The null store, or a Memcached server that is down: no exception, and hit() counts 0.
    app()->instance(RateLimiter::class, new RateLimiter(new Repository(new NullStore)));

    $envelopes = [rateLimitEcho(), rateLimitEcho()];
    $entries = AuditLog::entries();

    expect(array_column($envelopes, 'status'))->toBe(['unavailable', 'unavailable'])
        ->and(array_column($envelopes, 'note'))->toBe([RateLimitStage::UNAVAILABLE, RateLimitStage::UNAVAILABLE])
        ->and(array_column($entries, 'reason'))->toBe(['rate_limit_unavailable', 'rate_limit_unavailable']);
    // Per call: this stage, and the audit's denied-call counter (Part D) on the same cache.
    Exceptions::assertReported(CounterNotStored::class);
    Exceptions::assertReportedCount(4);
});
