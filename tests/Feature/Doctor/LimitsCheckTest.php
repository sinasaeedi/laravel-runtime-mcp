<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Limits;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyConnectionOptions;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSettings;

/* Spec 4.5, 5.8, 7.3, 01-17 Part A: the doctor check for rate_limits and output.max_bytes (01-12's API). */

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}> [status, message, fix]
 */
function limitsCheckResults(array $config = [], ?string $environment = null): array
{
    currentTest()->bootWith($config, $environment);

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        app(Limits::class)->run(),
    );
}

it('LC1 passes with the limits in use', function (array $config, string $message) {
    /** @var array<string, mixed> $config */
    expect(limitsCheckResults($config))->toBe([['pass', $message, null]]);
})->with([
    'the defaults' => [[], '120 calls per minute per key; db_query 60 calls per minute; results at most 100000 bytes.'],
    'custom values' => [
        ['runtime-mcp.rate_limits' => ['per_principal' => '30,5', 'per_tool' => ['db_query' => '10,1', 'echo' => '1,1']], 'runtime-mcp.output.max_bytes' => '50000'],
        '30 calls per 5 minutes per key; db_query 10 calls per minute; echo 1 call per minute; results at most 50000 bytes.',
    ],
    'no per-tool limit' => [['runtime-mcp.rate_limits.per_tool' => []], '120 calls per minute per key; no per-tool limit; results at most 100000 bytes.'],
]);

it('LC2 fails once per bad key, with the default in use and the fix', function () {
    expect(limitsCheckResults([
        'runtime-mcp.rate_limits' => ['per_principal' => 'abc', 'per_tool' => ['db_query' => '60', 'BadName' => '1,1']],
        'runtime-mcp.output.max_bytes' => 10,
    ]))->toBe([
        ['fail', "rate_limits.per_principal is not 'attempts,minutes', so 120,1 is used.", Limits::LIMIT_FIX],
        ['fail', "rate_limits.per_tool.db_query is not 'attempts,minutes', so 60,1 is used.", Limits::LIMIT_FIX],
        ['fail', LimitSettings::BAD_TOOL_NAME, Limits::LIMIT_FIX],
        ['fail', 'output.max_bytes is not a whole number of at least 1000, so 100000 is used.', 'Set it to the cap in bytes, for example 100000.'],
    ])
        ->and(Limits::LIMIT_FIX)->toBe("Write each limit as 'attempts,minutes', for example '120,1'.");
});

it('LC3 fails for a bad output.max_bytes and never prints the value', function (mixed $value) {
    $results = limitsCheckResults(['runtime-mcp.output.max_bytes' => $value]);

    expect($results)->toBe([['fail', Limits::BAD_MAX_BYTES, Limits::MAX_BYTES_FIX]]);
})->with(['below the minimum' => [999], 'a word' => ['rmcp_secret_value'], 'null' => [null], 'a float' => [1.5]]);

it('LC4 is a plain Fail, not a privilege check: it fails in a relaxed environment too', function () {
    currentTest()->bootWith(['runtime-mcp.rate_limits.per_principal' => '0,1'], 'local');

    expect(app(Limits::class)->run()[0]->privilege)->toBeFalse();
});

it('LC5 is the core check after ReadOnlyConnectionOptions', function () {
    currentTest()->bootWith();

    expect(array_search(Limits::class, Doctor::CHECKS, true))->toBe(array_search(ReadOnlyConnectionOptions::class, Doctor::CHECKS, true) + 1)
        ->and(app(Limits::class)->title())->toBe('Limits');
});

it('LC6 names a limiter store that denies every call or counts per process, from the config only', function (array $config, string $environment, ?array $store) {
    /** @var array<string, mixed> $config */
    $pass = ['pass', '120 calls per minute per key; db_query 60 calls per minute; results at most 100000 bytes.', null];

    expect(limitsCheckResults($config, $environment))->toBe($store === null ? [$pass] : [$pass, $store]);
})->with([
    'cache.limiter null' => [['cache.limiter' => 'null'], 'production', ['fail', sprintf(Limits::NULL_STORE, 'the cache store `null`'), Limits::STORE_FIX]],
    'cache.default unset' => [['cache.limiter' => null, 'cache.default' => null], 'production', ['fail', sprintf(Limits::NULL_STORE, 'the cache store `null`'), Limits::STORE_FIX]],
    'a store with the null driver, relaxed too' => [['cache.stores.off' => ['driver' => 'null'], 'cache.limiter' => 'off'], 'local', ['fail', sprintf(Limits::NULL_STORE, 'the cache store `off`'), Limits::STORE_FIX]],
    'a store not in cache.stores' => [['cache.limiter' => 'missing'], 'production', ['fail', sprintf(Limits::UNDEFINED_STORE, 'the cache store `missing`'), Limits::STORE_FIX]],
    'a store name that is not a name is never printed' => [['cache.limiter' => 'redis://user:secret@cache'], 'production', ['fail', sprintf(Limits::UNDEFINED_STORE, 'the limiter cache store'), Limits::STORE_FIX]],
    'array in a strict environment' => [['cache.limiter' => 'array'], 'production', ['warn', sprintf(Limits::ARRAY_STORE, 'the cache store `array`'), Limits::STORE_FIX]],
    'array through cache.default' => [['cache.limiter' => null, 'cache.default' => 'array'], 'staging', ['warn', sprintf(Limits::ARRAY_STORE, 'the cache store `array`'), Limits::STORE_FIX]],
    'array in a relaxed environment' => [['cache.limiter' => 'array'], 'testing', null],
    'a shared store' => [['cache.limiter' => 'file'], 'production', null],
]);
