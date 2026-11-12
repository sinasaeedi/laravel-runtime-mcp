<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ErrorFingerprint;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsRecentErrors;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\Tier;

/*
 * 01-26 (spec 4.4, 5.6, 5.8, 6.10, 11.2 "Envelope", "Redaction", "Protocol eras", rule 11): logs_recent_errors over
 * HTTP with a Gate. Fixtures are written with the real Monolog formatters into a temp directory (LogFiles). Now is
 * 2026-10-02 18:00 UTC (Testbench's app timezone), so the default since is 2026-10-01 18:00 UTC.
 */

const RECENT_ERRORS = 'logs_recent_errors';

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
function recentErrorsServe(array $channels = [LogFiles::SINGLE], array $more = []): void
{
    currentTest()->bootWith(LogFiles::config($channels, $more));
    Gates::allow(RECENT_ERRORS);
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed> the envelope of one call, checked against the outputSchema
 */
function recentErrors(array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call(RECENT_ERRORS, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<array<array-key, mixed>>
 */
function recentErrorsGroups(array $envelope): array
{
    $groups = data_get($envelope, 'data.groups');
    assert(is_array($groups) && array_is_list($groups));

    /** @var list<array<array-key, mixed>> $groups */
    return $groups;
}

/**
 * $count entries, $seconds apart from $start, oldest first.
 *
 * @param  'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'  $level
 * @param  Closure(int): string  $message
 * @return list<string>
 */
function recentErrorsLines(string $start, int $count, int $seconds, string $level, Closure $message): array
{
    $lines = [];
    $time = CarbonImmutable::parse($start);

    for ($i = 0; $i < $count; $i++) {
        $lines[] = LogFiles::line($time->addSeconds($i * $seconds)->format('Y-m-d H:i:s'), $level, $message($i));
    }

    return $lines;
}

it('RE1 groups 300 errors of one cause and 2 of another, ok with no note, in both eras', function (ProtocolEra $era) {
    recentErrorsServe();
    LogFiles::write('app.log', [
        ...recentErrorsLines('2026-10-02 09:00:00', 300, 10, 'error', fn (int $i): string => 'Order '.(11_000 + $i).' failed to sync'),
        ...recentErrorsLines('2026-10-02 09:55:00', 10, 1, 'warning', fn (int $i): string => "Slow query {$i}"),
        LogFiles::line('2026-10-02 12:00:00', 'error', 'Payment 9b2c6f0e-1d2a-4c3b-9f8e-2a1b3c4d5e6f declined'),
        LogFiles::line('2026-10-02 12:30:00', 'error', 'Payment 0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0 declined', ['order' => 7]),
    ]);

    $envelope = recentErrors([], $era);
    $groups = recentErrorsGroups($envelope);

    expect($envelope)->toMatchArray(['status' => 'ok', 'note' => null, 'truncated' => false])
        ->and($envelope['data'])->toMatchArray(['since' => '2026-10-01T18:00:00+00:00', 'entries_read' => 312, 'files' => ['app.log']])
        ->and($groups)->toHaveCount(2)
        ->and($groups[0])->toBe([
            'fingerprint' => ErrorFingerprint::of('error', 'Order {n} failed to sync'),
            'level' => 'error',
            'pattern' => 'Order {n} failed to sync',
            'count' => 300,
            'first_seen' => '2026-10-02T09:00:00+00:00',
            'last_seen' => '2026-10-02T09:49:50+00:00',
            'sample' => [
                'time' => '2026-10-02T09:49:50+00:00',
                'level' => 'error',
                'env' => 'testing',
                'channel' => LogFiles::SINGLE,
                'file' => 'app.log',
                'message' => 'Order 11299 failed to sync',
                'context' => null,
            ],
        ])
        ->and($groups[1])->toMatchArray([
            'pattern' => 'Payment {uuid} declined',
            'count' => 2,
            'first_seen' => '2026-10-02T12:00:00+00:00',
            'last_seen' => '2026-10-02T12:30:00+00:00',
        ])
        ->and($groups[1]['sample'])->toMatchArray(['message' => 'Payment 0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0 declined', 'context' => '{"order":7}']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('RE2 groups messages that differ only in IDs, and keeps levels and other causes apart', function () {
    recentErrorsServe();
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'error', 'Import 01JB4X7ZQ3K9M2N8P5R6S7T0VW failed'),
        LogFiles::line('2026-10-02 09:01:00', 'error', 'Import 01JB4X8AB4K9M2N8P5R6S7T0VW failed'),
        LogFiles::line('2026-10-02 09:02:00', 'error', 'Cache key user_7f3a9c21d4 missing'),
        LogFiles::line('2026-10-02 09:03:00', 'error', 'Cache key user_1b9e0a77c2 missing'),
        LogFiles::line('2026-10-02 09:04:00', 'error', 'Order 1234567 failed'),
        LogFiles::line('2026-10-02 09:05:00', 'error', 'Order 12345678 failed'),
        LogFiles::line('2026-10-02 09:06:00', 'critical', 'Order 99 failed'),
        LogFiles::line('2026-10-02 09:07:00', 'error', 'Refund 5 failed'),
        LogFiles::line('2026-10-02 09:08:00', 'info', 'Order 100 failed'),
    ]);

    $groups = recentErrorsGroups(recentErrors());

    expect(array_map(fn (array $group): array => [$group['level'] ?? null, $group['count'] ?? null, $group['pattern'] ?? null], $groups))->toBe([
        ['error', 2, 'Order {n} failed'],
        ['error', 2, 'Cache key user_{hex} missing'],
        ['error', 2, 'Import {id} failed'],
        ['error', 1, 'Refund {n} failed'],
        ['critical', 1, 'Order {n} failed'],
    ]);
});

it('RE3 shows the most frequent groups up to limit, partial with the count of the rest', function () {
    recentErrorsServe();
    $lines = [];

    // 57 causes (words, no digits); "Cause aa" has 3 entries, "Cause ab" 2, the others 1.
    for ($i = 0; $i < 57; $i++) {
        $word = chr(97 + intdiv($i, 26)).chr(97 + $i % 26);
        $times = $i === 0 ? 3 : ($i === 1 ? 2 : 1);

        for ($n = 0; $n < $times; $n++) {
            $lines[] = LogFiles::line(sprintf('2026-10-02 10:%02d:%02d', $i, $n), 'error', "Cause {$word}");
        }
    }

    LogFiles::write('app.log', $lines);

    $twenty = recentErrors();
    $five = recentErrors(['limit' => 5]);

    expect($twenty)->toMatchArray(['status' => 'partial', 'note' => 'Showing the 20 most frequent of 57 groups.', 'truncated' => 37])
        ->and(recentErrorsGroups($twenty))->toHaveCount(20)
        ->and(array_column(recentErrorsGroups($five), 'pattern'))->toBe(['Cause aa', 'Cause ab', 'Cause ce', 'Cause cd', 'Cause cc'])
        ->and(recentErrors(['limit' => 57]))->toMatchArray(['status' => 'ok', 'note' => null])
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => RECENT_ERRORS, 'row_count' => 20]);
});

it('RE4 is empty, naming the files, when every file was read and no error is in the window', function () {
    recentErrorsServe([LogFiles::DAILY]);
    LogFiles::write('app-2026-10-01.log', [
        LogFiles::line('2026-10-01 17:59:59', 'error', 'before since'),
        LogFiles::line('2026-10-01 19:00:00', 'warning', 'only a warning'),
    ]);
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'info', 'all fine')]);

    expect(recentErrors())->toMatchArray([
        'status' => 'empty',
        'note' => 'No error-level entries since 2026-10-01T18:00:00+00:00 in app-2026-10-01.log and app-2026-10-02.log.',
        'truncated' => false,
        'data' => ['since' => '2026-10-01T18:00:00+00:00', 'groups' => [], 'entries_read' => 2, 'files' => ['app-2026-10-01.log', 'app-2026-10-02.log']],
    ]);
});

