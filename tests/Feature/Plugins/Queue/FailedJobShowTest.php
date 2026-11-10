<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobShow;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\SendInvoiceJob;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;
use SinaSaeedi\RuntimeMcp\Tools\Tier;

/*
 * 01-24 (spec 5.6 "Job payloads", 6.7, rule 11) on SQLite: queue_failed_job_show on the `file` source, every source
 * with nothing to read, a table on SQLite (not supported), the input, attempts, the payload notes, the exception cut
 * and the tool contract. The table sources on PostgreSQL, MySQL and MariaDB are in
 * tests/Integration/{Pgsql,Mysql}/FailedJobShowTest.php. App timezone: UTC (Testbench).
 */

beforeEach(function () {
    AuditLog::clear();
    failedJobShowDeleteFile();
});

afterEach(function () {
    AuditLog::clear();
    failedJobShowDeleteFile();
    Queue::createPayloadUsing(null);
});

/** The file source of this process (tests in one process run one at a time; no static state). */
function failedJobShowFile(): string
{
    return sys_get_temp_dir().'/rmcp-failed-job-show-'.getmypid().'.json';
}

function failedJobShowDeleteFile(): void
{
    if (file_exists(failedJobShowFile())) {
        chmod(failedJobShowFile(), 0644);
        unlink(failedJobShowFile());
    }
}

/**
 * Boots the package with queue.failed (the file source on the test's own file by default) and allows the tool.
 *
 * @param  array<string, mixed>  $config
 */
function failedJobShowServe(mixed $failed = null, array $config = []): void
{
    $failed ??= ['driver' => 'file', 'path' => failedJobShowFile()];
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'queue.failed' => $failed, ...$config]);
    Gates::allow(FailedJobs::SHOW);
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed> the envelope of one call, checked against the outputSchema
 */
