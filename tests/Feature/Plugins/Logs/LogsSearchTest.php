<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsRecentErrors;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsSearch;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\Tier;

/*
 * 01-25 (spec 4.4, 5.6, 5.8, 6.9, 11.2 "Envelope", rule 11): logs_search over HTTP with a Gate. Fixtures are written
 * with the real Monolog formatters into a temp directory (LogFiles). Now is 2026-10-02 18:00 UTC (Testbench's app
 * timezone), so the daily file choice does not depend on the day the suite runs.
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
 * @param  list<string>  $channels  runtime-mcp.logs.channels
 * @param  array<string, mixed>  $more  more config
 */
function logsSearchServe(array $channels = [LogFiles::SINGLE], array $more = []): void
{
    currentTest()->bootWith(LogFiles::config($channels, $more));
    Gates::allow(LogFiles::TOOL);
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed> the envelope of one call, checked against the outputSchema
 */
function logsSearch(array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call(LogFiles::TOOL, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<array<array-key, mixed>>
 */
function logsSearchEntries(array $envelope): array
{
    $entries = data_get($envelope, 'data.entries');
    assert(is_array($entries) && array_is_list($entries));

    /** @var list<array<array-key, mixed>> $entries */
    return $entries;
}

/** 30 entries a minute apart from 10:00, every 7th an error and the 15th critical: 4 at error or higher. */
function logsSearchThirty(): void
{
    $entries = [];

    for ($i = 0; $i < 30; $i++) {
        $level = $i === 15 ? 'critical' : ($i % 7 === 3 ? 'error' : ['debug', 'info', 'warning'][$i % 3]);
        $entries[] = LogFiles::line(sprintf('2026-10-02 10:%02d:00', $i), $level, "entry {$i}");
    }

    LogFiles::write('app.log', $entries);
}

it('LS1 returns the matching entries newest first, ok with no note, in both eras', function (ProtocolEra $era) {
    logsSearchServe();
    logsSearchThirty();

    $envelope = logsSearch(['level' => 'error'], $era);

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and($envelope['truncated'])->toBeFalse()
        ->and(array_column(logsSearchEntries($envelope), 'message'))->toBe(['entry 24', 'entry 17', 'entry 15', 'entry 10', 'entry 3'])
        ->and(logsSearchEntries($envelope)[2])->toBe([
            'time' => '2026-10-02T10:15:00+00:00',
            'level' => 'critical',
            'env' => 'testing',
            'channel' => LogFiles::SINGLE,
            'file' => 'app.log',
            'message' => 'entry 15',
            'context' => null,
        ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('LS2 reads the first channel by default, every level without a level, and the audit has no log text', function () {
    logsSearchServe([LogFiles::SINGLE, LogFiles::JSON]);
    logsSearchThirty();
    LogFiles::write('app-json.log', [LogFiles::json('2026-10-02 11:00:00', 'error', 'json only')]);

    $envelope = logsSearch(['limit' => 30]);
    $audit = AuditLog::entries();

    expect($envelope['status'])->toBe('ok')
        ->and(count(logsSearchEntries($envelope)))->toBe(30)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0])->toMatchArray(['tool' => LogFiles::TOOL, 'decision' => 'allowed', 'outcome' => 'success', 'row_count' => 30])
        ->and(implode("\n", AuditLog::lines()))->not->toContain('entry 1');
});

it('LS3 searches two daily files, newest first, and names both, oldest first, when nothing matches', function () {
    logsSearchServe([LogFiles::DAILY]);
    LogFiles::write('app-2026-10-01.log', [LogFiles::line('2026-10-01 09:00:00', 'error', 'yesterday')]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'today')]);

    $found = logsSearch(['level' => 'error']);
    $none = logsSearch(['contains' => 'checkout']);

    expect($found['status'])->toBe('ok')
        ->and(array_column(logsSearchEntries($found), 'message'))->toBe(['today', 'yesterday'])
        ->and(array_column(logsSearchEntries($found), 'file'))->toBe(['app-2026-10-02.log', 'app-2026-10-01.log'])
        ->and($none)->toMatchArray([
            'status' => 'empty',
            'note' => 'No match in app-2026-10-01.log and app-2026-10-02.log.',
            'truncated' => false,
            'data' => ['entries' => []],
        ]);
});

/** The input, then the files the empty note names. Files: 09-29 to 10-03; now is 10-02 18:00. */
const LOGS_DAILY_CHOICE = [
    'default: up to today, a later file is not read' => [[], 'app-2026-09-29.log, app-2026-09-30.log, app-2026-10-01.log and app-2026-10-02.log'],
    'from leaves out older days' => [['from' => '2026-09-30T23:00:00Z'], 'app-2026-09-30.log, app-2026-10-01.log and app-2026-10-02.log'],
    'to leaves out later days' => [['to' => '2026-10-01'], 'app-2026-09-29.log, app-2026-09-30.log and app-2026-10-01.log'],
    'one day' => [['from' => '2026-09-30', 'to' => '2026-09-30'], 'app-2026-09-30.log'],
];

it('LS4 chooses the daily files from the time range, and ignores other names', function (string $case) {
    [$arguments, $files] = LOGS_DAILY_CHOICE[$case];
    logsSearchServe([LogFiles::DAILY]);

    foreach (['09-29', '09-30', '10-01', '10-02', '10-03'] as $day) {
        LogFiles::write("app-2026-{$day}.log", [LogFiles::line("2026-{$day} 12:00:00", 'info', 'x')]);
    }

    foreach (['app-2026-10-02.log.gz', 'app-2026-13-01.log', 'app-2026-02-30.log', 'app-json.log', 'app-2026-10-2.log', 'other-2026-10-02.log'] as $other) {
        LogFiles::write($other, ['not a log']);
    }

    expect(logsSearch(['contains' => 'nothing', ...$arguments])['note'])->toBe("No match in {$files}.");
})->with(array_keys(LOGS_DAILY_CHOICE));

it("LS5 reads at most the channel's max_files, else days, newest first", function (array $channel, string $files) {
    logsSearchServe(['capped'], ['logging.channels.capped' => ['driver' => 'daily', 'path' => LogFiles::path('app.log'), ...$channel]]);

    foreach (['09-29', '09-30', '10-01', '10-02'] as $day) {
        LogFiles::write("app-2026-{$day}.log", [LogFiles::line("2026-{$day} 12:00:00", 'info', 'x')]);
    }

    expect(logsSearch(['contains' => 'nothing'])['note'])->toBe("No match in {$files}.");
})->with([
    'Laravel 13: max_files only' => [['max_files' => 2], 'app-2026-10-01.log and app-2026-10-02.log'],
    'Laravel 12: days' => [['days' => 3], 'app-2026-09-30.log, app-2026-10-01.log and app-2026-10-02.log'],
    'days 0 (unlimited): 14' => [['days' => 0], 'app-2026-09-29.log, app-2026-09-30.log, app-2026-10-01.log and app-2026-10-02.log'],
]);

it('LS6 is unavailable, never empty, when a daily channel has no file for the range', function (array $arguments, string $note) {
    logsSearchServe([LogFiles::DAILY]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 12:00:00', 'info', 'x')]);

    expect(logsSearch($arguments))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with([
    'from and to' => [['from' => '2026-09-01', 'to' => '2026-09-02'], 'Channel `app_daily` has no file for 2026-09-01 to 2026-09-02 (app-YYYY-MM-DD.log).'],
    'only to' => [['to' => '2026-09-02'], 'Channel `app_daily` has no file up to 2026-09-02 (app-YYYY-MM-DD.log).'],
]);

it('LS7 says "older entries were not searched" when the budget runs out, never empty, also with matches', function () {
    logsSearchServe(more: ['runtime-mcp.logs.max_bytes' => 10_000]);
    $entries = [];

    for ($i = 0; $i < 600; $i++) {
        $entries[] = LogFiles::line('2026-10-02 08:00:00', 'info', "old {$i}");
    }

    $entries[] = LogFiles::line('2026-10-02 09:00:00', 'error', 'newest error');
    LogFiles::write('app.log', $entries);
    $cut = 'Read the last 10000 bytes (app.log); older entries were not searched.';

    $none = logsSearch(['contains' => 'checkout']);
    $some = logsSearch(['level' => 'error']);

    expect(filesize(LogFiles::path('app.log')))->toBeGreaterThan(20_000)
        ->and($none)->toMatchArray(['status' => 'partial', 'note' => $cut, 'truncated' => false, 'data' => ['entries' => []]])
        ->and($some)->toMatchArray(['status' => 'partial', 'note' => $cut, 'truncated' => false])
        ->and(array_column(logsSearchEntries($some), 'message'))->toBe(['newest error']);
});

it('LS8 reads only the last 200000 bytes of a 300 MB file, in under a second and with flat memory', function () {
    logsSearchServe();
    $path = LogFiles::write('app.log', []);
    $handle = fopen($path, 'r+b');
    assert($handle !== false);
    // A sparse 300 MB file: the first 299.6 MB are a hole, the last 400 KB real lines.
    ftruncate($handle, 300_000_000 - 400_000);
    fseek($handle, 0, SEEK_END);

    while (ftell($handle) < 300_000_000) {
        fwrite($handle, LogFiles::line('2026-10-02 09:00:00', 'info', str_repeat('padding ', 10)));
    }

    fclose($handle);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);

    $envelope = logsSearch(['level' => 'error']);

    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and($envelope)->toMatchArray(['status' => 'partial', 'note' => 'Read the last 200000 bytes (app.log); older entries were not searched.']);
});

/** runtime-mcp.logs.channels (and config), then the note (spec 11.2 "Envelope": unavailable, never empty). */
const LOGS_UNAVAILABLE = [
    "Laravel's stderr channel" => [['stderr'], [], 'Channel `stderr` writes to stderr, so there is no file to read.'],
    'a missing file' => [[LogFiles::SINGLE], [], 'File app.log does not exist.'],
    'a stack of stderr and syslog' => [['both'], ['logging.channels.both' => ['driver' => 'stack', 'channels' => ['stderr', 'syslog']]], 'Channel `stderr` writes to stderr, so there is no file to read. Channel `syslog` writes to the system log, so there is no file to read.'],
    'a stack that names only itself' => [['self'], ['logging.channels.self' => ['driver' => 'stack', 'channels' => ['self']]], 'Channel `self` resolves to no log file.'],
    'no channel in logs.channels' => [[], [], LogsSearch::NO_CHANNELS],
];

it('LS9 is unavailable with the reason when it cannot read any file', function (string $case) {
    [$channels, $more, $note] = LOGS_UNAVAILABLE[$case];
    logsSearchServe($channels, $more);

    expect(logsSearch())->toMatchArray(['status' => 'unavailable', 'note' => $note, 'truncated' => false, 'data' => null]);
})->with(array_keys(LOGS_UNAVAILABLE));

it('LS10 is unavailable for an unreadable file and a file in another format, and empty for an empty file', function () {
    logsSearchServe();
    LogFiles::write('app.log', []);
    $empty = logsSearch();
    LogFiles::write('app.log', ["2026-10-02T09:00:00Z level=error msg=\"logfmt\"\n"]);
    $other = logsSearch();
    chmod(LogFiles::path('app.log'), 0000);
    $unreadable = logsSearch();

    expect($empty)->toMatchArray(['status' => 'empty', 'note' => 'No match in app.log.'])
        ->and($other)->toMatchArray(['status' => 'unavailable', 'note' => "File app.log has no entry in Laravel's line format or the JSON format."]);

    // root reads a file with mode 0000, so the last case would prove nothing: a skip shows it (GitHub's runner is not root).
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        currentTest()->markTestSkipped('root can read every file');
    }

    expect($unreadable)->toMatchArray(['status' => 'unavailable', 'note' => 'File app.log is not readable by the PHP process.']);
});

it('LS11 reads the readable part of a stack, and is partial with "Not searched" for the rest', function () {
    logsSearchServe(['mixed'], ['logging.channels.mixed' => ['driver' => 'stack', 'channels' => [LogFiles::DAILY, 'slack', 'stderr']]]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'from daily')]);
    $skipped = 'Not searched: Channel `slack` sends entries to Slack, so there is no file to read. Channel `stderr` writes to stderr, so there is no file to read.';

    $some = logsSearch();
    $none = logsSearch(['contains' => 'checkout']);

    expect($some)->toMatchArray(['status' => 'partial', 'note' => $skipped, 'truncated' => false])
        ->and(array_column(logsSearchEntries($some), 'message'))->toBe(['from daily'])
        ->and($none)->toMatchArray(['status' => 'partial', 'note' => "No match in app-2026-10-02.log. {$skipped}", 'data' => ['entries' => []]]);
});

it('LS12 merges the channels of a stack by time, and reads a file of two channels once', function () {
    logsSearchServe([LogFiles::STACK], [
        'logging.channels.'.LogFiles::STACK => ['driver' => 'stack', 'channels' => [LogFiles::SINGLE, LogFiles::JSON, 'copy']],
        'logging.channels.copy' => ['driver' => 'single', 'path' => LogFiles::path('app.log')],
    ]);
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'info', 'line 9:00'),
        LogFiles::line('2026-10-02 09:02:00', 'info', 'line 9:02'),
    ]);
    LogFiles::write('app-json.log', [
        LogFiles::json('2026-10-02 09:01:00', 'info', 'json 9:01'),
        LogFiles::json('2026-10-02 09:03:00', 'info', 'json 9:03'),
    ]);

    $envelope = logsSearch();

    expect($envelope['status'])->toBe('ok')
        ->and(array_column(logsSearchEntries($envelope), 'message'))->toBe(['json 9:03', 'line 9:02', 'json 9:01', 'line 9:00'])
        ->and(array_column(logsSearchEntries($envelope), 'channel'))->toBe([LogFiles::JSON, LogFiles::SINGLE, LogFiles::JSON, LogFiles::SINGLE])
        ->and(logsSearchEntries($envelope)[0]['context'])->toBe(['context' => [], 'extra' => []]);
});

