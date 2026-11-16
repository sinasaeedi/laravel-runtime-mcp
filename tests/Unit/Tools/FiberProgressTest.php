<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tests\Support\SteppingClock;
use SinaSaeedi\RuntimeMcp\Tools\FiberProgress;
use SinaSaeedi\RuntimeMcp\Tools\NullProgress;
use SinaSaeedi\RuntimeMcp\Tools\Progress;
use SinaSaeedi\RuntimeMcp\Tools\ProgressClock;
use SinaSaeedi\RuntimeMcp\Tools\ProgressReport;

/*
 * 01-37 Part A (spec 5.15): the drop rules, the cap and the message of FiberProgress, without HTTP. fiberReports()
 * runs $work inside a fiber as McpToolAdapter does, and collects what each accepted report suspends with.
 */

/**
 * @param  Closure(Progress): void  $work
 * @return list<mixed>
 */
function fiberReports(Closure $work, ?ProgressClock $clock = null): array
{
    // As McpToolAdapter: the fiber first, then the progress that knows it, passed in through start().
    $fiber = new Fiber(fn (Progress $progress) => $work($progress));
    $progress = new FiberProgress($fiber, app(Redactor::class), $clock ?? new SteppingClock);

    $reports = [];
    $suspended = $fiber->start($progress);

    while ($fiber->isSuspended()) {
        $reports[] = $suspended;
        $suspended = $fiber->resume();
    }

    return $reports;
}

/**
 * @param  list<mixed>  $reports
 * @return list<int>
 */
function fiberReportsDone(array $reports): array
{
    return array_map(fn (mixed $report): int => $report instanceof ProgressReport ? $report->done : -1, $reports);
}

it('FP1 drops a report outside any fiber instead of throwing a FiberError', function () {
    $fiber = new Fiber(fn () => null);
    $progress = new FiberProgress($fiber, app(Redactor::class), new SteppingClock);

    $progress->report(1, 2, 'outside');

    expect($fiber->isStarted())->toBeFalse();
});

it('FP2 drops a report from another fiber: that fiber runs to its end, unsuspended', function () {
    $inner = null;

    $reports = fiberReports(function (Progress $progress) use (&$inner): void {
        $inner = new Fiber(fn () => $progress->report(1, null, 'inner'));
        $inner->start();
        $progress->report(2, null, 'outer');
    });

    expect(fiberReportsDone($reports))->toBe([2])
        ->and($inner)->toBeInstanceOf(Fiber::class)
        ->and($inner?->isTerminated())->toBeTrue();
});

it('FP3 drops a report whose done does not grow past the last sent one', function () {
    $reports = fiberReports(function (Progress $progress): void {
        foreach ([3, 2, 3, 4] as $done) {
            $progress->report($done);
        }
    });

    expect(fiberReportsDone($reports))->toBe([3, 4]);
});

it('FP4 drops a report less than one second after the last sent one; the first always goes', function () {
    $s = 1_000_000_000;
    // now() of each report that reaches the clock rule: 10 s, 10.5 s, 10.999999999 s, 11 s, 11.5 s, 12 s.
    $clock = new SteppingClock(times: [10 * $s, 10 * $s + $s / 2, 11 * $s - 1, 11 * $s, 11 * $s + $s / 2, 12 * $s]);

    $reports = fiberReports(function (Progress $progress): void {
        foreach (range(1, 6) as $done) {
            $progress->report($done);
        }
    }, $clock);

    expect(fiberReportsDone($reports))->toBe([1, 4, 6]);
});

it('FP5 sends at most 20 reports for one call', function () {
    $reports = fiberReports(function (Progress $progress): void {
        foreach (range(1, 25) as $done) {
            $progress->report($done, 25);
        }
    });

    expect(fiberReportsDone($reports))->toBe(range(1, FiberProgress::MAX_REPORTS));
});

it('FP6 redacts the whole message first, then cuts it to 200 characters', function () {
    // A GitHub token from character 191 on: cut first, only "gh"."p_" and 6 characters are left, and no pattern
    // matches them. Split so that push protection sees no token in this file.
    $token = 'gh'.'p_'.str_repeat('A1b2C3d4', 5);
    $message = str_repeat('a', 190).' '.$token;

    $sent = fiberReports(fn (Progress $progress) => $progress->report(1, 1, $message))[0];

    expect($sent)->toBeInstanceOf(ProgressReport::class)
        ->and($sent instanceof ProgressReport ? $sent->message : null)->toBe(mb_substr(str_repeat('a', 190).' [redacted:github_token]', 0, 200))
        ->and(str_contains((string) ($sent instanceof ProgressReport ? $sent->message : ''), 'p_A1b2'))->toBeFalse();
});

it('FP7 cuts by characters, not bytes', function () {
    $sent = fiberReports(fn (Progress $progress) => $progress->report(1, null, str_repeat('é', 250)))[0];
    $message = $sent instanceof ProgressReport ? (string) $sent->message : '';

    expect(mb_strlen($message))->toBe(FiberProgress::MAX_MESSAGE)
        ->and(strlen($message))->toBe(400);
});

it('FP8 sends total and message only when they are set', function () {
    expect((new ProgressReport(2, null, null))->params())->toBe(['progress' => 2])
        ->and((new ProgressReport(2, 7, null))->params())->toBe(['progress' => 2, 'total' => 7])
        ->and((new ProgressReport(2, null, 'x'))->params())->toBe(['progress' => 2, 'message' => 'x'])
        ->and(fiberReports(fn (Progress $progress) => $progress->report(1)))->toEqual([new ProgressReport(1, null, null)]);
});

it('FP9 NullProgress never suspends, inside a fiber or outside', function () {
    $progress = new NullProgress;
    $progress->report(1, 2, 'outside');
    $fiber = new Fiber(fn () => $progress->report(1, 2, 'inside'));
    $fiber->start();

    expect($fiber->isTerminated())->toBeTrue();
});

it('FP10 the default clock is monotonic nanoseconds', function () {
    $clock = new ProgressClock;
    $first = $clock->now();

    expect($clock->now())->toBeGreaterThanOrEqual($first)
        ->and(abs(hrtime(true) - $first))->toBeLessThan(60 * FiberProgress::MIN_INTERVAL_NS);
});
