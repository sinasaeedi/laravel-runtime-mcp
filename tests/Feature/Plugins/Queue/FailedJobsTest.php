<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsList;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsSummary;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * 01-23 (spec 6.6, 6.8, rules 8 and 11) on SQLite: the `file` source, every source with nothing to read, a table on
 * SQLite (not supported), the inputs, and the tool contract. The table sources on PostgreSQL, MySQL and MariaDB are
 * in tests/Integration/{Pgsql,Mysql}/FailedJobsTest.php. App timezone: UTC (Testbench).
 */

const FAILED_JOB_TOOLS = ['queue_failed_jobs_list', 'queue_failed_jobs_summary'];

beforeEach(function () {
    AuditLog::clear();
    failedJobsDeleteFile();
});

afterEach(function () {
    AuditLog::clear();
    failedJobsDeleteFile();
});

/** The file source of this process (tests in one process run one at a time; no static state). */
function failedJobsFile(): string
{
    return sys_get_temp_dir().'/rmcp-failed-jobs-'.getmypid().'.json';
}

function failedJobsDeleteFile(): void
{
    if (file_exists(failedJobsFile())) {
        chmod(failedJobsFile(), 0644);
        unlink(failedJobsFile());
    }
}

/**
 * Boots the package with queue.failed (the file source on the test's own file by default) and allows both tools.
 *
 * @param  array<string, mixed>  $config
 */
function failedJobsServe(mixed $failed = null, array $config = []): void
{
    $failed ??= ['driver' => 'file', 'path' => failedJobsFile()];
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'queue.failed' => $failed, ...$config]);
    Gates::allow(...FAILED_JOB_TOOLS);
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed> the envelope of one call, checked against the outputSchema
 */
