<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntryParser;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;

/*
 * 01-25 Part C: entries from lines that come newest first, in Laravel's line format and Monolog's JSON format. The
 * fixtures come from the real formatters (LogFiles). App timezone: UTC (Testbench), unless a test sets another.
 */

afterEach(fn () => date_default_timezone_set('UTC'));

/**
 * The entries of a file's content (oldest first in the content), newest first, and the header count.
 *
 * @param  LogSource::LINE|LogSource::JSON  $format
 * @return array{list<array{string, string, string, string, string|array<array-key, mixed>|null}>, int}
 */
function logEntriesOf(string $content, string $format = LogSource::LINE): array
{
    $lines = explode("\n", rtrim($content, "\n"));
    $entries = (new LogEntryParser)->entries(array_reverse($lines), $format);
    $out = array_map(
        fn (LogEntry $entry): array => [(string) Timestamp::iso($entry->time), $entry->level, $entry->env, $entry->message, $entry->context],
        iterator_to_array($entries, false),
    );

    return [$out, $entries->getReturn()];
}

it('LP1 reads a plain entry: time in the app timezone, level in lower case, env, message, no context', function () {
    expect(logEntriesOf(LogFiles::line('2026-10-02 14:03:11', 'error', 'Payment failed', env: 'production')))->toBe([
        [['2026-10-02T14:03:11+00:00', 'error', 'production', 'Payment failed', null]],
        1,
    ]);
});

it('LP2 puts the context and the extra after the message, and keeps braces that are no JSON object in the message', function () {
    $line = LogFiles::line('2026-10-02 14:03:11', 'warning', 'Order {id} for [5] failed', ['order' => 5], ['uid' => 'ab12']);

    expect(logEntriesOf($line)[0])->toBe([
        ['2026-10-02T14:03:11+00:00', 'warning', 'testing', 'Order {id} for [5] failed', '{"order":5} {"uid":"ab12"}'],
    ]);
});

it('LP3 keeps an exception with its stack trace as one entry, the trace in the context', function () {
    $content = LogFiles::line('2026-10-02 14:00:00', 'info', 'before')
        .LogFiles::line('2026-10-02 14:03:11', 'error', 'Checkout failed', ['exception' => new RuntimeException('Card declined')])
        .LogFiles::line('2026-10-02 14:05:00', 'info', 'after');
    [$entries, $headers] = logEntriesOf($content);
    $context = $entries[1][4];
    assert(is_string($context));

    expect($headers)->toBe(3)
        ->and(array_column($entries, 3))->toBe(['after', 'Checkout failed', 'before'])
        ->and($context)->toStartWith('{"exception":"[object] (RuntimeException(code: 0): Card declined at ')
        ->and($context)->toContain("\n[stacktrace]\n#0 ")
        ->and($context)->toEndWith('"}')
        ->and(substr_count($context, "\n"))->toBeGreaterThan(3);
});

it('LP4 keeps a line break inside a message: the first line is the message, the rest the context', function () {
    expect(logEntriesOf(LogFiles::line('2026-10-02 14:03:11', 'notice', "first line\nsecond line\nthird"))[0])->toBe([
        ['2026-10-02T14:03:11+00:00', 'notice', 'testing', 'first line', "second line\nthird"],
    ]);
});

it('LP5 reads a stamp with an offset as that offset, and one without in the app timezone', function (string $timezone, string $stamp, string $iso) {
    date_default_timezone_set($timezone);

    expect(logEntriesOf("[{$stamp}] production.ERROR: x")[0][0][0])->toBe($iso);
})->with([
    'Laravel stamp, UTC' => ['UTC', '2026-07-02 14:03:11', '2026-07-02T14:03:11+00:00'],
    'Laravel stamp, London summer' => ['Europe/London', '2026-07-02 14:03:11', '2026-07-02T14:03:11+01:00'],
    'Laravel stamp, London winter' => ['Europe/London', '2026-12-02 14:03:11', '2026-12-02T14:03:11+00:00'],
    'an offset, shown in the app timezone' => ['UTC', '2026-07-02T14:03:11+02:00', '2026-07-02T12:03:11+00:00'],
    'an offset without a colon' => ['UTC', '2026-07-02T14:03:11-0500', '2026-07-02T19:03:11+00:00'],
    'Z and a fraction' => ['Europe/London', '2026-07-02T14:03:11.250Z', '2026-07-02T15:03:11+01:00'],
]);

it('LP6 attaches a line that is no header to the entry above it, and drops the lines above the oldest header', function () {
    $content = "a stray line from a cut entry\n"
        .LogFiles::line('2026-10-02 14:00:00', 'info', 'first')
        ."PHP Warning: something printed by hand\n"
        .LogFiles::line('2026-10-02 14:01:00', 'error', 'second')
        ."  a line under second\n";

    // The lines under `second` are read first (backwards) and belong to it alone, never also to `first`.
    expect(logEntriesOf($content))->toBe([[
        ['2026-10-02T14:01:00+00:00', 'error', 'testing', 'second', '  a line under second'],
        ['2026-10-02T14:00:00+00:00', 'info', 'testing', 'first', 'PHP Warning: something printed by hand'],
    ], 2]);
});