function failedJobShow(array $arguments, ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call(FailedJobs::SHOW, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * One file entry as FileFailedJobProvider::log() writes it.
 *
 * @param  array<string, mixed>  $fields
 */
function failedJobShowWriteFile(array $fields): void
{
    file_put_contents(failedJobShowFile(), (string) json_encode([[
        'id' => 'f-1', 'connection' => 'redis', 'queue' => 'default', 'payload' => FailedJobs::payload(),
        'exception' => 'RuntimeException: boom in /app/x.php:1', 'failed_at' => '2026-10-01 10:00:00',
        'failed_at_timestamp' => 1759312800, ...$fields,
    ]], JSON_PRETTY_PRINT));
}

it('FS1 shows one failed job of the file: metadata, the whole exception, the payload with its data masked, in both eras', function (ProtocolEra $era) {
    failedJobShowServe();
    $exception = new ConnectionException('cURL error 28: Operation timed out');
    $id = FailedJobs::log(SendInvoiceJob::class, $exception, '2026-10-01T23:14:02Z', 'invoices', 'redis', FailedJobs::realPayload(new SendInvoiceJob));
    FailedJobs::log('App\\Jobs\\Other', new RuntimeException('other'), '2026-10-01T23:00:00Z');

    $envelope = failedJobShow(['id' => $id], $era);
    $data = $envelope['data'];
    assert(is_array($data));

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and($envelope['truncated'])->toBeFalse()
        ->and(array_keys($data))->toBe(['id', 'job_class', 'queue', 'connection', 'attempts', 'failed_at', 'exception', 'payload'])
        ->and(array_slice($data, 0, 6))->toBe([
            'id' => $id,
            'job_class' => SendInvoiceJob::class,
            'queue' => 'invoices',
            'connection' => 'redis',
            'attempts' => null,
            'failed_at' => '2026-10-01T23:14:02+00:00',
        ])
        ->and($data['exception'])->toBe((string) $exception)
        ->and(data_get($data, 'payload.uuid'))->toBe($id)
        ->and(data_get($data, 'payload.displayName'))->toBe(SendInvoiceJob::class)
        ->and(data_get($data, 'payload.data'))->toBe(['commandName' => SendInvoiceJob::class, 'command' => Redactor::PAYLOAD, 'batchId' => null]);

    $json = (string) json_encode($envelope);
    expect($json)->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    expect($json)->not->toContain('Acacia');
    expect(AuditLog::entries()[0])->toMatchArray(['tool' => FailedJobs::SHOW, 'outcome' => 'success', 'row_count' => 1, 'params' => ['id' => $id]]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('FS2 says empty, naming the id and the file, when the file does not hold the id or there is no file', function () {
    failedJobShowServe();

    expect(failedJobShow(['id' => 'nope']))->toMatchArray([
        'status' => 'empty', 'note' => 'No failed job with id `nope` in the failed-job file.', 'data' => [],
    ]);

    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');

    expect(failedJobShow(['id' => 'nope']))->toMatchArray(['status' => 'empty', 'note' => 'No failed job with id `nope` in the failed-job file.']);

    // What Laravel writes when no job is left (a flush): that is empty too.
    file_put_contents(failedJobShowFile(), (string) json_encode([], JSON_PRETTY_PRINT));

    expect(failedJobShow(['id' => 'nope']))->toMatchArray(['status' => 'empty']);
});

/* What goes into the file, then the note. all() gives [] for the first two (Laravel's read()). */
const FAILED_JOB_SHOW_BAD_FILES = [
    'empty, as during a write' => FailedJobReader::FILE_BUSY,
    'not json' => FailedJobReader::FILE_FORMAT,
    'too large' => FailedJobReader::FILE_TOO_LARGE,
    'not readable' => FailedJobReader::FILE_UNREADABLE,
    'an entry with no time' => FailedJobReader::FILE_FORMAT,
    'an entry with no exception' => FailedJobReader::FILE_FORMAT,
];

it('FS3 never says empty for a file it cannot read whole, or that Laravel\'s read() cannot decode', function (string $case) {
    failedJobShowServe();
    $path = failedJobShowFile();

    match ($case) {
        'empty, as during a write' => file_put_contents($path, ''),
        'not json' => file_put_contents($path, 'not json'),
        'too large' => file_put_contents($path, '['.str_repeat(' ', FailedJobReader::MAX_FILE_BYTES).']'),
        'not readable' => file_put_contents($path, '[]'),
        'an entry with no time' => failedJobShowWriteFile(['failed_at' => null, 'failed_at_timestamp' => null]),
        default => failedJobShowWriteFile(['exception' => null]),
    };

    if ($case === 'not readable') {
        chmod($path, 0000);
    }

    $note = $case === 'too large'
        ? sprintf(FailedJobReader::FILE_TOO_LARGE, FailedJobReader::MAX_FILE_BYTES + 2, FailedJobReader::MAX_FILE_BYTES)
        : FAILED_JOB_SHOW_BAD_FILES[$case];

    expect(failedJobShow(['id' => 'f-1']))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with(array_keys(FAILED_JOB_SHOW_BAD_FILES));

it('FS4 says try again, never empty, when the file holds the id after Laravel\'s read gave nothing (a write between the reads)', function () {
    failedJobShowServe();
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T23:30:00Z');
    // Laravel's provider, as the reader sees it while a worker truncates the file and writes it again.
    app()->instance(FailedJobProviderInterface::class, new class(failedJobShowFile()) extends FileFailedJobProvider
    {
        /** @return list<object> */
        public function all()
        {
            return [];
        }
    });

    expect(failedJobShow(['id' => $id]))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::FILE_BUSY])
        ->and(failedJobShow(['id' => 'another']))->toMatchArray(['status' => 'empty']);
});

it('FS5 reads only Laravel\'s file provider: an app that rebinds queue.failer is unavailable', function () {
    failedJobShowServe();
    app()->instance(FailedJobProviderInterface::class, new NullFailedJobProvider);

    expect(failedJobShow(['id' => 'f-1']))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::REBOUND]);
});

/* queue.failed, then the note. */
const FAILED_JOB_SHOW_EMPTY_SOURCES = [
    'dynamodb' => [['driver' => 'dynamodb', 'table' => 'failed_jobs', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's'], FailedJobSource::DYNAMODB],
    'driver null' => [['driver' => null, 'table' => 'failed_jobs'], FailedJobSource::NULL_DRIVER],
    'no table' => [['driver' => 'redis'], FailedJobSource::NO_TABLE],
    'a table that is not a name' => [['driver' => 'database', 'table' => 'failed jobs'], FailedJobSource::BAD_TABLE],
    'not an array' => ['database-uuids', FailedJobSource::NOT_AN_ARRAY],
];

it('FS6 is unavailable with the reason for every source with nothing to read (also for an int id), and never resolves queue.failer', function (string $case) {
    [$failed, $note] = FAILED_JOB_SHOW_EMPTY_SOURCES[$case];
    failedJobShowServe($failed);

    foreach (['f-1', '12', '1abc'] as $id) {
        expect(failedJobShow(['id' => $id]))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
    }

    // DynamoDB: building the provider creates an AWS client (rule 4).
    expect(app()->resolved('queue.failer'))->toBeFalse();
})->with(array_keys(FAILED_JOB_SHOW_EMPTY_SOURCES));

it('FS7 says that a table on SQLite is not supported (Testbench\'s default source), never empty', function () {
    failedJobShowServe(['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);

    expect(failedJobShow(['id' => 'f-1']))->toMatchArray([
        'status' => 'unavailable',
        'note' => 'The failed-job tools support PostgreSQL, MySQL and MariaDB; the read-only connection uses sqlite.',
    ]);
});

/* The arguments, then a part of the Invalid input note. */
const FAILED_JOB_SHOW_BAD_INPUT = [
    'no id' => [[], 'id field is required'],
    'an empty id' => [['id' => ''], 'id field is required'],
    'SQL' => [['id' => '1 OR 1=1'], 'id field format is invalid'],
    'a quote' => [['id' => "1'"], 'id field format is invalid'],
    'a trailing newline' => [['id' => "8f1c\n"], 'id field format is invalid'],
    'a backtick' => [['id' => 'a`b'], 'id field format is invalid'],
    'an int' => [['id' => 12], 'id field must be a string'],
    'a list' => [['id' => ['12']], 'id field must be a string'],
    '65 characters' => [['id' => 'a', 'pad' => 65], 'id field must not be greater than 64 characters'],
    'an unknown argument' => [['id' => 'f-1', 'payload' => true], 'The payload argument is not allowed.'],
];

it('FS8 refuses bad input as Invalid input, and audits the call as an error', function (string $case) {
    [$arguments, $message] = FAILED_JOB_SHOW_BAD_INPUT[$case];

    if (isset($arguments['pad'])) {
        $arguments = ['id' => str_repeat('a', 65)];
    }

    failedJobShowServe();
    $envelope = failedJobShow($arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toStartWith('Invalid input: ')->toContain($message)
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => FailedJobs::SHOW, 'decision' => 'allowed', 'outcome' => 'error']);
})->with(array_keys(FAILED_JOB_SHOW_BAD_INPUT));

it('FS9 counts attempts as Laravel does: a Redis payload\'s attempts plus 1, null for every other driver', function () {
    failedJobShowServe();
    // A connection name with a dot: set as one key of the array (a bootWith() key with dots is a config path).
    config(['queue.connections' => [...(array) config('queue.connections'), 'my.redis' => ['driver' => 'redis', 'connection' => 'default']]]);
    $redis = FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 2);
    $attempts = fn (string $connection, ?string $payload = null): mixed => data_get(failedJobShow([
        'id' => FailedJobs::log(SendInvoiceJob::class, new RuntimeException('x'), '2026-10-01T10:00:00Z', 'default', $connection, $payload ?? FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 2)),
    ]), 'data.attempts');

    expect($attempts('redis', $redis))->toBe(3)
        // A connection name with a dot is one name, never a config path.
        ->and($attempts('my.redis'))->toBe(3)
        ->and($attempts('sqs'))->toBeNull()
        ->and($attempts('database'))->toBeNull()
        ->and($attempts('gone'))->toBeNull()
        ->and($attempts('redis', FailedJobs::realPayload(new SendInvoiceJob)))->toBeNull()
        ->and($attempts('redis', (string) json_encode(['uuid' => 'u-neg', 'displayName' => 'A', 'attempts' => -1])))->toBeNull()
        ->and($attempts('redis', (string) json_encode(['uuid' => 'u-str', 'displayName' => 'A', 'attempts' => '2'])))->toBeNull();

    // The payload keeps the stored value.
    $uuid = data_get(json_decode($redis, true), 'uuid');

    expect(data_get(failedJobShow(['id' => is_string($uuid) ? $uuid : '']), 'data.payload.attempts'))->toBe(2);
});

it('FS10 shows the job without job_class and attempts when the payload is over 1,000,000 bytes or not Laravel\'s JSON', function () {
    failedJobShowServe();
    $large = (string) json_encode(['uuid' => 'big', 'displayName' => 'App\\Jobs\\Big', 'attempts' => 1, 'data' => ['command' => str_repeat('x', FailedJobReader::MAX_PAYLOAD_BYTES)]]);
    $edge = (string) json_encode(['uuid' => 'edge', 'displayName' => 'App\\Jobs\\Edge', 'data' => ['command' => str_repeat('x', FailedJobReader::MAX_PAYLOAD_BYTES - 100)]]);
    FailedJobs::log('App\\Jobs\\Big', new RuntimeException('x'), '2026-10-01T10:00:00Z', payload: $large);
    FailedJobs::log('App\\Jobs\\Edge', new RuntimeException('x'), '2026-10-01T10:00:00Z', payload: $edge);

    $big = failedJobShow(['id' => 'big']);

    expect(strlen($large))->toBeGreaterThan(FailedJobReader::MAX_PAYLOAD_BYTES)
        ->and(strlen($edge))->toBeLessThanOrEqual(FailedJobReader::MAX_PAYLOAD_BYTES)
        ->and($big['status'])->toBe('partial')
        ->and($big['note'])->toBe(sprintf(FailedJobReader::PAYLOAD_TOO_LARGE, number_format(strlen($large)), '1,000,000'))
        ->and($big['truncated'])->toBeFalse()
        ->and(data_get($big, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($big, 'data.job_class'))->toBeNull()
        ->and(data_get($big, 'data.attempts'))->toBeNull()
        ->and(data_get($big, 'data.exception'))->toStartWith('RuntimeException: x in ')
        ->and(failedJobShow(['id' => 'edge']))->toMatchArray(['status' => 'ok'])
        ->and(data_get(failedJobShow(['id' => 'edge']), 'data.job_class'))->toBe('App\\Jobs\\Edge');

    failedJobShowWriteFile(['payload' => 'O:8:"stdClass":1:{s:5:"email";s:24:"ada.customer@example.com";}']);
    $invalid = failedJobShow(['id' => 'f-1']);

    expect($invalid)->toMatchArray(['status' => 'partial', 'note' => FailedJobReader::PAYLOAD_INVALID, 'truncated' => false])
        ->and(data_get($invalid, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($invalid, 'data.job_class'))->toBeNull();
    expect((string) json_encode($invalid))->not->toContain(FailedJobs::CUSTOMER_EMAIL);
});

it('FS11 cuts an exception over 200,000 characters after redaction: partial, the bytes left out, a secret at the cut redacted whole', function () {
    // An output cap above the text, to see the tool's own cut (the default cap of 100,000 bytes cuts more: below).
    failedJobShowServe(config: ['runtime-mcp.output.max_bytes' => 2_000_000]);
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    // é is two bytes: the note counts characters, truncated counts bytes.
    // Two-byte characters before and after the cut: characters and bytes differ on both sides.
    // "Bearer " and the token's first 5 characters end at character 200,000: a cut before redaction leaves
    // "Bearer abcde", which no pattern matches (the bearer pattern needs 8). Redacted first, the marker is cut instead.
    $text = 'RuntimeException: '.str_repeat('é', 199_969).' Bearer '.$token.' '.str_repeat('ü', 312_331);
    failedJobShowWriteFile(['exception' => $text]);

    $envelope = failedJobShow(['id' => 'f-1']);
    $exception = data_get($envelope, 'data.exception');
    assert(is_string($exception));
    $shown = mb_substr($text, 0, FailedJobReader::EXCEPTION_CHARACTERS);

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe(sprintf(FailedJobReader::EXCEPTION_CUT, number_format(mb_strlen($text)), '200,000'))
        ->and($envelope['truncated'])->toBe(strlen($text) - strlen($shown))
        ->and(mb_strlen($exception))->toBe(FailedJobReader::EXCEPTION_CHARACTERS)
        ->and($exception)->toEndWith(' Bearer [reda');
    expect($exception)->not->toContain('Bearer abc');

    // Exactly 200,000 characters: shown whole, ok.
    failedJobShowWriteFile(['exception' => str_repeat('é', FailedJobReader::EXCEPTION_CHARACTERS)]);

    expect(failedJobShow(['id' => 'f-1']))->toMatchArray(['status' => 'ok', 'truncated' => false]);

    // With the default cap, 01-17's cut comes on top: its sentence follows the tool's note, and truncated keeps the
    // tool's own count (OutputCap::truncated(): a partial result keeps its count).
    config(['runtime-mcp.output.max_bytes' => 100_000]);
    failedJobShowWriteFile(['exception' => $text]);
    $capped = failedJobShow(['id' => 'f-1']);

    expect($capped['status'])->toBe('partial')
        ->and($capped['note'])->toStartWith(sprintf(FailedJobReader::EXCEPTION_CUT, number_format(mb_strlen($text)), '200,000').' The result was larger than 100000 bytes; ')
        ->and($capped['truncated'])->toBe(strlen($text) - strlen($shown))
        ->and(strlen((string) json_encode($capped, ResultEnvelope::JSON_FLAGS)))->toBeLessThanOrEqual(100_000);
});

it('FS12 masks the keys an app adds with Queue::createPayloadUsing(), and no job data reaches the response or the audit', function () {
    failedJobShowServe();
    Queue::createPayloadUsing(fn (): array => ['tenant' => 'acme-tenant', 'user_email' => 'ada.hook@example.com']);
    $payload = FailedJobs::realPayload(new SendInvoiceJob);
    Queue::createPayloadUsing(null);
    $id = FailedJobs::log(SendInvoiceJob::class, new RuntimeException('Invoice failed'), '2026-10-01T10:00:00Z', payload: $payload);

    $envelope = failedJobShow(['id' => $id]);
    $json = (string) json_encode($envelope);
    $audit = implode("\n", AuditLog::lines());

    expect($payload)->toContain('ada.hook@example.com')
        ->and(data_get($envelope, 'data.payload.tenant'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.payload.user_email'))->toBe(Redactor::PAYLOAD);

    foreach ([FailedJobs::CUSTOMER_EMAIL, 'Acacia', 'ada.hook@example.com', 'acme-tenant'] as $secret) {
        expect($json)->not->toContain($secret);
        expect($audit)->not->toContain($secret);
    }
});

it('FS13 keeps the tool contract: name, tier read_sensitive, description, schema and rules; tools/list reads no file', function () {
    failedJobShowServe();
    file_put_contents(failedJobShowFile(), 'not json');
    $tool = app(QueueFailedJobShow::class);

    assertToolContract($tool, app(QueuePlugin::class));

    expect($tool->name())->toBe('queue_failed_job_show')
        ->and($tool->tier())->toBe(Tier::ReadSensitive)
        ->and($tool->ability())->toBe('runtime-mcp.queue_failed_job_show')
        ->and($tool->description())->toContain('at most 64 characters')->toContain('[redacted:payload]')->toContain('null when the queue driver')
        ->and(currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name'))->toContain(FailedJobs::SHOW)
        ->and(app()->resolved('queue.failer'))->toBeFalse();

    // The client sees the id's limits in the schema (spec 4.3), the same as the rules enforce.
    $tools = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools');
    assert(is_array($tools));
    $show = array_values(array_filter($tools, fn (mixed $tool): bool => is_array($tool) && ($tool['name'] ?? null) === FailedJobs::SHOW))[0] ?? [];

    expect(data_get($show, 'inputSchema.properties.id'))->toMatchArray(['type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9-]{1,64}$'])
        ->and(data_get($show, 'inputSchema.required'))->toBe(['id']);
});

it('FS14 never shows a secret that the read cut short, also when redaction made the text before the cut shorter', function () {
    // 01-24 triage (review M1). An output cap above the text, to see the tool's own cut.
    failedJobShowServe(config: ['runtime-mcp.output.max_bytes' => 2_000_000]);
    // A private key before the cut becomes a 22-character marker; the URL password starts after character 200,000 and
    // the read (202,000 characters) cuts it before its "@", so no pattern matches the part that was read.
    $head = "RuntimeException: -----BEGIN RSA PRIVATE KEY-----\n".str_repeat('A', 3200)."\n-----END RSA PRIVATE KEY----- ";
    $url = 'mysql://app:Sup3rS3cret@db/app';
    $text = $head.str_repeat('x', 201_980 - mb_strlen($head)).$url.' '.str_repeat('ü', 98_000);
    failedJobShowWriteFile(['exception' => $text]);

    $envelope = failedJobShow(['id' => 'f-1']);
    $exception = data_get($envelope, 'data.exception');
    assert(is_string($exception));

    // What is shown is the first 200,000 characters, redacted: never a character the read kept for redaction only.
    expect($exception)->toBe('RuntimeException: [redacted:private_key] '.str_repeat('x', FailedJobReader::EXCEPTION_CHARACTERS - mb_strlen($head)))
        ->and($envelope['note'])->toBe(sprintf(FailedJobReader::EXCEPTION_CUT, number_format(mb_strlen($text)), '200,000'))
        ->and($envelope['truncated'])->toBe(strlen($text) - strlen(mb_substr($text, 0, FailedJobReader::EXCEPTION_CHARACTERS)));
    expect($exception)->not->toContain('Sup3r');

    // A marker after character 200,000 as well: the text up to that marker is whole, so it may be shown; the cut
    // secret after it is still never shown.
    $middle = str_repeat('x', 200_500 - mb_strlen($head)).' Bearer abcdefghijklmnopqrstuvwxyz0123456789 ';
    $text = $head.$middle.str_repeat('y', 201_980 - mb_strlen($head.$middle)).$url.' '.str_repeat('ü', 98_000);
    failedJobShowWriteFile(['exception' => $text]);
    $exception = data_get(failedJobShow(['id' => 'f-1']), 'data.exception');
    assert(is_string($exception));

    expect(mb_strlen($exception))->toBeLessThanOrEqual(FailedJobReader::EXCEPTION_CHARACTERS)
        ->and($exception)->toStartWith('RuntimeException: [redacted:private_key] xxx');
    expect($exception)->not->toContain('Sup3r');
    expect($exception)->not->toContain('mysql://app');
});

/* What goes into the file. Laravel's find() reads $job->id of each entry: a PHP warning for the first three. */
const FAILED_JOB_SHOW_BAD_ENTRIES = [
    'null' => '[null]',
    'a string' => '["x"]',
    'no id' => '[{"connection":"redis"}]',
    'an int id' => '[{"id":5}]',
    'the job, then null' => '[{"id":"f-1","connection":"redis","queue":"default","payload":"{}","exception":"x","failed_at":"2026-10-01 10:00:00"},null]',
];

it('FS15 says the file is not in Laravel\'s format, never an app error, for an entry that is not an object or has no id', function (string $case) {
    // 01-24 triage (review m1): the list says FILE_FORMAT for the same file (fileJob()).
    failedJobShowServe();
    Exceptions::fake();
    file_put_contents(failedJobShowFile(), FAILED_JOB_SHOW_BAD_ENTRIES[$case]);

    expect(failedJobShow(['id' => 'f-1']))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::FILE_FORMAT, 'data' => null]);
    Exceptions::assertNothingReported();
})->with(array_keys(FAILED_JOB_SHOW_BAD_ENTRIES));

it('FS16 is unavailable, never empty, for an id that cannot match an integer key on a table it cannot read', function () {
    // 01-24 triage (review, Suspected 1; 01-21: "an unreadable table is unavailable, never empty"). SQLite: not
    // supported; denied_tables not a list: fail closed. Both before any SQL.
    failedJobShowServe(['driver' => 'database', 'database' => 'testing', 'table' => 'failed_jobs']);

    foreach (['abc', '1abc', '12'] as $id) {
        expect(failedJobShow(['id' => $id]))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'The failed-job tools support PostgreSQL, MySQL and MariaDB; the read-only connection uses sqlite.',
        ]);
    }

    failedJobShowServe(['driver' => 'database', 'database' => 'testing', 'table' => 'failed_jobs'], ['runtime-mcp.database.denied_tables' => 'failed_jobs']);

    expect(failedJobShow(['id' => 'abc']))->toMatchArray(['status' => 'unavailable', 'note' => FailedJobReader::BAD_DENIED_TABLES]);
});