function failedJobsEnvelope(string $tool, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call($tool, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<array<array-key, mixed>> the jobs of a list envelope
 */
function failedJobsJobs(array $envelope): array
{
    $jobs = data_get($envelope, 'data.jobs');
    expect($jobs)->toBeArray();
    assert(is_array($jobs));

    return array_values(array_filter($jobs, is_array(...)));
}

/** Three failures in the file: two SendInvoice (one on `invoices`), one SyncOrder. Returns their IDs, newest first.
 *
 * @return array{string, string, string}
 */
function failedJobsSeedFile(): array
{
    $old = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28: Operation timed out'), '2026-10-01T22:00:00Z', 'invoices');
    $mid = FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('Order 1234 not found'), '2026-10-01T23:00:00Z');
    $new = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7: Failed to connect'), '2026-10-01T23:30:00Z', 'invoices');

    return [$new, $mid, $old];
}

it('FF1 lists the file source newest first, with the one-line reason and no payload, in both protocol eras', function (ProtocolEra $era) {
    failedJobsServe();
    [$new, $mid, $old] = failedJobsSeedFile();

    $envelope = failedJobsEnvelope('queue_failed_jobs_list', [], $era);
    $jobs = failedJobsJobs($envelope);

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and($envelope['truncated'])->toBeFalse()
        ->and($jobs)->toHaveCount(3)
        ->and(array_column($jobs, 'id'))->toBe([$new, $mid, $old])
        ->and(array_keys($jobs[0]))->toBe(['id', 'job_class', 'queue', 'connection', 'failed_at', 'exception'])
        ->and(array_slice($jobs[0], 0, 5))->toBe([
            'id' => $new,
            'job_class' => 'App\\Jobs\\SendInvoice',
            'queue' => 'invoices',
            'connection' => 'redis',
            'failed_at' => '2026-10-01T23:30:00+00:00',
        ])
        ->and($jobs[0]['exception'])->toStartWith('Illuminate\\Http\\Client\\ConnectionException: cURL error 7: Failed to connect in ')
        ->and($jobs[1]['exception'])->toStartWith('RuntimeException: Order 1234 not found in ');
    expect(json_encode($envelope))->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    expect(json_encode($envelope))->not->toContain('Stack trace');
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('FF2 filters exactly by job_class, queue, connection and an inclusive window; the empty note names them', function () {
    failedJobsServe();
    [$new, $mid, $old] = failedJobsSeedFile();
    $ids = fn (array $arguments): array => array_column(failedJobsJobs(failedJobsEnvelope('queue_failed_jobs_list', $arguments)), 'id');

    expect($ids(['job_class' => 'App\\Jobs\\SendInvoice']))->toBe([$new, $old])
        ->and($ids(['job_class' => 'SendInvoice']))->toBe([])
        ->and($ids(['job_class' => 'app\\jobs\\sendinvoice']))->toBe([])
        ->and($ids(['queue' => 'default']))->toBe([$mid])
        ->and($ids(['connection' => 'redis', 'queue' => 'invoices']))->toBe([$new, $old])
        ->and($ids(['connection' => 'sqs']))->toBe([])
        ->and($ids(['from' => '2026-10-01T23:00:00Z']))->toBe([$new, $mid])
        ->and($ids(['to' => '2026-10-01T23:00:00Z']))->toBe([$mid, $old])
        ->and($ids(['from' => '2026-10-01', 'to' => '2026-10-01']))->toBe([$new, $mid, $old])
        ->and($ids(['from' => '2026-10-02T00:00:01+01:00', 'to' => '2026-10-02T00:30:00+01:00']))->toBe([$new]);

    $envelope = failedJobsEnvelope('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'connection' => 'redis', 'from' => '2026-10-01', 'to' => '2026-10-02']);

    expect($envelope['status'])->toBe('empty')
        ->and($envelope['data'])->toBe(['jobs' => []])
        ->and($envelope['note'])->toBe('No failed jobs match job_class=App\\Jobs\\SendInvoice, queue=emails, connection=redis, from 2026-10-01T00:00:00+00:00, to 2026-10-02T23:59:59+00:00 in the failed-job file.');
});

it('FF3 shows at most limit jobs and says when more match; the default limit is 25', function () {
    failedJobsServe();

    for ($i = 0; $i < 30; $i++) {
        FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException("boom {$i}"), sprintf('2026-10-01T10:%02d:00Z', $i));
    }

    $two = failedJobsEnvelope('queue_failed_jobs_list', ['limit' => 2]);
    $default = failedJobsEnvelope('queue_failed_jobs_list');
    $all = failedJobsEnvelope('queue_failed_jobs_list', ['limit' => 30]);

    expect($two['status'])->toBe('partial')
        ->and($two['note'])->toBe('More failed jobs match; showing the newest 2. Narrow with from and to, or job_class.')
        ->and($two['truncated'])->toBeFalse()
        ->and(array_column(failedJobsJobs($two), 'failed_at'))->toBe(['2026-10-01T10:29:00+00:00', '2026-10-01T10:28:00+00:00'])
        ->and(failedJobsJobs($default))->toHaveCount(25)
        ->and($default['note'])->toBe('More failed jobs match; showing the newest 25. Narrow with from and to, or job_class.')
        ->and($all['status'])->toBe('ok')
        ->and(failedJobsJobs($all))->toHaveCount(30);
});

it('FF4 counts the file source by job class and by exception class in the last 24 hours by default', function () {
    failedJobsServe();
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28'), '2026-10-01T10:00:00Z');
    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7'), '2026-10-01T11:00:00Z');
    // An empty message: PHP writes "LogicException in /path:line", with no ": ".
    FailedJobs::log('App\\Jobs\\SyncOrder', new LogicException(''), '2026-10-02T08:00:00Z');
    // A chained exception: PHP prints the previous one first.
    FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('outer', 0, new InvalidArgumentException('inner')), '2026-10-02T08:30:00Z');
    // Older than 24 hours: not counted by default.
    FailedJobs::log('App\\Jobs\\Old', new RuntimeException('old'), '2026-09-30T08:00:00Z');

    $envelope = failedJobsEnvelope('queue_failed_jobs_summary');

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['data'])->toBe([
            'from' => '2026-10-01T09:00:00+00:00',
            'to' => '2026-10-02T09:00:00+00:00',
            'total' => 4,
            'by_job_class' => [
                ['job_class' => 'App\\Jobs\\SendInvoice', 'count' => 2],
                ['job_class' => 'App\\Jobs\\SyncOrder', 'count' => 2],
            ],
            'by_exception_class' => [
                ['exception_class' => 'Illuminate\\Http\\Client\\ConnectionException', 'count' => 2],
                ['exception_class' => 'InvalidArgumentException', 'count' => 1],
                ['exception_class' => 'LogicException', 'count' => 1],
            ],
        ])
        ->and(data_get(failedJobsEnvelope('queue_failed_jobs_summary', ['from' => '2026-09-30']), 'data.total'))->toBe(5);

    $empty = failedJobsEnvelope('queue_failed_jobs_summary', ['from' => '2026-09-01', 'to' => '2026-09-02']);

    expect($empty['status'])->toBe('empty')
        ->and($empty['note'])->toBe('No failed jobs between 2026-09-01T00:00:00+00:00 and 2026-09-02T23:59:59+00:00 in the failed-job file.')
        ->and($empty['data'])->toBe([
            'from' => '2026-09-01T00:00:00+00:00', 'to' => '2026-09-02T23:59:59+00:00', 'total' => 0, 'by_job_class' => [], 'by_exception_class' => [],
        ]);
});

it('FF5 shows the 50 largest groups of each list and says when more exist', function () {
    failedJobsServe(config: ['queue.failed.limit' => 200]);
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    for ($i = 1; $i <= 52; $i++) {
        $class = sprintf('App\\Jobs\\Job%02d', $i);
        $exception = sprintf('App\\Exceptions\\Error%02d', $i);
        $times = $i === 7 ? 3 : 1;

        for ($t = 0; $t < $times; $t++) {
            FailedJobs::log($class, new class($exception) extends RuntimeException
            {
                public function __construct(private readonly string $shown)
                {
                    parent::__construct('failed');
                }

                public function __toString(): string
                {
                    return $this->shown.': failed in /app/x.php:1';
                }
            }, '2026-10-02T08:00:00Z');
        }
    }

    $envelope = failedJobsEnvelope('queue_failed_jobs_summary');
    $classes = data_get($envelope, 'data.by_job_class');
    expect($classes)->toBeArray();
    assert(is_array($classes));

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe('More than 50 job classes failed in this window; by_job_class shows the 50 with the most failures. '
            .'More than 50 exception classes failed in this window; by_exception_class shows the 50 with the most failures.')
        ->and($envelope['truncated'])->toBeFalse()
        ->and(data_get($envelope, 'data.total'))->toBe(54)
        ->and($classes)->toHaveCount(50)
        ->and($classes[0])->toBe(['job_class' => 'App\\Jobs\\Job07', 'count' => 3])
        ->and($classes[1])->toBe(['job_class' => 'App\\Jobs\\Job01', 'count' => 1])
        ->and($classes[49])->toBe(['job_class' => 'App\\Jobs\\Job50', 'count' => 1])
        ->and(data_get($envelope, 'data.by_exception_class.0'))->toBe(['exception_class' => 'App\\Exceptions\\Error07', 'count' => 3])
        ->and(data_get($envelope, 'data.by_exception_class'))->toHaveCount(50);
});

it('FF6 says empty, naming the file, when no job has failed yet (no file)', function () {
    failedJobsServe();

    $list = failedJobsEnvelope('queue_failed_jobs_list');

    expect($list['status'])->toBe('empty')
        ->and($list['note'])->toBe('No failed jobs in the failed-job file.')
        ->and(failedJobsEnvelope('queue_failed_jobs_summary')['status'])->toBe('empty');
});

/* What goes into the file, then the note. A string dataset: a closure in a Pest dataset is not typed (01-09 trap). */
const FAILED_JOB_BAD_FILES = [
    'too large' => FailedJobReader::FILE_TOO_LARGE,
    'not readable' => FailedJobReader::FILE_UNREADABLE,
    'an entry with no id' => FailedJobReader::FILE_FORMAT,
    'an entry that is not an object' => FailedJobReader::FILE_FORMAT,
    'a failed_at that is not a time' => FailedJobReader::FILE_FORMAT,
];

it('FF7 refuses a file over 10,000,000 bytes, an unreadable file and a file not in Laravel\'s format, never empty', function (string $case) {
    failedJobsServe();
    $path = failedJobsFile();
    $entry = ['id' => 'a', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => '2026-10-01 10:00:00'];

    if ($case === 'too large') {
        file_put_contents($path, '['.str_repeat(' ', FailedJobReader::MAX_FILE_BYTES).']');
    } elseif ($case === 'not readable') {
        file_put_contents($path, '[]');
        chmod($path, 0000);
    } elseif ($case === 'an entry with no id') {
        unset($entry['id']);
        file_put_contents($path, (string) json_encode([$entry]));
    } elseif ($case === 'an entry that is not an object') {
        file_put_contents($path, '["job"]');
    } else {
        file_put_contents($path, (string) json_encode([[...$entry, 'failed_at' => 'soon']]));
    }

    $note = $case === 'too large'
        ? sprintf(FailedJobReader::FILE_TOO_LARGE, FailedJobReader::MAX_FILE_BYTES + 2, FailedJobReader::MAX_FILE_BYTES)
        : FAILED_JOB_BAD_FILES[$case];

    foreach (FAILED_JOB_TOOLS as $tool) {
        $envelope = failedJobsEnvelope($tool);

        expect($envelope['status'])->toBe('unavailable')
            ->and($envelope['note'])->toBe($note);
        // Spec 4.3: never a path.
        expect($envelope['note'])->not->toContain($path);
    }
})->with(array_keys(FAILED_JOB_BAD_FILES));

it('FF8 reads only Laravel\'s file provider: an app that rebinds queue.failer is unavailable', function () {
    failedJobsServe();
    app()->instance(FailedJobProviderInterface::class, new NullFailedJobProvider);

    expect(failedJobsEnvelope('queue_failed_jobs_list'))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::REBOUND]);
});

