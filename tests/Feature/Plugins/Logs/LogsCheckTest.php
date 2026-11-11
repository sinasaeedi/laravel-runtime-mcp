<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Doctor\LogsCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;

/*
 * 01-25 Part E (spec 4.5, 6.9, 7.3): the `Logs` doctor check of the logs plugin. Config only: no log file is read.
 * Testbench's own `stack` is Laravel's stock stack of `single`.
 */

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}>
 */
function logsCheckResults(array $config = []): array
{
    currentTest()->bootWith($config);
    $checks = app(LogsPlugin::class)->doctorChecks();

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(LogsCheck::class)
        ->and($checks[0]->title())->toBe('Logs');

    return array_map(fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix], $checks[0]->run());
}

it('LC1 passes with the channels and the budget', function () {
    expect(logsCheckResults())->toBe([['pass', 'logs_search reads `stack`; the read budget is 200000 bytes per call.', null]])
        ->and(logsCheckResults(['runtime-mcp.logs.channels' => ['stack', 'daily'], 'runtime-mcp.logs.max_bytes' => 10_000]))
        ->toBe([['pass', 'logs_search reads `stack`, `daily`; the read budget is 10000 bytes per call.', null]]);
});

it('LC2 fails for a max_bytes that is not a whole number of at least 10000', function (mixed $value) {
    expect(logsCheckResults(['runtime-mcp.logs.max_bytes' => $value]))->toBe([['fail', LogsCheck::BAD_MAX_BYTES, LogsCheck::MAX_BYTES_FIX]]);
})->with(['9999' => 9_999, 'a string' => '200000', 'a float' => 200000.0, 'null' => null, 'zero' => 0]);

it('LC3 fails when no channel is valid, and warns for each entry that is not a name, by its position', function () {
    expect(logsCheckResults(['runtime-mcp.logs.channels' => []]))->toBe([['fail', LogsCheck::NO_CHANNELS, LogsCheck::CHANNELS_FIX]])
        ->and(logsCheckResults(['runtime-mcp.logs.channels' => 'stack']))->toBe([['fail', LogsCheck::NO_CHANNELS, LogsCheck::CHANNELS_FIX]])
        ->and(logsCheckResults(['runtime-mcp.logs.channels' => ['stack', 'a b', 7]]))->toBe([
            ['warn', 'Entry 2 of runtime-mcp.logs.channels is not a channel name, so logs_search leaves it out.', LogsCheck::BAD_ENTRY_FIX],
            ['warn', 'Entry 3 of runtime-mcp.logs.channels is not a channel name, so logs_search leaves it out.', LogsCheck::BAD_ENTRY_FIX],
        ]);
});

it('LC4 warns for each part of a channel that it cannot read', function () {
    expect(logsCheckResults(['runtime-mcp.logs.channels' => ['stderr', 'mixed'], 'logging.channels.mixed' => ['driver' => 'stack', 'channels' => ['single', 'slack']]]))->toBe([
        ['warn', 'logs_search cannot read a part of it: Channel `stderr` writes to stderr, so there is no file to read.', LogsCheck::UNREADABLE_FIX],
        ['warn', 'logs_search cannot read a part of it: Channel `slack` sends entries to Slack, so there is no file to read.', LogsCheck::UNREADABLE_FIX],
    ]);
});

/** The config that lets logs_search read the audit log. */
const LOGS_CHECK_AUDIT = [
    'named' => ['runtime-mcp.logs.channels' => ['runtime-mcp']],
    'in a stack' => ['runtime-mcp.logs.channels' => ['all'], 'logging.channels.all' => ['driver' => 'stack', 'channels' => ['single', 'runtime-mcp']]],
];

it("LC5 warns when logs_search can read the package's audit log, also through a stack", function (string $case) {
    expect(logsCheckResults(LOGS_CHECK_AUDIT[$case]))->toBe([
        ['warn', "Channel `runtime-mcp` is the package's audit log: logs_search shows every developer's tool calls and their params to an agent that may use it.", LogsCheck::AUDIT_FIX],
    ]);
})->with(array_keys(LOGS_CHECK_AUDIT));