it('RE5 says the counts are lower bounds when the budget runs out, with the oldest time it read', function () {
    recentErrorsServe(more: ['runtime-mcp.logs.max_bytes' => 10_000]);
    LogFiles::write('app.log', recentErrorsLines('2026-10-02 08:00:00', 600, 1, 'error', fn (int $i): string => "Order {$i} failed"));
    // The oldest whole entry in the last 10,000 bytes: what the reader reaches.
    $raw = (string) file_get_contents(LogFiles::path('app.log'));
    $tail = substr($raw, -10_000);
    $first = substr($tail, (int) strpos($tail, "\n") + 1, 21);

    $envelope = recentErrors();
    $groups = recentErrorsGroups($envelope);
    $oldest = CarbonImmutable::parse(substr($first, 1, 19))->toIso8601String();

    expect(filesize(LogFiles::path('app.log')))->toBeGreaterThan(20_000)
        ->and($envelope)->toMatchArray([
            'status' => 'partial',
            'note' => "Read the last 10000 bytes (app.log); errors older than {$oldest} may be missing. Counts are lower bounds.",
            'truncated' => false,
        ])
        ->and($groups)->toHaveCount(1)
        ->and($groups[0]['count'])->toBeLessThan(600)
        ->and($groups[0]['first_seen'])->toBe($oldest)
        ->and($groups[0]['last_seen'])->toBe('2026-10-02T08:09:59+00:00');
});