/* queue.failed, then the note. */
const FAILED_JOB_EMPTY_SOURCES = [
    'dynamodb' => [['driver' => 'dynamodb', 'table' => 'failed_jobs', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's'], FailedJobSource::DYNAMODB],
    'driver null' => [['driver' => null, 'table' => 'failed_jobs'], FailedJobSource::NULL_DRIVER],
    "driver 'null'" => [['driver' => 'null'], FailedJobSource::NULL_DRIVER],
    'no table' => [['driver' => 'redis'], FailedJobSource::NO_TABLE],
    'a table that is not a name' => [['driver' => 'database-uuids', 'table' => 'failed jobs'], FailedJobSource::BAD_TABLE],
    'not an array' => ['database-uuids', FailedJobSource::NOT_AN_ARRAY],
];

it('FF9 is unavailable with the reason for every source with nothing to read, and never resolves queue.failer', function (string $case) {
    [$failed, $note] = FAILED_JOB_EMPTY_SOURCES[$case];
    failedJobsServe($failed);

    foreach (FAILED_JOB_TOOLS as $tool) {
        expect(failedJobsEnvelope($tool))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
    }

    // DynamoDB: building the provider creates an AWS client (rule 4); the AWS SDK is not even installed here.
    expect(app()->resolved('queue.failer'))->toBeFalse();
})->with(array_keys(FAILED_JOB_EMPTY_SOURCES));

