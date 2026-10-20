<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\BootRules;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Endpoint;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Route;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Doctor\DoctorLine;
use SinaSaeedi\RuntimeMcp\Doctor\DoctorReport;

/* Spec 4.6 (third row), 7.3: the counts, the footer and the exit code of one doctor run. */

/** @param  class-string  $source */
function reportLine(CheckStatus $status, bool $privilege = false, string $source = Endpoint::class): DoctorLine
{
    return new DoctorLine('A check', new CheckResult($status, 'message', null, $privilege), $source);
}

it('turns a failed privilege result into a warning only in a relaxed environment', function () {
    $line = reportLine(CheckStatus::Fail, privilege: true);

    expect((new DoctorReport([$line], relaxed: true))->status($line))->toBe(CheckStatus::Warn)
        ->and((new DoctorReport([$line], relaxed: false))->status($line))->toBe(CheckStatus::Fail);
});

it('keeps every other status as returned, in both environments', function (CheckStatus $status, bool $privilege, bool $relaxed) {
    $line = reportLine($status, $privilege);

    expect((new DoctorReport([$line], $relaxed))->status($line))->toBe($status);
})->with([
    'a plain fail, relaxed' => [CheckStatus::Fail, false, true],
    'a privilege warn, relaxed' => [CheckStatus::Warn, true, true],
    'a privilege pass, relaxed' => [CheckStatus::Pass, true, true],
    'a plain warn, strict' => [CheckStatus::Warn, false, false],
]);

it('exits 1 on any failure and 0 otherwise, in every environment', function (array $statuses, bool $relaxed, int $code) {
    /** @var list<CheckStatus> $statuses */
    $report = new DoctorReport(array_map(fn (CheckStatus $status): DoctorLine => reportLine($status), $statuses), $relaxed);

    expect($report->exitCode())->toBe($code);
})->with([
    'all pass' => [[CheckStatus::Pass, CheckStatus::Pass], false, 0],
    'warnings only' => [[CheckStatus::Pass, CheckStatus::Warn], false, 0],
    'a fail, strict' => [[CheckStatus::Pass, CheckStatus::Fail], false, 1],
    'a fail, relaxed' => [[CheckStatus::Fail], true, 1],
    'nothing' => [[], false, 0],
]);

it('exits 0 in a relaxed environment when the only failure is a privilege check', function () {
    $report = new DoctorReport([reportLine(CheckStatus::Fail, privilege: true)], relaxed: true);

    expect($report->exitCode())->toBe(0)
        ->and($report->count(CheckStatus::Warn))->toBe(1)
        ->and($report->summary())->toBe('1 warning.');
});

it('writes the footer with the counts and what they mean', function (array $lines, string $summary) {
    /** @var list<DoctorLine> $lines */
    expect((new DoctorReport($lines, false))->summary())->toBe($summary);
})->with([
    'all pass' => [[reportLine(CheckStatus::Pass)], 'Every check passes.'],
    'one failure' => [[reportLine(CheckStatus::Fail)], '1 failure. Fix them before the release goes live.'],
    'failures and warnings' => [[reportLine(CheckStatus::Fail), reportLine(CheckStatus::Fail), reportLine(CheckStatus::Warn)], '2 failures, 1 warning. Fix them before the release goes live.'],
    'warnings only' => [[reportLine(CheckStatus::Warn), reportLine(CheckStatus::Warn)], '2 warnings.'],
    'a boot rule failure' => [[reportLine(CheckStatus::Fail, source: BootRules::class), reportLine(CheckStatus::Warn)], '1 failure, 1 warning. The endpoint stays off until every boot rule and route failure is fixed.'],
    'a route failure' => [[reportLine(CheckStatus::Fail, source: Route::class)], '1 failure. The endpoint stays off until every boot rule and route failure is fixed.'],
]);

it('says the endpoint stays off only for a failure of a boot rule or the route', function () {
    expect((new DoctorReport([reportLine(CheckStatus::Warn, source: BootRules::class)], false))->blocksEndpoint())->toBeFalse()
        ->and((new DoctorReport([reportLine(CheckStatus::Fail)], false))->blocksEndpoint())->toBeFalse()
        ->and((new DoctorReport([reportLine(CheckStatus::Fail, source: BootRules::class)], false))->blocksEndpoint())->toBeTrue()
        ->and(DoctorReport::ENDPOINT_CHECKS)->toBe([BootRules::class, Route::class]);
});