it('LS13 keeps an exception as one entry and cuts its context to 2000 bytes with the cut count', function () {
    logsSearchServe();
    // {"cart":"é…é", … : 9 bytes, then two-byte characters, so byte 2,000 falls inside a character.
    LogFiles::write('app.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'Checkout failed', ['cart' => str_repeat('é', 1_500), 'exception' => new RuntimeException('Card declined')])]);
    $raw = (string) file_get_contents(LogFiles::path('app.log'));
    $full = rtrim(substr($raw, (int) strpos($raw, '{"cart"')));

    $entries = logsSearchEntries(logsSearch());
    $context = $entries[0]['context'];
    assert(is_string($context));
    $kept = mb_strcut($full, 0, 2_000, 'UTF-8');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['message'])->toBe('Checkout failed')
        ->and($context)->toBe($kept.'…[cut '.(strlen($full) - strlen($kept)).' bytes]')
        ->and(strlen($kept))->toBe(1_999)
        ->and($full)->toContain('[stacktrace]')
        ->and(mb_check_encoding($context, 'UTF-8'))->toBeTrue();
});

it('LS14 redacts the whole entry before the cut, so a token at the cut is never half shown', function () {
    logsSearchServe();
    // The context JSON is {"pad":"a…a","auth":"Bearer <token>","tail":"b…b"}: the token starts at byte 1997, so a
    // cut first would leave "Bearer Qz9" (3 characters, too short for the bearer pattern).
    $token = 'Qz9XkLmNoPqRsTuVwXyZ01';
    LogFiles::write('app.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'auth eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJl failed', [
        'pad' => str_repeat('a', 1_972), 'auth' => "Bearer {$token}", 'tail' => str_repeat('b', 500),
    ])]);
    $raw = (string) file_get_contents(LogFiles::path('app.log'));
    $context = substr($raw, (int) strpos($raw, '{"pad"'));

    $response = FixtureTools::call(LogFiles::TOOL)->getContent();
    $entry = logsSearchEntries(logsSearch())[0];

    expect(strpos($context, $token))->toBe(1_997)
        ->and($response)->not->toContain('Qz9')
        ->and($response)->not->toContain('eyJhbGciOiJIUzI1NiJ9')
        ->and($entry['message'])->toBe('auth [redacted:jwt] failed')
        // Redacted first, the cut then falls inside the marker: the token never shows.
        ->and($entry['context'])->toEndWith('"auth":"Bearer [re…[cut 526 bytes]');
});

