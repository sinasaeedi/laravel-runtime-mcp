<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootReport;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Security\Boot\Severity;

it('splits errors from warnings, in order, and passes with warnings only', function () {
    $report = new BootReport([
        BootIssue::warning('a', 'first warning'),
        BootIssue::error('b', 'first error'),
        BootIssue::warning('c', 'second warning'),
        BootIssue::error('d', 'second error'),
    ], true, true);

    expect(array_map(fn (BootIssue $issue): string => $issue->ruleId, $report->errors()))->toBe(['b', 'd'])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $report->warnings()))->toBe(['a', 'c'])
        ->and($report->passed())->toBeFalse()
        ->and((new BootReport([BootIssue::warning('a', 'w')], true, true))->passed())->toBeTrue()
        ->and((new BootReport([], false, false))->passed())->toBeTrue();
});

it('allows a transport only when validation passed and the transport is ready', function (array $issues, bool $httpReady, bool $stdioReady, bool $http, bool $stdio) {
    /** @var list<BootIssue> $issues */
    $report = new BootReport($issues, $httpReady, $stdioReady);

    expect($report->httpAllowed())->toBe($http)
        ->and($report->stdioAllowed())->toBe($stdio);
})->with([
    'clean, both ready' => [[], true, true, true, true],
    'clean, only STDIO ready' => [[], false, true, false, true],
    'clean, only HTTP ready' => [[], true, false, true, false],
    'a warning, both ready' => [[BootIssue::warning('w', 'w')], true, true, true, true],
    'an error, both ready' => [[BootIssue::error('e', 'e')], true, true, false, false],
]);

it('builds issues with the named severities and a log array', function () {
    expect(BootIssue::error('x', 'm')->severity)->toBe(Severity::Error)
        ->and(BootIssue::warning('x', 'm')->severity)->toBe(Severity::Warning)
        ->and(BootIssue::warning('x', 'm')->toArray())->toBe(['rule' => 'x', 'severity' => 'warning', 'message' => 'm']);
});

it('keeps the endpoint closed while BootState holds no report', function () {
    $state = new BootState;

    expect($state->report())->toBeNull()
        ->and($state->httpAllowed())->toBeFalse();

    $state->record(new BootReport([], true, false));
    expect($state->httpAllowed())->toBeTrue();

    $state->record(new BootReport([BootIssue::error('e', 'e')], true, true));
    expect($state->httpAllowed())->toBeFalse();
});

it('lists every error, and no warning, in the relaxed exception', function () {
    $report = new BootReport([
        BootIssue::error('one', 'First problem.'),
        BootIssue::warning('two', 'Only a warning.'),
        BootIssue::error('three', 'Second problem.'),
    ], true, true);

    $e = new RuntimeMcpMisconfigured($report, 'local');

    expect($e->getMessage())->toBe(<<<'TEXT'
        The Runtime MCP security config is not valid in the `local` environment:
        - [one] First problem.
        - [three] Second problem.
        Fix these, or set RUNTIME_MCP_ENABLED=false. Strict environments keep the endpoint off instead of throwing.
        TEXT)
        ->and($e->report)->toBe($report);
});