it('RE6 names, for a stack, only a time that is true for every source, or none when a source was not read', function () {
    $big = recentErrorsLines('2026-10-02 08:00:00', 600, 1, 'error', fn (int $i): string => "Order {$i} failed");
    $small = [LogFiles::json('2026-10-02 07:00:00', 'error', 'Queue stalled'), LogFiles::json('2026-10-02 07:30:00', 'error', 'Queue stalled')];

    // The JSON channel is read first (its whole small file), then the line file takes the rest of the budget.
    recentErrorsServe(['jsonFirst'], [
        'runtime-mcp.logs.max_bytes' => 10_000,
        'logging.channels.jsonFirst' => ['driver' => 'stack', 'channels' => [LogFiles::JSON, LogFiles::SINGLE]],
    ]);
    LogFiles::write('app.log', $big);
    LogFiles::write('app-json.log', $small);
    $both = recentErrors();
    $lineOldest = data_get(collect(recentErrorsGroups($both))->firstWhere('pattern', 'Order {n} failed'), 'first_seen');
    $lineOldest = is_string($lineOldest) ? $lineOldest : 'none';

    // The line file takes the whole budget first: the JSON file is never read, so no time is true for it.
    recentErrorsServe(['lineFirst'], [
        'runtime-mcp.logs.max_bytes' => 10_000,
        'logging.channels.lineFirst' => ['driver' => 'stack', 'channels' => [LogFiles::SINGLE, LogFiles::JSON]],
    ]);
    $one = recentErrors();

    expect(data_get($both, 'data.files'))->toBe(['app.log', 'app-json.log'])
        ->and($lineOldest)->toStartWith('2026-10-02T08:')
        ->and($both['note'])->toBe("Read the last 10000 bytes (app.log and app-json.log); errors older than {$lineOldest} may be missing. Counts are lower bounds.")
        ->and(data_get(collect(recentErrorsGroups($both))->firstWhere('pattern', 'Queue stalled'), 'count'))->toBe(2)
        ->and($one)->toMatchArray([
            'status' => 'partial',
            'note' => 'Read the last 10000 bytes (app.log); older errors may be missing. Counts are lower bounds.',
        ])
        ->and(data_get($one, 'data.files'))->toBe(['app.log']);
});

/** runtime-mcp.logs.channels and more config, then the note (spec 11.2 "Envelope": unavailable, never empty). */
const RECENT_ERRORS_UNAVAILABLE = [
    "Laravel's stderr channel" => [['stderr'], [], 'Channel `stderr` writes to stderr, so there is no file to read.'],
    'a missing file' => [[LogFiles::SINGLE], [], 'File app.log does not exist.'],
    'a daily channel with no file' => [[LogFiles::DAILY], [], 'Channel `app_daily` has no file for 2026-10-01 to 2026-10-02 (app-YYYY-MM-DD.log).'],
    'a stack that names only itself' => [['self'], ['logging.channels.self' => ['driver' => 'stack', 'channels' => ['self']]], 'Channel `self` resolves to no log file.'],
    'no channel in logs.channels' => [[], [], LogsRecentErrors::NO_CHANNELS],
];

it('RE7 is unavailable with the reason when it cannot read any file', function (string $case) {
    [$channels, $more, $note] = RECENT_ERRORS_UNAVAILABLE[$case];
    recentErrorsServe($channels, $more);

    expect(recentErrors())->toMatchArray(['status' => 'unavailable', 'note' => $note, 'truncated' => false, 'data' => null]);
})->with(array_keys(RECENT_ERRORS_UNAVAILABLE));

