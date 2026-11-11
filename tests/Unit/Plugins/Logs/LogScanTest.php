<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Budget;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\DailyFiles;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogScan;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ResolvedChannel;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ReverseLineReader;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;

/*
 * 01-25 Part D: the daily file list and the scan (merge by time, one budget, from, a file read once, a file that
 * changes). The tool's statuses and notes are LogsSearchTest's.
 */

beforeEach(fn () => LogFiles::clear());
afterEach(fn () => LogFiles::clear());

function logScanSource(string $channel, string $name, bool $daily = false, int $maxFiles = 14): LogSource
{
    return new LogSource($channel, LogFiles::path($name), LogSource::LINE, $daily, $daily ? $maxFiles : 1);
}

/**
 * @param  list<LogSource>  $sources
 * @return array{list<string>, list<string>, bool, list<string>, int}
 */
function logScanRun(array $sources, ?string $from = null, ?string $to = null, int $budget = 1_000_000, int $want = 100, ?Closure $each = null): array
{
    $keep = function (LogEntry $entry, LogSource $source, string $file) use ($each): array {
        if ($each !== null) {
            $each($entry);
        }

        return ['message' => "{$source->channel}:{$entry->message}"];
    };
    $result = (new LogScan)->run(
        new ResolvedChannel($sources, []),
        $from === null ? null : CarbonImmutable::parse($from),
        $to === null ? null : CarbonImmutable::parse($to),
        new Budget($budget),
        $keep,
        $want,
    );

    $kept = array_map(fn (array $row): string => is_string($row['message'] ?? null) ? $row['message'] : '', $result->kept);

    return [$kept, $result->files, $result->cut, $result->skipped, $result->bytes];
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function logScanNames(array $paths): array
{
    return array_map(fn (string $path): string => basename($path), $paths);
}

it('DF1 lists the dated files newest first, inside the days, at most maxFiles, and ignores other names', function () {
    foreach (['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $day) {
        LogFiles::write("app-{$day}.log", ["x\n"]);
    }

    foreach (['app-2026-10-01.log.1', 'app-2026-02-30.log', 'app-2026-10-1.log', 'app2-2026-10-01.log', 'app-2026-10-01.txt'] as $other) {
        LogFiles::write($other, ["x\n"]);
    }

    mkdir(LogFiles::path('app-2026-09-28.log'));
    $daily = logScanSource('d', 'app.log', daily: true);

    expect(logScanNames(DailyFiles::list($daily, null, CarbonImmutable::parse('2026-10-05'))))->toBe(['app-2026-10-02.log', 'app-2026-10-01.log', 'app-2026-09-30.log', 'app-2026-09-29.log'])
        ->and(logScanNames(DailyFiles::list($daily, CarbonImmutable::parse('2026-09-30 23:59:59'), CarbonImmutable::parse('2026-10-01 00:00:00'))))->toBe(['app-2026-10-01.log', 'app-2026-09-30.log'])
        ->and(logScanNames(DailyFiles::list(logScanSource('d', 'app.log', daily: true, maxFiles: 2), null, CarbonImmutable::parse('2026-10-05'))))->toBe(['app-2026-10-02.log', 'app-2026-10-01.log'])
        ->and(DailyFiles::pattern($daily))->toBe('app-YYYY-MM-DD.log');
});

it('DF2 names files as Monolog does for a path without an extension, and with glob characters in the name', function () {
    LogFiles::write('worker-2026-10-01', ["x\n"]);
    LogFiles::write('app[1]-2026-10-01.log', ["x\n"]);
    LogFiles::write('app1-2026-10-02.log', ["x\n"]);
    $to = CarbonImmutable::parse('2026-10-05');

    expect(logScanNames(DailyFiles::list(logScanSource('w', 'worker', daily: true), null, $to)))->toBe(['worker-2026-10-01'])
        ->and(logScanNames(DailyFiles::list(logScanSource('a', 'app[1].log', daily: true), null, $to)))->toBe(['app[1]-2026-10-01.log'])
        ->and(DailyFiles::pattern(logScanSource('w', 'worker', daily: true)))->toBe('worker-YYYY-MM-DD');
});

it('DF3 reads the day of to and from in the app timezone, as Monolog names the files', function () {
    date_default_timezone_set('Europe/London');
    LogFiles::write('app-2026-07-02.log', ["x\n"]);

    try {
        // 23:30 UTC on July 1 is July 2 in London.
        expect(logScanNames(DailyFiles::list(logScanSource('d', 'app.log', daily: true), CarbonImmutable::parse('2026-07-01T23:30:00Z'), CarbonImmutable::parse('2026-07-01T23:30:00Z'))))->toBe(['app-2026-07-02.log']);
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('SC1 merges two sources by time, newest first, with equal times in config order', function () {
    LogFiles::write('a.log', [LogFiles::line('2026-10-02 09:00:00', 'info', '9:00'), LogFiles::line('2026-10-02 09:02:00', 'info', '9:02')]);
    LogFiles::write('b.log', [LogFiles::line('2026-10-02 09:01:00', 'info', '9:01'), LogFiles::line('2026-10-02 09:02:00', 'info', '9:02')]);

    expect(logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'b.log')])[0])->toBe(['a:9:02', 'b:9:02', 'b:9:01', 'a:9:00']);
});

it('SC2 ends a source at the first entry older than from, so the rest of the file is not read', function () {
    $old = array_fill(0, 3_000, LogFiles::line('2026-10-02 08:00:00', 'info', 'early'));
    LogFiles::write('app.log', [...$old, LogFiles::line('2026-10-02 10:00:00', 'info', 'late')]);

    [$kept, $files, $cut, , $bytes] = logScanRun([logScanSource('s', 'app.log')], from: '2026-10-02 09:00:00');

    expect(filesize(LogFiles::path('app.log')))->toBeGreaterThan(2 * ReverseLineReader::CHUNK_BYTES)
        ->and($kept)->toBe(['s:late'])
        ->and($files)->toBe(['app.log'])
        ->and($cut)->toBeFalse()
        // One chunk is read; the older chunks are never read.
        ->and($bytes)->toBe(ReverseLineReader::CHUNK_BYTES);
});

it('SC3 skips entries after to, and stops when it kept what it wants', function () {
    LogFiles::write('a.log', array_map(fn (int $m): string => LogFiles::line(sprintf('2026-10-02 09:%02d:00', $m), 'info', "{$m}"), range(0, 9)));

    expect(logScanRun([logScanSource('a', 'a.log')], to: '2026-10-02 09:06:00', want: 3)[0])->toBe(['a:6', 'a:5', 'a:4']);
});

it('SC4 marks a cut when the budget runs out, and does not open a file after it', function () {
    LogFiles::write('a.log', [str_repeat(LogFiles::line('2026-10-02 09:00:00', 'info', 'a'), 400)]);
    LogFiles::write('b.log', [LogFiles::line('2026-10-02 08:00:00', 'info', 'b')]);
    $exact = (int) filesize(LogFiles::path('a.log'));

    [, $files, $cut, , $bytes] = logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'b.log')], budget: 10_000);
    // The budget is exactly a.log: b.log is not opened, and that is a cut too.
    [, $exactFiles, $exactCut] = logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'b.log')], budget: $exact);

    expect($cut)->toBeTrue()
        ->and($bytes)->toBe(10_000)
        ->and($files)->toBe(['a.log'])
        ->and($exactFiles)->toBe(['a.log'])
        ->and($exactCut)->toBeTrue();
});