it('LS15 matches contains case-insensitively in the message or the context, and only in the redacted text', function () {
    logsSearchServe();
    // The Stripe-like keys are split in two so that secret scanners do not flag this fake test data.
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'info', 'Checkout started'),
        LogFiles::line('2026-10-02 09:01:00', 'error', 'Payment failed', ['flow' => 'CHECKOUT']),
        LogFiles::line('2026-10-02 09:02:00', 'error', 'Stripe call with sk_'.'live_4eC39HqLyjWDarjtT1zdp7dc failed'),
        LogFiles::line('2026-10-02 09:03:00', 'info', 'Cart viewed'),
        LogFiles::line('2026-10-02 09:04:00', 'warning', 'Webhook signed', ['key' => 'rk_'.'live_9xQ81LmPcdTyw0aZ']),
    ]);

    $checkout = logsSearch(['contains' => 'checkout']);
    // An agent cannot test a guessed prefix of a redacted key: the raw key is never searched.
    $probe = logsSearch(['contains' => 'sk_live_4eC3']);
    $contextProbe = logsSearch(['contains' => 'rk_live_9xQ8']);
    $marker = logsSearch(['contains' => '[redacted:stripe_key]']);

    expect(array_column(logsSearchEntries($checkout), 'message'))->toBe(['Payment failed', 'Checkout started'])
        ->and($probe)->toMatchArray(['status' => 'empty', 'note' => 'No match in app.log.'])
        ->and($contextProbe)->toMatchArray(['status' => 'empty', 'note' => 'No match in app.log.'])
        ->and(array_column(logsSearchEntries($marker), 'message'))->toBe(['Webhook signed', 'Stripe call with [redacted:stripe_key] failed']);
});