it('FF10 says that a table on SQLite is not supported (Testbench\'s default source), never empty', function () {
    failedJobsServe(['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);

    foreach (FAILED_JOB_TOOLS as $tool) {
        expect(failedJobsEnvelope($tool))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'The failed-job tools support PostgreSQL, MySQL and MariaDB; the read-only connection uses sqlite.',
        ]);
    }
});

/* The tool, its arguments, and a part of the Invalid input note. */
const FAILED_JOB_BAD_INPUT = [
    'limit 0' => ['queue_failed_jobs_list', ['limit' => 0], 'limit'],
    'limit 101' => ['queue_failed_jobs_list', ['limit' => 101], 'limit'],
    'limit true' => ['queue_failed_jobs_list', ['limit' => true], 'limit'],
    'limit 2.5' => ['queue_failed_jobs_list', ['limit' => 2.5], 'limit'],
    'an empty job_class' => ['queue_failed_jobs_list', ['job_class' => ''], 'job class'],
    'a queue that is not a string' => ['queue_failed_jobs_list', ['queue' => ['default']], 'queue'],
    'from tomorrow' => ['queue_failed_jobs_list', ['from' => 'tomorrow'], 'from'],
    'to 2026-02-30' => ['queue_failed_jobs_summary', ['to' => '2026-02-30'], 'to'],
    'to before from' => ['queue_failed_jobs_list', ['from' => '2026-10-02', 'to' => '2026-10-01T23:00:00Z'], FailedJobWindow::TO_BEFORE_FROM],
    'to before from, summary' => ['queue_failed_jobs_summary', ['from' => '2026-10-02T10:00:00Z', 'to' => '2026-10-02T09:59:59Z'], FailedJobWindow::TO_BEFORE_FROM],
    'an unknown argument' => ['queue_failed_jobs_summary', ['job_class' => 'App\\Jobs\\SendInvoice'], 'The job_class argument is not allowed.'],
];

