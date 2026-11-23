<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsRecentErrors;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsSearch;
use SinaSaeedi\RuntimeMcp\Testing\SteppingClock;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProgressStreams;

/*
 * 01-37 Part C (spec 6.9, 6.10, 5.15): logs_search and logs_recent_errors report after each file, over HTTP with a
 * progressToken. Now is 2026-10-02 18:00 UTC, as in LogsSearchTest; the clock steps one second per report, so the
 * one-second rule drops nothing here.
 */

beforeEach(function () {
    AuditLog::clear();
    LogFiles::clear();
    CarbonImmutable::setTestNow('2026-10-02 18:00:00');
});

afterEach(function () {
    AuditLog::clear();
    LogFiles::clear();
    CarbonImmutable::setTestNow();
    date_default_timezone_set('UTC');
});

/**
 * @param  list<string>  $channels
 * @param  array<string, mixed>  $more  more config
 */
function logsProgressServe(array $channels, array $more = []): void
{
    currentTest()->bootWith(LogFiles::config($channels, $more));
    Gates::allow(LogFiles::TOOL, 'logs_recent_errors');
    ProgressStreams::clock(new SteppingClock);
}

/** Seven daily files, 2026-09-26 to 2026-10-02, each with one error at 09:00 that names its day. */
function logsProgressSevenDays(): void
{
    foreach (range(0, 6) as $back) {
        $day = CarbonImmutable::parse('2026-10-02')->subDays($back)->format('Y-m-d');
        LogFiles::write("app-{$day}.log", [LogFiles::line("{$day} 09:00:00", 'error', "failed on {$day}")]);
    }
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array{list<array<array-key, mixed>>, array<array-key, mixed>} the progress params and the envelope
 */
function logsProgressCall(string $tool, array $arguments): array
{
    $messages = ProgressStreams::messages(ProgressStreams::call($tool, $arguments));

    return [ProgressStreams::notifications($messages), ProgressStreams::envelope($messages)];
}

/** @return list<string> "Searched 1 of 7 files (app-2026-10-02.log)." … newest file first */
function logsProgressMessages(string $format): array
{
    return array_map(
        fn (int $done): string => sprintf($format, $done, 7, 'app-'.CarbonImmutable::parse('2026-10-02')->subDays($done - 1)->format('Y-m-d').'.log'),
        range(1, 7),
    );
}

it('LG1 logs_search reports "Searched n of 7 files" after each of seven daily files, newest first', function () {
    logsProgressServe([LogFiles::DAILY]);
    logsProgressSevenDays();

    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, ['limit' => 200]);

    expect(array_column($reports, 'message'))->toBe(logsProgressMessages(LogsSearch::PROGRESS))
        // Spec 6.9's text, written out: the constant must not drift from it.
        ->and($reports[0]['message'] ?? null)->toBe('Searched 1 of 7 files (app-2026-10-02.log).')
        ->and(array_column($reports, 'progress'))->toBe(range(1, 7))
        ->and(array_column($reports, 'total'))->toBe(array_fill(0, 7, 7))
        ->and($envelope['status'])->toBe('ok')
        ->and(data_get($envelope, 'data.entries'))->toHaveCount(7);
});

it('LG2 logs_recent_errors reports "Grouped n of 7 files" the same way', function () {
    logsProgressServe([LogFiles::DAILY]);
    logsProgressSevenDays();

    [$reports, $envelope] = logsProgressCall('logs_recent_errors', ['since' => '2026-09-20T00:00:00Z']);

    expect(array_column($reports, 'message'))->toBe(logsProgressMessages(LogsRecentErrors::PROGRESS))
        ->and($reports[6]['message'] ?? null)->toBe('Grouped 7 of 7 files (app-2026-09-26.log).')
        ->and($envelope['status'])->toBe('ok');
});

it('LG3 counts the files of every source of a stack in one total, whichever source finishes first', function () {
    logsProgressServe([LogFiles::STACK]);
    LogFiles::write('app.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'line format')]);
    LogFiles::write('app-json.log', [LogFiles::json('2026-10-02 10:00:00', 'error', 'json format')]);

    [$reports] = logsProgressCall(LogFiles::TOOL, []);

    expect(array_column($reports, 'progress'))->toBe([1, 2])
        ->and(array_column($reports, 'total'))->toBe([2, 2])
        // The JSON entry is newer: the merge takes it first, and the next step of that stream ends its file.
        ->and(array_column($reports, 'message'))->toBe([
            sprintf(LogsSearch::PROGRESS, 1, 2, 'app-json.log'),
            sprintf(LogsSearch::PROGRESS, 2, 2, 'app.log'),
        ]);
});

it('LG4 counts a skipped file as done', function () {
    logsProgressServe([LogFiles::DAILY]);
    LogFiles::write('app-2026-10-01.log', ["not a log line\n"]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'today')]);

    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, []);

    expect(array_column($reports, 'message'))->toBe([
        sprintf(LogsSearch::PROGRESS, 1, 2, 'app-2026-10-02.log'),
        sprintf(LogsSearch::PROGRESS, 2, 2, 'app-2026-10-01.log'),
    ])->and($envelope['status'])->toBe('partial');
});

