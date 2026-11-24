<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Catalog;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Limits;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;

/* Spec 4.5, 5.8, 5.17, 7.3, 01-40 Part D: the doctor check for catalog.enabled and the cap in use (01-12's API). */

/** @return list<array{string, string, string|null}> [status, message, fix] */
function catalogCheckResults(): array
{
    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        app(Catalog::class)->run(),
    );
}

it('CK1 passes with the catalog off', function (mixed $value) {
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => $value]);

    expect(catalogCheckResults())->toBe([['pass', 'The catalog is off: tools/list shows every tool.', null]]);
})->with(['false' => [false], 'null' => [null]]);

it('CK2 fails for a value that is not a bool, says the catalog is off, and never prints the value', function (mixed $value) {
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => $value]);

    expect(catalogCheckResults())->toBe([[
        'fail',
        'catalog.enabled is not true or false, so the catalog is off.',
        'Set catalog.enabled to true or false.',
    ]]);
})->with(['the string yes' => ['yes'], 'the string true' => ['true'], 'one' => [1], 'a secret-shaped word' => ['rmcp_secret_value']]);

it('CK3 passes with the catalog on and names the source of the cap in use', function (array $config, string $message) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => true, ...$config]);

    expect(catalogCheckResults())->toBe([['pass', $message, null]]);
})->with([
    'the defaults' => [[], 'The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is 63488 bytes (from mcp.tool_search.max_output_bytes minus 2048).'],
    'output.max_bytes lower' => [['runtime-mcp.output.max_bytes' => 20_000], 'The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is 20000 bytes (from output.max_bytes).'],
    'both the same' => [['runtime-mcp.output.max_bytes' => 63_488], 'The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is 63488 bytes (from mcp.tool_search.max_output_bytes minus 2048).'],
    'exactly 8,000' => [['mcp.tool_search.max_output_bytes' => 10_048], 'The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is 8000 bytes (from mcp.tool_search.max_output_bytes minus 2048).'],
]);

it('CK4 warns with the catalog on and a cap in use below 8,000 bytes', function (array $config, int $limit, string $source) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => true, ...$config]);

    expect(catalogCheckResults())->toBe([[
        'warn',
        "The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is {$limit} bytes ({$source}).",
        'Raise mcp.tool_search.max_output_bytes: below 8000 bytes most results are cut.',
    ]]);
})->with([
    'one byte below' => [['mcp.tool_search.max_output_bytes' => 10_047], 7_999, 'from mcp.tool_search.max_output_bytes minus 2048'],
    'the floor' => [['mcp.tool_search.max_output_bytes' => 100], 1_000, 'from mcp.tool_search.max_output_bytes minus 2048'],
    'a small output.max_bytes' => [['runtime-mcp.output.max_bytes' => 5_000], 5_000, 'from output.max_bytes'],
]);

it('CK5 is a plain Fail, not a privilege check: it fails in a relaxed environment too', function () {
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => 'yes'], 'local');

    expect(app(Catalog::class)->run()[0]->privilege)->toBeFalse();
});

it('CK6 is the last core check, after Limits', function () {
    currentTest()->bootWith();

    expect(array_search(Catalog::class, Doctor::CHECKS, true))->toBe(array_search(Limits::class, Doctor::CHECKS, true) + 1)
        ->and(Doctor::CHECKS[array_key_last(Doctor::CHECKS)])->toBe(Catalog::class)
        ->and(app(Catalog::class)->title())->toBe('Catalog');
});

it('CK7 makes the Limits line show the cap in use with the catalog on', function () {
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => true]);

    expect(app(Limits::class)->run()[0]->message)->toEndWith('results at most 63488 bytes.');
});
