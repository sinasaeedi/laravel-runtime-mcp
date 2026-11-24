<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CatalogCalls;
use SinaSaeedi\RuntimeMcp\Tools\OutputCap;

/*
 * 01-40 Part C, spec 5.8, 5.17: with the catalog on, the cap in use is the lower of output.max_bytes and
 * mcp.tool_search.max_output_bytes minus 2,048, at least 1,000, so one inner result always fits the batch. The package
 * reads mcp.tool_search.* as laravel/mcp's ToolSearch does, and never changes it.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('CC1 picks the cap in use from both settings', function (array $config, int $limit) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    expect(app(OutputCap::class)->limit())->toBe($limit);
})->with([
    'catalog off' => [[], 100_000],
    'catalog off, a small batch limit is ignored' => [['mcp.tool_search.max_output_bytes' => 5_000], 100_000],
    'catalog on, the defaults' => [['runtime-mcp.catalog.enabled' => true], 63_488],
    'catalog on, output.max_bytes lower' => [['runtime-mcp.catalog.enabled' => true, 'runtime-mcp.output.max_bytes' => 20_000], 20_000],
    'catalog on, a digit string' => [['runtime-mcp.catalog.enabled' => true, 'mcp.tool_search.max_output_bytes' => '70000'], 67_952],
    'catalog on, a tiny batch limit' => [['runtime-mcp.catalog.enabled' => true, 'mcp.tool_search.max_output_bytes' => 100], 1_000],
    'catalog on, a word (ToolSearch uses 256)' => [['runtime-mcp.catalog.enabled' => true, 'mcp.tool_search.max_output_bytes' => 'abc'], 1_000],
    'catalog on, the key missing (65,536)' => [['runtime-mcp.catalog.enabled' => true, 'mcp.tool_search' => []], 63_488],
    'catalog on, a bad output.max_bytes (100,000)' => [['runtime-mcp.catalog.enabled' => true, 'runtime-mcp.output.max_bytes' => 'big'], 63_488],
    'catalog "yes" is off' => [['runtime-mcp.catalog.enabled' => 'yes', 'runtime-mcp.output.max_bytes' => 50_000], 50_000],
]);

it('CC2 fits one large inner result in the batch: the result is cut to the cap in use', function () {
    CatalogCalls::serve();

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(CatalogCalls::calls([['big_result', ['kind' => 'rows', 'count' => 5_000]]]))));
    $envelope = data_get($batch, 'results.0.structuredContent');
    $envelope = is_array($envelope) ? $envelope : [];

    expect($batch['ok'])->toBeTrue()
        ->and($envelope['status'] ?? null)->toBe('partial')
        ->and($envelope['truncated'] ?? null)->toBeGreaterThan(0)
        ->and(strlen((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))->toBeLessThanOrEqual(63_488)
        ->and(CatalogCalls::audit())->toBe([
            ['big_result', 'allowed', null, 'success'],
            ['execute_tools', 'allowed', null, 'success'],
        ]);
});

it('CC3 answers OutputLimitExceeded with the counts when several large results exceed the batch', function () {
    CatalogCalls::serve();
    $big = ['big_result', ['kind' => 'rows', 'count' => 5_000]];

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(CatalogCalls::calls([$big, $big, $big]))));

    expect($batch)->toBe([
        'ok' => false,
        'error' => ['kind' => 'OutputLimitExceeded', 'message' => 'The tool output exceeded 65536 bytes.'],
        'completedToolCalls' => 2,
        'attemptedToolCalls' => 2,
    ])
        ->and(CatalogCalls::audit())->toBe([
            ['big_result', 'allowed', null, 'success'],
            ['big_result', 'allowed', null, 'success'],
            ['execute_tools', 'allowed', null, 'error'],
        ]);
});