it('LS16 says "more entries match" when a match is left out by limit, and is ok at exactly limit', function () {
    logsSearchServe();
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'error', 'e1'),
        LogFiles::line('2026-10-02 09:01:00', 'error', 'e2'),
        LogFiles::line('2026-10-02 09:02:00', 'error', 'e3'),
    ]);

    $two = logsSearch(['limit' => 2]);

    expect($two)->toMatchArray(['status' => 'partial', 'note' => 'More entries match; showing the newest 2. Narrow with from, to, level or contains.', 'truncated' => false])
        ->and(array_column(logsSearchEntries($two), 'message'))->toBe(['e3', 'e2'])
        ->and(logsSearch(['limit' => 3])['status'])->toBe('ok');
});

it('LS17 keeps entries inside from and to, with a date-only to as the whole day', function () {
    logsSearchServe();
    LogFiles::write('app.log', [
        LogFiles::line('2026-09-30 23:59:59', 'info', 'before'),
        LogFiles::line('2026-10-01 00:00:00', 'info', 'first second'),
        LogFiles::line('2026-10-01 23:59:59', 'info', 'last second'),
        LogFiles::line('2026-10-02 00:00:00', 'info', 'after'),
    ]);

    expect(array_column(logsSearchEntries(logsSearch(['from' => '2026-10-01', 'to' => '2026-10-01'])), 'message'))->toBe(['last second', 'first second'])
        ->and(array_column(logsSearchEntries(logsSearch(['from' => '2026-10-01T01:00:00+01:00'])), 'message'))->toBe(['after', 'last second', 'first second']);
});