it('LP7 takes no header for a stamp that does not read back, or for a level Monolog does not have', function (string $line) {
    $content = LogFiles::line('2026-03-01 10:00:00', 'info', 'kept')."{$line}\n";

    expect(logEntriesOf($content))->toBe([[['2026-03-01T10:00:00+00:00', 'info', 'testing', 'kept', $line]], 1]);
})->with([
    'February 30' => ['[2026-02-30 10:00:00] testing.ERROR: forged'],
    'hour 25' => ['[2026-03-01 25:00:00] testing.ERROR: forged'],
    'an unknown level' => ['[2026-03-01 10:00:01] testing.FATAL: forged'],
    'a level in lower case' => ['[2026-03-01 10:00:01] testing.error: forged'],
    'no dot before the level' => ['[2026-03-01 10:00:01] ERROR: forged'],
]);

it('LP8 skips a DST gap time: it does not read back in the app timezone', function () {
    date_default_timezone_set('Europe/London');

    expect(logEntriesOf('[2026-03-29 01:30:00] production.ERROR: in the gap')[1])->toBe(0)
        ->and(logEntriesOf('[2026-03-29 02:30:00] production.ERROR: after the gap')[1])->toBe(1);
});

it('LP9 reads Monolog JSON records, and keeps both context and extra', function () {
    $content = LogFiles::json('2026-10-02 14:03:11', 'critical', 'Disk full', ['disk' => '/data'], ['request_id' => 'r-1'], 'production')
        .LogFiles::json('2026-10-02 14:04:00', 'debug', 'tick');

    expect(logEntriesOf($content, LogSource::JSON))->toBe([[
        ['2026-10-02T14:04:00+00:00', 'debug', 'testing', 'tick', ['context' => [], 'extra' => []]],
        ['2026-10-02T14:03:11+00:00', 'critical', 'production', 'Disk full', ['context' => ['disk' => '/data'], 'extra' => ['request_id' => 'r-1']]],
    ], 2]);
});

it('LP10 reads a JSON stamp with microseconds and an offset, and keeps text lines under a record as text (an empty object decodes to [])', function () {
    $content = '{"message":"m","context":{"a":1},"level":400,"level_name":"ERROR","channel":"prod","datetime":"2026-10-02T14:03:11.123456+02:00","extra":{}}'
        ."\nnot json\n";

    expect(logEntriesOf($content, LogSource::JSON)[0])->toBe([
        ['2026-10-02T12:03:11+00:00', 'error', 'prod', 'm', "{\"context\":{\"a\":1},\"extra\":[]}\nnot json"],
    ]);
});

it('LP11 takes no header for a JSON line without the record fields (a renamed formatter, a line-format line)', function (string $line) {
    expect(logEntriesOf($line, LogSource::JSON))->toBe([[], 0]);
})->with([
    'GoogleCloudLoggingFormatter keys' => ['{"message":"m","severity":"ERROR","time":"2026-10-02T14:03:11.000+00:00","channel":"x"}'],
    'LogglyFormatter keys' => ['{"message":"m","level_name":"ERROR","timestamp":"2026-10-02T14:03:11.000000+0000"}'],
    'a line-format line' => ['[2026-10-02 14:03:11] testing.ERROR: m'],
    'not an object' => ['[1,2,3]'],
    'an unknown level' => ['{"message":"m","level_name":"FATAL","datetime":"2026-10-02T14:03:11+00:00"}'],
    'broken JSON' => ['{"message":"m",'],
]);

it('LP12 ranks the levels from debug to emergency', function () {
    expect(LogEntryParser::rank('debug'))->toBe(0)
        ->and(LogEntryParser::rank('error'))->toBe(4)
        ->and(LogEntryParser::rank('emergency'))->toBe(7)
        ->and(LogEntryParser::rank('fatal'))->toBe(-1);
});

it("LP13 reads Monolog's own line format (formatter 'default'): an offset stamp, and no empty [] markers", function () {
    $content = LogFiles::monologLine('2026-10-02 14:03:11', 'error', "Boom\nsecond", env: 'production')
        .LogFiles::monologLine('2026-10-02 14:04:00', 'info', 'Order {id} for [5]', ['order' => 5])
        .LogFiles::monologLine('2026-10-02 14:05:00', 'info', 'With extra', [], ['uid' => 'u1']);

    expect(logEntriesOf($content))->toBe([[
        ['2026-10-02T14:05:00+00:00', 'info', 'testing', 'With extra', '{"uid":"u1"}'],
        ['2026-10-02T14:04:00+00:00', 'info', 'testing', 'Order {id} for [5]', '{"order":5}'],
        ['2026-10-02T14:03:11+00:00', 'error', 'production', 'Boom second', null],
    ], 3]);
});