it('FF11 refuses bad input as Invalid input, and audits the call as an error', function (string $case) {
    [$tool, $arguments, $message] = FAILED_JOB_BAD_INPUT[$case];
    failedJobsServe();

    $envelope = failedJobsEnvelope($tool, $arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toStartWith('Invalid input: ')->toContain($message)
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => $tool, 'decision' => 'allowed', 'outcome' => 'error']);
})->with(array_keys(FAILED_JOB_BAD_INPUT));

it('FF11b refuses a name over 255 characters and a time over 64 characters', function () {
    failedJobsServe();

    foreach ([
        ['queue_failed_jobs_list', ['job_class' => str_repeat('a', 256)], 'job class'],
        ['queue_failed_jobs_list', ['connection' => str_repeat('c', 256)], 'connection'],
        ['queue_failed_jobs_summary', ['from' => '2026-10-01'.str_repeat(' ', 55)], 'from'],
    ] as [$tool, $arguments, $message]) {
        expect(failedJobsEnvelope($tool, $arguments)['note'])->toStartWith('Invalid input: ')->toContain($message);
    }

    expect(failedJobsEnvelope('queue_failed_jobs_list', ['job_class' => str_repeat('a', 255)])['status'])->toBe('empty');
});

it('FF12 redacts the whole first line before it cuts it to 300 characters, so a secret at the cut is not half shown', function () {
    failedJobsServe();
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    // The token starts at character 276 of the first line and runs past character 300 (where an SQL cut would
    // leave "Bearer abcdefghijklmnopqrstuvwx", which matches no pattern); its marker ends before the cut.
    $message = str_repeat('x', 249).' Bearer '.$token.' '.str_repeat('y', 400);
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException($message), '2026-10-01T10:00:00Z');
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('short'), '2026-10-01T09:00:00Z');

    $jobs = failedJobsJobs(failedJobsEnvelope('queue_failed_jobs_list'));
    $line = $jobs[0]['exception'];
    $short = $jobs[1]['exception'];
    assert(is_string($line) && is_string($short));

    expect(mb_strlen($line))->toBe(300)
        ->and($line)->toEndWith('…')
        ->and($line)->toContain('Bearer [redacted:bearer]')
        ->and($short)->toStartWith('RuntimeException: short in ');
    expect($line)->not->toContain(substr($token, 0, 8));
    expect($short)->not->toEndWith('…');
});

it('FF13 keeps the tool contract: names, tier read, descriptions, schema and rules, both directions', function () {
    currentTest()->bootWith();
    $plugin = app(QueuePlugin::class);
    $list = app(QueueFailedJobsList::class);
    $summary = app(QueueFailedJobsSummary::class);

    assertToolContract($list, $plugin);
    assertToolContract($summary, $plugin);

    expect($list->name())->toBe('queue_failed_jobs_list')
        ->and($summary->name())->toBe('queue_failed_jobs_summary')
        ->and($list->ability())->toBe('runtime-mcp.queue_failed_jobs_list')
        ->and($summary->ability())->toBe('runtime-mcp.queue_failed_jobs_summary')
        ->and($list->description())->toContain('never returned')->toContain('limit: 1 to 100, default 25')
        ->and($summary->description())->toContain('the last 24 hours');
});