it('LS18 reads stamps without an offset in the app timezone', function () {
    logsSearchServe();
    date_default_timezone_set('Europe/London');
    LogFiles::write('app.log', ["[2026-07-01 12:00:00] production.ERROR: summer\n"]);

    expect(logsSearchEntries(logsSearch())[0]['time'])->toBe('2026-07-01T12:00:00+01:00');
});

/** Arguments, then a part of the Invalid input note. */
const LOGS_BAD_INPUT = [
    'a channel not in logs.channels' => [['channel' => LogFiles::DAILY], 'channel'],
    'an empty channel' => [['channel' => ''], 'channel'],
    'limit 0' => [['limit' => 0], 'limit'],
    'limit 201' => [['limit' => 201], 'limit'],
    'limit true' => [['limit' => true], 'limit'],
    'level fatal' => [['level' => 'fatal'], 'level'],
    'level ERROR' => [['level' => 'ERROR'], 'level'],
    'an empty contains' => [['contains' => ''], 'contains'],
    'from not a date' => [['from' => 'yesterday-ish'], 'from'],
    'to 2026-02-30' => [['to' => '2026-02-30'], 'to'],
    'to before from' => [['from' => '2026-10-02', 'to' => '2026-10-01T23:00:00Z'], FailedJobWindow::TO_BEFORE_FROM],
    'an unknown argument' => [['grep' => 'x'], 'The grep argument is not allowed.'],
];

it('LS19 refuses bad input as Invalid input, and audits the call as an error', function (string $case) {
    [$arguments, $message] = LOGS_BAD_INPUT[$case];
    logsSearchServe();

    $envelope = logsSearch($arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toStartWith('Invalid input: ')->toContain($message)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => LogFiles::TOOL, 'outcome' => 'error']);
})->with(array_keys(LOGS_BAD_INPUT));

it('LS20 takes a contains of 200 characters and an ISO date-time with an offset, and refuses 201 characters', function () {
    logsSearchServe();
    LogFiles::write('app.log', [LogFiles::line('2026-10-02 09:00:00', 'info', 'x')]);

    expect(logsSearch(['contains' => str_repeat('y', 200), 'from' => '2026-10-02T09:00:00+00:00', 'level' => 'debug', 'limit' => 200])['status'])->toBe('empty')
        ->and(logsSearch(['contains' => str_repeat('y', 201)])['note'])->toStartWith('Invalid input: ')->toContain('contains');
});