it('SC5 reads a file reached by two sources once, also through a symlink', function () {
    LogFiles::write('a.log', [LogFiles::line('2026-10-02 09:00:00', 'info', 'only once')]);
    symlink(LogFiles::path('a.log'), LogFiles::path('link.log'));

    expect(logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'a.log'), logScanSource('c', 'link.log')])[0])->toBe(['a:only once']);
});

it('SC6 names a file that shrinks during the read and leaves it out of the searched files', function () {
    LogFiles::write('a.log', [str_repeat(LogFiles::line('2026-10-02 09:00:00', 'info', 'line'), 2_000)]);
    LogFiles::write('b.log', [LogFiles::line('2026-10-02 08:00:00', 'info', 'b')]);
    $truncated = false;
    $each = function () use (&$truncated): void {
        if (! $truncated) {
            $truncated = true;
            file_put_contents(LogFiles::path('a.log'), '');
        }
    };

    // want 10000: the scan must go on past the first 64 KB chunk, where the shrink is seen.
    [$kept, $files, $cut, $skipped] = logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'b.log')], want: 10_000, each: $each);

    expect(filesize(LogFiles::path('b.log')))->toBeGreaterThan(0)
        ->and($skipped)->toBe(['File a.log changed while it was read; its older entries were not searched.'])
        ->and($files)->toBe(['b.log'])
        ->and($cut)->toBeFalse()
        ->and(end($kept))->toBe('b:b');
});

it('SC7 names a file with no header, and a missing one, in the order it met them, and still reads the others', function () {
    LogFiles::write('a.log', ["no header here\nnor here\n"]);
    LogFiles::write('c.log', [LogFiles::line('2026-10-02 08:00:00', 'info', 'c')]);

    [$kept, $files, , $skipped] = logScanRun([logScanSource('a', 'a.log'), logScanSource('b', 'b.log'), logScanSource('c', 'c.log')]);

    expect($kept)->toBe(['c:c'])
        ->and($files)->toBe(['c.log'])
        // a.log is read to its start while the scan takes the first entry of each source, so its reason comes first.
        ->and($skipped)->toBe(["File a.log has no entry in Laravel's line format or the JSON format.", 'File b.log does not exist.']);
});