it('RE8 is unavailable for a file in another format larger than the budget, never a cut with no group', function () {
    recentErrorsServe(more: ['runtime-mcp.logs.max_bytes' => 10_000]);
    $lines = [];

    for ($i = 0; $i < 500; $i++) {
        $lines[] = "2026-10-02T09:00:00Z level=error msg=\"logfmt {$i}\"\n";
    }

    LogFiles::write('app.log', $lines);

    expect(recentErrors())->toMatchArray([
        'status' => 'unavailable',
        'note' => "File app.log has no entry in Laravel's line format or the JSON format in its last 10000 bytes.",
    ]);
});

it('RE9 reads the readable part of a stack, partial with "Not searched", also with no error', function () {
    recentErrorsServe(['mixed'], ['logging.channels.mixed' => ['driver' => 'stack', 'channels' => [LogFiles::DAILY, 'stderr']]]);
    $skipped = 'Not searched: Channel `stderr` writes to stderr, so there is no file to read.';
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'info', 'fine')]);
    $none = recentErrors();
    LogFiles::write('app-2026-10-02.log', [LogFiles::line('2026-10-02 09:00:00', 'error', 'Disk full')]);
    $some = recentErrors();

    expect($none)->toMatchArray([
        'status' => 'partial',
        'note' => "No error-level entries since 2026-10-01T18:00:00+00:00 in app-2026-10-02.log. {$skipped}",
        'truncated' => false,
    ])
        ->and($some)->toMatchArray(['status' => 'partial', 'note' => $skipped])
        ->and(array_column(recentErrorsGroups($some), 'pattern'))->toBe(['Disk full']);
});

it('RE10 groups across the channels of a stack, with the newest entry of any channel as the sample', function () {
    recentErrorsServe([LogFiles::STACK]);
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'error', 'Queue 1 stalled'),
        LogFiles::line('2026-10-02 09:02:00', 'error', 'Queue 2 stalled'),
    ]);
    LogFiles::write('app-json.log', [LogFiles::json('2026-10-02 09:03:00', 'error', 'Queue 3 stalled', ['queue' => 'mail'])]);

    $groups = recentErrorsGroups(recentErrors());

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['count' => 3, 'first_seen' => '2026-10-02T09:00:00+00:00', 'last_seen' => '2026-10-02T09:03:00+00:00'])
        ->and($groups[0]['sample'])->toMatchArray([
            'channel' => LogFiles::JSON,
            'file' => 'app-json.log',
            'message' => 'Queue 3 stalled',
            'context' => ['context' => ['queue' => 'mail'], 'extra' => []],
        ]);
});

it('RE11 redacts the samples and builds the pattern from the redacted message, so no part of a secret is shown', function () {
    recentErrorsServe();
    // The provider-shaped keys are split in two so that secret scanners do not flag this fake test data. The Stripe
    // keys have 12 characters after sk_live_: too short for {id}, so a pattern of the raw message would keep "HqLyjWD".
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-02 09:00:00', 'error', 'Stripe call with sk_'.'live_9xQ81LmPcdTy failed'),
        LogFiles::line('2026-10-02 09:01:00', 'error', 'Stripe call with sk_'.'live_4eC39HqLyjWD failed', ['auth' => 'Bearer Qz9XkLmNoPqRsTuVwXyZ01']),
        LogFiles::line('2026-10-02 09:02:00', 'critical', 'Upload with AKIA'.'IOSFODNN7EXAMPLE denied'),
    ]);

    $response = (string) FixtureTools::call(RECENT_ERRORS)->getContent();
    $groups = recentErrorsGroups(recentErrors());

    expect($response)->not->toContain('HqLyjWD')
        ->and($response)->not->toContain('LmPcdTy')
        ->and($response)->not->toContain('Qz9XkLm')
        ->and($response)->not->toContain('IOSFODNN7')
        ->and(array_column($groups, 'pattern'))->toBe(['Stripe call with [redacted:stripe_key] failed', 'Upload with [redacted:aws_access_key] denied'])
        ->and($groups[0]['count'])->toBe(2)
        ->and($groups[0]['sample'])->toMatchArray(['message' => 'Stripe call with [redacted:stripe_key] failed', 'context' => '{"auth":"Bearer [redacted:bearer]"}']);
});