it('FF14 never builds the reader\'s source in the constructor: tools/list reads no file and resolves no provider', function () {
    failedJobsServe();
    file_put_contents(failedJobsFile(), 'not json');

    $tools = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools');
    assert(is_array($tools));
    $names = array_column($tools, 'name');

    expect($names)->toContain(...FAILED_JOB_TOOLS)
        ->and(app()->resolved('queue.failer'))->toBeFalse();
});

it('FF15 reads the file source by its Unix time, so a later change of the app timezone does not move a failure', function () {
    failedJobsServe();
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T23:30:00Z');

    // The file holds failed_at 2026-10-01 23:30:00 (written in UTC) and its Unix time. Now the app runs in London:
    // Laravel sets PHP's default timezone from app.timezone at boot (as TimestampTest does).
    date_default_timezone_set('Europe/London');

    try {
        $job = failedJobsJobs(failedJobsEnvelope('queue_failed_jobs_list'))[0];
    } finally {
        date_default_timezone_set('UTC');
    }

    expect($job)->toMatchArray(['id' => $id, 'failed_at' => '2026-10-02T00:30:00+01:00']);
});

it('FF16 sorts the file source by time, newest first, also when the jobs were written out of time order', function () {
    failedJobsServe();
    // log() puts each job at the start of the file, so the file order is the write order, not the time order.
    $late = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('late'), '2026-10-01T23:00:00Z');
    $early = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('early'), '2026-10-01T22:00:00Z');

    expect(array_column(failedJobsJobs(failedJobsEnvelope('queue_failed_jobs_list')), 'id'))->toBe([$late, $early]);
});

/* What goes into the file, then the note (01-23 triage: M1 and m1 of the review). Laravel's read() gives [] for all of these. */
const FAILED_JOB_UNDECODED_FILES = [
    'not json' => FailedJobReader::FILE_FORMAT,
    'a JSON object' => FailedJobReader::FILE_FORMAT,
    'a list cut short' => FailedJobReader::FILE_FORMAT,
    'empty, as during a write' => FailedJobReader::FILE_BUSY,
    'an entry with no time' => FailedJobReader::FILE_FORMAT,
    'a relative time' => FailedJobReader::FILE_FORMAT,
];

it('FF17 never says empty for a file that Laravel\'s read() cannot decode, or an entry with no time Laravel writes', function (string $case) {
    failedJobsServe();
    $path = failedJobsFile();
    $entry = ['id' => 'a', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => '2026-10-01 10:00:00'];

    file_put_contents($path, match ($case) {
        'not json' => 'not json',
        'a JSON object' => '{}',
        'a list cut short' => substr((string) json_encode([$entry, $entry], JSON_PRETTY_PRINT), 0, 100),
        'empty, as during a write' => '',
        'an entry with no time' => (string) json_encode([array_diff_key($entry, ['failed_at' => true])]),
        default => (string) json_encode([[...$entry, 'failed_at' => 'yesterday']]),
    });

    foreach (FAILED_JOB_TOOLS as $tool) {
        expect(failedJobsEnvelope($tool))->toMatchArray(['status' => 'unavailable', 'note' => FAILED_JOB_UNDECODED_FILES[$case]]);
    }

    // What Laravel writes when no job is left (a flush): that is empty.
    file_put_contents($path, (string) json_encode([], JSON_PRETTY_PRINT));

    expect(failedJobsEnvelope('queue_failed_jobs_list'))->toMatchArray(['status' => 'empty', 'note' => 'No failed jobs in the failed-job file.']);
})->with(array_keys(FAILED_JOB_UNDECODED_FILES));

it('FF18 says try again, never empty, when the file holds jobs after Laravel\'s read() gave none (a write between the reads)', function () {
    failedJobsServe();
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T23:30:00Z');
    // Laravel's provider, as the reader sees it while a worker truncates the file and writes it again.
    app()->instance(FailedJobProviderInterface::class, new class(failedJobsFile()) extends FileFailedJobProvider
    {
        /** @return array<array-key, mixed> */
        public function all()
        {
            return [];
        }
    });

    foreach (FAILED_JOB_TOOLS as $tool) {
        expect(failedJobsEnvelope($tool))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::FILE_BUSY]);
    }
});