it('LS21 keeps the tool contract, also with no valid channel', function (mixed $channels) {
    currentTest()->bootWith(['runtime-mcp.logs.channels' => $channels]);
    $tool = app(LogsSearch::class);

    assertToolContract($tool, app(LogsPlugin::class));

    expect($tool->name())->toBe('logs_search')
        ->and($tool->tier())->toBe(Tier::ReadSensitive)
        ->and($tool->ability())->toBe('runtime-mcp.logs_search');
})->with([
    'the default' => [['stack']],
    'two channels and a bad entry' => [['stack', 'single', 'a b', 3]],
    'none valid' => [['a b']],
    'not a list' => ['stack'],
]);

it('LS22 hides the tool without a Gate, and the plugin registers it', function () {
    currentTest()->bootWith(LogFiles::config());

    expect(app(LogsPlugin::class)->tools())->toBe([LogsSearch::class, LogsRecentErrors::class])
        ->and(FixtureTools::result(currentTest()->mcpPost('tools/list')->assertOk())['tools'] ?? [])
        ->not->toContain(LogFiles::TOOL);
});

it('LS23 keeps a small JSON context as an object, and cuts a large one as its redacted JSON', function () {
    logsSearchServe([LogFiles::JSON]);
    LogFiles::write('app-json.log', [
        LogFiles::json('2026-10-02 09:00:00', 'error', 'big', ['rows' => str_repeat('r', 2_500), 'token' => 'Bearer Kq7Wm2Zx9Lp4Rt8Vn3Yb']),
        LogFiles::json('2026-10-02 09:01:00', 'error', 'small', ['order' => 7]),
    ]);

    $entries = logsSearchEntries(logsSearch());
    $big = $entries[1]['context'];
    assert(is_string($big));

    expect($entries[0]['context'])->toBe(['context' => ['order' => 7], 'extra' => []])
        ->and($big)->toStartWith('{"context":{"rows":"rrr')
        ->and($big)->toMatch('/…\[cut \d+ bytes\]$/u')
        ->and(strlen($big))->toBeLessThan(2_100)
        ->and(json_encode($entries))->not->toContain('Kq7Wm2');
});

it('LS24 is unavailable for a file in another format that is larger than the budget, never a cut search', function () {
    logsSearchServe(more: ['runtime-mcp.logs.max_bytes' => 10_000]);
    $lines = [];

    for ($i = 0; $i < 500; $i++) {
        $lines[] = "2026-10-02T09:00:00Z level=error msg=\"logfmt {$i}\"\n";
    }

    LogFiles::write('app.log', $lines);

    expect(filesize(LogFiles::path('app.log')))->toBeGreaterThan(20_000)
        ->and(logsSearch(['level' => 'error']))->toMatchArray([
            'status' => 'unavailable',
            'note' => "File app.log has no entry in Laravel's line format or the JSON format in its last 10000 bytes.",
            'data' => null,
        ]);
});

it('LS25 redacts the keys of a JSON context too, so a secret used as a key is never shown or searched', function () {
    // The Stripe-like keys are split in two so that secret scanners do not flag this fake test data.
    logsSearchServe([LogFiles::JSON]);
    LogFiles::write('app-json.log', [
        LogFiles::json('2026-10-02 09:00:00', 'warning', 'big', ['rows' => str_repeat('r', 2_500), 'merchants' => ['sk_'.'live_9xC39HqLyjWDarjtT1zdp7dc' => 1]]),
        LogFiles::json('2026-10-02 09:01:00', 'warning', 'small', ['merchants' => ['sk_'.'live_4eC39HqLyjWDarjtT1zdp7dc' => 1], 'order' => 7]),
    ]);

    $response = FixtureTools::call(LogFiles::TOOL)->getContent();
    $entries = logsSearchEntries(logsSearch());
    // The key of the big context lies past the cut: only a search on the raw key would find it.
    $probe = logsSearch(['contains' => 'sk_live_9xC3']);

    expect($response)->not->toContain('sk_live_4eC3')
        ->and($entries[0]['context'])->toBe('{"context":{"merchants":{"[redacted:stripe_key]":1},"order":7},"extra":[]}')
        ->and($entries[1]['context'])->toMatch('/…\[cut \d+ bytes\]$/u')
        ->and($probe)->toMatchArray(['status' => 'empty', 'note' => 'No match in app-json.log.']);
});