it('RE12 reads from since: 24 hours by default, a date-only since from its start, and an offset', function () {
    recentErrorsServe();
    LogFiles::write('app.log', [
        LogFiles::line('2026-10-01 17:59:59', 'error', 'Cause a'),
        LogFiles::line('2026-10-01 18:00:00', 'error', 'Cause b'),
        LogFiles::line('2026-10-02 00:00:00', 'error', 'Cause c'),
        LogFiles::line('2026-10-02 08:00:00', 'error', 'Cause d'),
    ]);
    $patterns = fn (array $arguments): array => array_column(recentErrorsGroups(recentErrors($arguments)), 'pattern');

    expect($patterns([]))->toBe(['Cause d', 'Cause c', 'Cause b'])
        ->and($patterns(['since' => '2026-10-02']))->toBe(['Cause d', 'Cause c'])
        ->and(data_get(recentErrors(['since' => '2026-10-02']), 'data.since'))->toBe('2026-10-02T00:00:00+00:00')
        ->and($patterns(['since' => '2026-10-02T09:00:00+01:00']))->toBe(['Cause d'])
        ->and($patterns(['since' => '2026-09-01']))->toBe(['Cause d', 'Cause c', 'Cause b', 'Cause a']);
});

it('RE13 reads since without an offset in the app timezone', function () {
    recentErrorsServe();
    date_default_timezone_set('Europe/London');
    LogFiles::write('app.log', ["[2026-10-02 09:30:00] production.ERROR: summer\n"]);

    expect(recentErrors(['since' => '2026-10-02T09:00:00'])['data'])->toMatchArray(['since' => '2026-10-02T09:00:00+01:00'])
        ->and(recentErrorsGroups(recentErrors(['since' => '2026-10-02T09:00:00']))[0]['first_seen'])->toBe('2026-10-02T09:30:00+01:00')
        ->and(recentErrorsGroups(recentErrors(['since' => '2026-10-02T09:31:00'])))->toBe([]);
});

/** Arguments, then a part of the Invalid input note. */
const RECENT_ERRORS_BAD_INPUT = [
    'a channel not in logs.channels' => [['channel' => LogFiles::DAILY], 'channel'],
    'an empty channel' => [['channel' => ''], 'channel'],
    'limit 0' => [['limit' => 0], 'limit'],
    'limit 101' => [['limit' => 101], 'limit'],
    'limit true' => [['limit' => true], 'limit'],
    'since not a date' => [['since' => 'yesterday-ish'], 'since'],
    'since 2026-02-30' => [['since' => '2026-02-30'], 'since'],
    'since in the future' => [['since' => '2026-10-02T18:00:01Z'], 'since'],
    'since of 65 characters' => [['since' => '2026-10-02T09:00:00xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'], 'since'],
    'an unknown argument' => [['level' => 'error'], 'The level argument is not allowed.'],
];

it('RE14 refuses bad input as Invalid input, and audits the call as an error', function (string $case) {
    [$arguments, $message] = RECENT_ERRORS_BAD_INPUT[$case];
    recentErrorsServe();

    $envelope = recentErrors($arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toStartWith('Invalid input: ')->toContain($message)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => RECENT_ERRORS, 'outcome' => 'error']);
})->with(array_keys(RECENT_ERRORS_BAD_INPUT));

it('RE15 takes since equal to now and limit 100, and audits a call with no log text', function () {
    recentErrorsServe();
    LogFiles::write('app.log', [LogFiles::line('2026-10-02 17:00:00', 'error', 'Card 4242 declined')]);

    $now = recentErrors(['since' => '2026-10-02T18:00:00Z', 'limit' => 100]);
    recentErrors();
    $audit = AuditLog::entries();

    expect($now['status'])->toBe('empty')
        ->and($audit)->toHaveCount(2)
        ->and($audit[1])->toMatchArray(['tool' => RECENT_ERRORS, 'decision' => 'allowed', 'outcome' => 'success', 'row_count' => 1])
        ->and(implode("\n", AuditLog::lines()))->not->toContain('declined');
});

it('RE16 keeps the tool contract, also with no valid channel', function (mixed $channels) {
    currentTest()->bootWith(['runtime-mcp.logs.channels' => $channels]);
    $tool = app(LogsRecentErrors::class);

    assertToolContract($tool, app(LogsPlugin::class));

    expect($tool->name())->toBe(RECENT_ERRORS)
        ->and($tool->tier())->toBe(Tier::ReadSensitive)
        ->and($tool->ability())->toBe('runtime-mcp.logs_recent_errors');
})->with([
    'the default' => [['stack']],
    'two channels and a bad entry' => [['stack', 'single', 'a b', 3]],
    'none valid' => [['a b']],
    'not a list' => ['stack'],
]);