it('LG5 sends no report for a file it did not finish: limit reached in the newest file', function () {
    logsProgressServe([LogFiles::DAILY]);
    logsProgressSevenDays();
    LogFiles::write('app-2026-10-02.log', array_map(
        fn (int $minute): string => LogFiles::line(sprintf('2026-10-02 09:%02d:00', $minute), 'error', "today {$minute}"),
        range(0, 9),
    ));

    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, ['limit' => 3]);

    expect($reports)->toBe([])
        ->and($envelope['status'])->toBe('partial')
        ->and(data_get($envelope, 'data.entries'))->toHaveCount(3);
});

it('LG6 sends no report and plain JSON without a progressToken', function () {
    logsProgressServe([LogFiles::DAILY]);
    logsProgressSevenDays();

    $response = ProgressStreams::call(LogFiles::TOOL, [], null);

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->json('result.structuredContent.status'))->toBe('ok');
});

it('LG7 counts a file that is missing before the read as done', function () {
    logsProgressServe([LogFiles::STACK]);
    LogFiles::write('app-json.log', [LogFiles::json('2026-10-02 10:00:00', 'error', 'json format')]);

    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, []);

    // The single channel's app.log does not exist: its stream is skipped on the first merge step, before the JSON file.
    expect(array_column($reports, 'message'))->toBe([
        sprintf(LogsSearch::PROGRESS, 1, 2, 'app.log'),
        sprintf(LogsSearch::PROGRESS, 2, 2, 'app-json.log'),
    ])->and($envelope['status'])->toBe('partial');
});

it('LG8 sends no report for a file it did not finish: an entry older than since inside the second file', function () {
    logsProgressServe([LogFiles::DAILY]);
    LogFiles::write('app-2026-10-01.log', [
        LogFiles::line('2026-10-01 09:00:00', 'error', 'before since'),
        LogFiles::line('2026-10-01 13:00:00', 'error', 'after since'),
    ]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'today')]);

    [$reports, $envelope] = logsProgressCall('logs_recent_errors', ['since' => '2026-10-01T12:00:00Z']);

    // The 09:00 entry of 2026-10-01 ends the scan inside that file: it is not counted.
    expect(array_column($reports, 'message'))->toBe([sprintf(LogsRecentErrors::PROGRESS, 1, 2, 'app-2026-10-02.log')])
        ->and($envelope['status'])->toBe('ok');
});

it('LG9 sends no report for a file it did not finish: the budget runs out inside the second file', function () {
    logsProgressServe([LogFiles::DAILY], ['runtime-mcp.logs.max_bytes' => 10_000]);
    LogFiles::write('app-2026-09-30.log', [LogFiles::line('2026-09-30 09:00:00', 'error', 'never read')]);
    LogFiles::write('app-2026-10-01.log', array_map(
        fn (int $i): string => LogFiles::line('2026-10-01 08:00:00', 'info', "old {$i}"),
        range(0, 599),
    ));
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'today')]);

    // No info entry is kept, so the scan reads on until the budget, not until the limit.
    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, ['level' => 'error']);

    expect(filesize(LogFiles::path('app-2026-10-01.log')))->toBeGreaterThan(20_000)
        ->and(array_column($reports, 'message'))->toBe([sprintf(LogsSearch::PROGRESS, 1, 3, 'app-2026-10-02.log')])
        ->and($envelope['status'])->toBe('partial');
});

it('LG10 sends no report for a file it did not open: the budget is spent at the end of the newest file', function () {
    logsProgressServe([LogFiles::DAILY], ['runtime-mcp.logs.max_bytes' => 10_000]);
    LogFiles::write('app-2026-10-01.log', [LogFiles::line('2026-10-01 09:00:00', 'error', 'never read')]);
    $line = LogFiles::line('2026-10-02 09:00:00', 'error', 'today ');
    // One entry of exactly 10000 bytes: the read ends at the start of the file with the budget spent.
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'today '.str_repeat('p', 10_000 - strlen($line)))]);

    [$reports, $envelope] = logsProgressCall(LogFiles::TOOL, ['limit' => 200]);

    expect(filesize(LogFiles::path('app-2026-10-02.log')))->toBe(10_000)
        ->and(array_column($reports, 'message'))->toBe([sprintf(LogsSearch::PROGRESS, 1, 2, 'app-2026-10-02.log')])
        ->and($envelope['status'])->toBe('partial');
});
