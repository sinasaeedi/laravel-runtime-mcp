<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSettings;

/* Spec 4.5, 5.8, 01-17 Part A: the limits in use. A bad value gives the spec default, never "no limit". */

/** @param  array<string, mixed>  $config */
function limitSettingsWith(array $config = []): LimitSettings
{
    currentTest()->bootWith($config);

    return app(LimitSettings::class);
}

/** @return array{int, int}|null */
function limitSettingsPair(?SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSpec $spec): ?array
{
    return $spec === null ? null : [$spec->maxAttempts, $spec->decayMinutes];
}

it('LT1 uses the spec defaults: 120,1 per key, 60,1 for db_query, nothing for another tool', function () {
    $limits = limitSettingsWith();

    expect(limitSettingsPair($limits->perPrincipal()))->toBe([120, 1])
        ->and(limitSettingsPair($limits->forTool('db_query')))->toBe([60, 1])
        ->and($limits->forTool('app_info'))->toBeNull()
        ->and(array_keys($limits->perToolInUse()))->toBe(['db_query'])
        ->and($limits->problems())->toBe([]);
});

it('LT2 reads valid values', function () {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits' => ['per_principal' => '30,5', 'per_tool' => ['db_query' => '10,1', 'echo' => '2,1']]]);

    expect(limitSettingsPair($limits->perPrincipal()))->toBe([30, 5])
        ->and(limitSettingsPair($limits->forTool('db_query')))->toBe([10, 1])
        ->and(limitSettingsPair($limits->forTool('echo')))->toBe([2, 1])
        ->and($limits->problems())->toBe([]);
});

it('LT3 falls back to 120,1 for a bad per_principal and names the key', function () {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits.per_principal' => 'rmcp_not_a_limit']);

    expect(limitSettingsPair($limits->perPrincipal()))->toBe([120, 1])
        ->and($limits->problems())->toBe([LimitSettings::BAD_PER_PRINCIPAL])
        ->and(LimitSettings::BAD_PER_PRINCIPAL)->toBe("rate_limits.per_principal is not 'attempts,minutes', so 120,1 is used.");
});

it('LT4 uses the spec default when per_tool is missing, and fails doctor when it is not a list', function (mixed $value, array $problems) {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits.per_tool' => $value]);

    expect(limitSettingsPair($limits->forTool('db_query')))->toBe([60, 1])
        ->and($limits->problems())->toBe($problems);
})->with([
    'missing' => [null, []],
    'a string' => ['60,1', [LimitSettings::BAD_PER_TOOL_LIST]],
]);

it('LT5 gives a bad tool value the spec default, or 60,1 for a tool without one', function () {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits.per_tool' => ['db_query' => 'abc', 'echo' => '0,1']]);

    expect(limitSettingsPair($limits->forTool('db_query')))->toBe([60, 1])
        ->and(limitSettingsPair($limits->forTool('echo')))->toBe([60, 1])
        ->and($limits->problems())->toBe([
            "rate_limits.per_tool.db_query is not 'attempts,minutes', so 60,1 is used.",
            "rate_limits.per_tool.echo is not 'attempts,minutes', so 60,1 is used.",
        ]);
});

it('LT6 has no per-tool limit for an empty per_tool list', function () {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits.per_tool' => []]);

    expect($limits->forTool('db_query'))->toBeNull()
        ->and($limits->perToolInUse())->toBe([])
        ->and($limits->problems())->toBe([]);
});

it('LT7 fails for a key that cannot be a tool name, and never prints it', function () {
    $limits = limitSettingsWith(['runtime-mcp.rate_limits.per_tool' => ['Db_Query' => '60,1', 'rmcp_secret key' => '60,1', 0 => '60,1']]);

    expect($limits->problems())->toBe([LimitSettings::BAD_TOOL_NAME, LimitSettings::BAD_TOOL_NAME, LimitSettings::BAD_TOOL_NAME])
        ->and($limits->perToolInUse())->toBe([])
        ->and(implode(' ', $limits->problems()))->not->toContain('rmcp_');
});