it('RE17 hides the tool without a Gate and refuses a call, and the plugin registers it after logs_search', function () {
    currentTest()->bootWith(LogFiles::config());

    $listed = FixtureTools::result(currentTest()->mcpPost('tools/list')->assertOk())['tools'] ?? [];
    $call = FixtureTools::call(RECENT_ERRORS);

    expect(app(LogsPlugin::class)->tools())->toBe([SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsSearch::class, LogsRecentErrors::class])
        ->and(array_column(is_array($listed) ? $listed : [], 'name'))->not->toContain(RECENT_ERRORS)
        ->and((string) $call->getContent())->not->toContain('"groups"')
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => RECENT_ERRORS, 'decision' => 'denied']);
});

it('RE18 lets the output cap drop whole groups from the end, and counts them in truncated', function () {
    recentErrorsServe(more: ['runtime-mcp.output.max_bytes' => 5_000]);
    $lines = [];

    // 40 causes (words, no digits), one entry each, a minute apart: the newest is "Cause bn".
    for ($i = 0; $i < 40; $i++) {
        $word = chr(97 + intdiv($i, 26)).chr(97 + $i % 26);
        $lines[] = LogFiles::line(sprintf('2026-10-02 10:%02d:00', $i), 'error', "Cause {$word} ".str_repeat('x', 100));
    }

    LogFiles::write('app.log', $lines);

    $envelope = recentErrors(['limit' => 40]);
    $groups = recentErrorsGroups($envelope);

    expect($envelope['status'])->toBe('partial')
        ->and(count($groups))->toBeGreaterThan(0)->toBeLessThan(40)
        ->and($envelope['truncated'])->toBe(40 - count($groups))
        ->and($groups[0]['pattern'])->toBe('Cause bn '.str_repeat('x', 100))
        ->and($groups[0]['sample'])->toMatchArray(['message' => 'Cause bn '.str_repeat('x', 100)]);
});

it('RE19 is partial with the cut note, never empty, when the budget runs out on a file with no error', function () {
    recentErrorsServe(more: ['runtime-mcp.logs.max_bytes' => 10_000]);
    LogFiles::write('app.log', recentErrorsLines('2026-10-02 08:00:00', 600, 1, 'info', fn (int $i): string => "Order {$i} shipped"));

    $envelope = recentErrors();
    $note = $envelope['note'];

    expect($envelope)->toMatchArray(['status' => 'partial', 'truncated' => false])
        ->and(data_get($envelope, 'data.groups'))->toBe([])
        ->and($note)->toBeString()->toStartWith('Read the last 10000 bytes (app.log); errors older than 2026-10-02T08:')
        ->and($note)->toEndWith(' may be missing. Counts are lower bounds.')
        ->and($note)->not->toContain('No error-level entries');
});

it('RE20 joins the notes in order (more, cut, not searched) and counts the groups left out on a cut read', function () {
    recentErrorsServe(['cutMixed'], [
        'runtime-mcp.logs.max_bytes' => 10_000,
        'logging.channels.cutMixed' => ['driver' => 'stack', 'channels' => [LogFiles::DAILY, 'stderr']],
    ]);
    // 600 causes (words, no digits), one entry each, a second apart: more groups in the budget than the limit.
    LogFiles::write('app-2026-10-02.log', recentErrorsLines('2026-10-02 08:00:00', 600, 1, 'error', fn (int $i): string => 'Cause '.chr(97 + intdiv($i, 676)).chr(97 + intdiv($i, 26) % 26).chr(97 + $i % 26)));
    $raw = (string) file_get_contents(LogFiles::path('app-2026-10-02.log'));
    $tail = substr($raw, -10_000);
    $oldest = CarbonImmutable::parse(substr($tail, (int) strpos($tail, "\n") + 2, 19))->toIso8601String();

    $envelope = recentErrors(['limit' => 5]);
    $read = data_get($envelope, 'data.entries_read');
    $read = is_int($read) ? $read : 0;

    expect($read)->toBeGreaterThan(5)->toBeLessThan(600)
        ->and($envelope)->toMatchArray([
            'status' => 'partial',
            'note' => "Showing the 5 most frequent of {$read} groups. "
                ."Read the last 10000 bytes (app-2026-10-02.log); errors older than {$oldest} may be missing. Counts are lower bounds. "
                .'Not searched: Channel `stderr` writes to stderr, so there is no file to read.',
            'truncated' => $read - 5,
        ])
        ->and(recentErrorsGroups($envelope))->toHaveCount(5);
});
