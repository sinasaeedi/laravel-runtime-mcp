<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\SendInvoiceJob;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * 01-24 on PostgreSQL (spec 5.6 "Job payloads", 6.7, rules 8 and 11): queue_failed_job_show on a table, read as
 * 01-11's read-only role on the read-only connection inside ReadOnlySession (01-14). Laravel's own provider writes
 * the failed jobs (FailedJobs::log()); a row Laravel cannot write (a bad payload, a long trace) is inserted.
 * testing_pgsql is the privileged connection; the default connection stays SQLite. Keep in step with the MySQL file.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(function () {
    AuditLog::clear();
    Queue::createPayloadUsing(null);
});

/**
 * 01-23's FailedJobs::serve() and the show tool's Gate.
 *
 * @param  array<string, mixed>  $config
 * @param  (Closure(string): void)|null  $extend
 */
function pgsqlFailedJobShowServe(array $config = [], ?Closure $extend = null): ReadOnlyRole
{
    $role = FailedJobs::serve('testing_pgsql', $config, $extend);
    Gates::allow(FailedJobs::SHOW);

    return $role;
}

/** @return array<array-key, mixed> */
function pgsqlFailedJobShow(string $id, ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call(FailedJobs::SHOW, ['id' => $id], $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/** One row that Laravel's provider could not write (bad JSON, a huge trace), with the privileged connection. */
function pgsqlFailedJobShowInsert(string $uuid, string $payload, string $exception, string $table = 'failed_jobs'): void
{
    DB::connection('testing_pgsql')->table($table)->insert([
        'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'payload' => $payload, 'exception' => $exception,
        'failed_at' => '2026-10-01 10:00:00',
    ]);
}

/**
 * The SQL that the read-only connection ran on failed_jobs during $call.
 *
 * @return list<string>
 */
function pgsqlFailedJobShowSql(Closure $call): array
{
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$sql): void {
        if ($event->connectionName === ReadOnlyRole::CONNECTION && str_contains($event->sql, 'failed_jobs')) {
            $sql[] = $event->sql;
        }
    });
    $call();

    return $sql;
}

it('FSP1 shows a real failed job: metadata, Laravel\'s attempts, the whole trace, the payload with its data masked, in both eras', function (ProtocolEra $era) {
    pgsqlFailedJobShowServe();
    $exception = new ConnectionException('cURL error 28: Operation timed out');
    // A Redis job that failed on its third try: the stored body says 2 (RedisJob::attempts() adds 1).
    $id = FailedJobs::log(SendInvoiceJob::class, $exception, '2026-10-01T23:14:02Z', 'invoices', 'redis', FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 2));

    $envelope = pgsqlFailedJobShow($id, $era);
    $data = $envelope['data'];
    assert(is_array($data));

    expect($envelope)->toMatchArray(['status' => 'ok', 'note' => null, 'truncated' => false])
        ->and(array_slice($data, 0, 6))->toBe([
            'id' => $id,
            'job_class' => SendInvoiceJob::class,
            'queue' => 'invoices',
            'connection' => 'redis',
            'attempts' => 3,
            'failed_at' => '2026-10-01T23:14:02+00:00',
        ])
        ->and($data['exception'])->toBe((string) $exception)
        ->and(data_get($data, 'payload.attempts'))->toBe(2)
        ->and(data_get($data, 'payload.maxTries'))->toBe(3)
        ->and(data_get($data, 'payload.data'))->toBe(['commandName' => SendInvoiceJob::class, 'command' => Redactor::PAYLOAD, 'batchId' => null]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('FSP2 never returns or audits job data: the serialized job and the keys of createPayloadUsing() are masked', function () {
    pgsqlFailedJobShowServe();
    Queue::createPayloadUsing(fn (): array => ['tenant' => 'acme-tenant', 'user_email' => 'ada.hook@example.com']);
    $payload = FailedJobs::realPayload(new SendInvoiceJob);
    Queue::createPayloadUsing(null);
    $id = FailedJobs::log(SendInvoiceJob::class, new RuntimeException('Invoice failed'), '2026-10-01T10:00:00Z', payload: $payload);

    $envelope = pgsqlFailedJobShow($id);
    $json = (string) json_encode($envelope);
    $audit = implode("\n", AuditLog::lines());

    expect($envelope['status'])->toBe('ok')
        ->and(data_get($envelope, 'data.payload.tenant'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.payload.user_email'))->toBe(Redactor::PAYLOAD)
        ->and($payload)->toContain(FailedJobs::CUSTOMER_EMAIL);

    foreach ([FailedJobs::CUSTOMER_EMAIL, 'Acacia', 'ada.hook@example.com', 'acme-tenant'] as $secret) {
        expect($json)->not->toContain($secret);
        expect($audit)->not->toContain($secret);
    }
});

it('FSP3 never selects a payload over 1,000,000 bytes (counted in bytes, not characters): partial, job_class not shown', function () {
    pgsqlFailedJobShowServe();
    // 600,000 characters, 1,200,000 bytes: a character count would read it.
    $large = (string) json_encode(['uuid' => 'big', 'displayName' => 'App\\Jobs\\Big', 'data' => ['command' => str_repeat('é', 600_000)]], JSON_UNESCAPED_UNICODE);
    pgsqlFailedJobShowInsert('big', $large, 'RuntimeException: big in /app/x.php:1');
    $envelope = null;

    $sql = pgsqlFailedJobShowSql(function () use (&$envelope): void {
        $envelope = pgsqlFailedJobShow('big');
    });

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('CASE WHEN octet_length("payload") <= 1000000 THEN "payload" END as payload')
        ->and($sql[0])->toContain('left("exception", 202000) as exception')
        ->and($sql[0])->not->toMatch('/select \*|\.\*/i')
        ->and($envelope)->toMatchArray([
            'status' => 'partial',
            'note' => sprintf(FailedJobReader::PAYLOAD_TOO_LARGE, number_format(strlen($large)), '1,000,000'),
            'truncated' => false,
        ])
        ->and(data_get($envelope, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.job_class'))->toBeNull()
        ->and(data_get($envelope, 'data.exception'))->toBe('RuntimeException: big in /app/x.php:1');
});

it('FSP4 cuts an exception over 200,000 characters after redaction: the note in characters, truncated in bytes', function () {
    pgsqlFailedJobShowServe(['runtime-mcp.output.max_bytes' => 2_000_000]);
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    // Two-byte characters before and after the cut: characters and bytes differ on both sides.
    // "Bearer " and the token's first 5 characters end at character 200,000: a cut before redaction leaves
    // "Bearer abcde", which no pattern matches (the bearer pattern needs 8). Redacted first, the marker is cut instead.
    $text = 'RuntimeException: '.str_repeat('é', 199_969).' Bearer '.$token.' '.str_repeat('ü', 312_331);
    pgsqlFailedJobShowInsert('long', FailedJobs::payload(), $text);

    $envelope = pgsqlFailedJobShow('long');
    $exception = data_get($envelope, 'data.exception');
    assert(is_string($exception));

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe(sprintf(FailedJobReader::EXCEPTION_CUT, number_format(mb_strlen($text)), '200,000'))
        ->and($envelope['truncated'])->toBe(strlen($text) - strlen(mb_substr($text, 0, 200_000)))
        ->and(mb_strlen($exception))->toBe(200_000)
        ->and($exception)->toEndWith(' Bearer [reda');
    expect($exception)->not->toContain('Bearer abc');
});

it('FSP5 redacts a secret inside the trace (a password in a URL), and shows the rest of the message', function () {
    pgsqlFailedJobShowServe();
    $id = FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('Cannot reach postgres://app:s3cret@db/app for 12 Acacia Avenue'), '2026-10-01T10:00:00Z');

    $exception = data_get(pgsqlFailedJobShow($id), 'data.exception');

    // Spec 5.6: redaction removes secret formats; other text from an exception message stays (a documented limit).
    expect($exception)->toContain('postgres://app:[redacted:password]@db/app for 12 Acacia Avenue')
        ->and($exception)->not->toContain('s3cret');
});

it('FSP6 answers an id that cannot match an integer key with empty, and the id never reaches SQL (01-21 Q4); a valid id is read', function () {
    pgsqlFailedJobShowServe(['queue.failed.driver' => 'database', 'queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
        Schema::connection($connection)->create('old_failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('a'), '2026-10-01T10:00:00Z');

    foreach (['abc', '1abc', '01', '0', '9223372036854775808', '99999999999999999999', '-1', 'a1'] as $bad) {
        $envelope = null;
        $sql = pgsqlFailedJobShowSql(function () use (&$envelope, $bad): void {
            $envelope = pgsqlFailedJobShow($bad);
        });

        // 01-24 triage: the read runs (a table that cannot be read stays unavailable), selects no row, binds nothing.
        expect($sql)->toHaveCount(1)
            ->and($sql[0])->toEndWith(' where 1 = 0 limit 1')
            ->and(str_contains($sql[0], '?'))->toBeFalse()
            ->and($envelope)->toMatchArray(['status' => 'empty', 'note' => "No failed job with id `{$bad}` in table `old_failed_jobs`."]);
    }

    expect($id)->toBe('1')
        ->and(pgsqlFailedJobShow($id))->toMatchArray(['status' => 'ok'])
        ->and(data_get(pgsqlFailedJobShow($id), 'data.id'))->toBe('1')
        ->and(pgsqlFailedJobShow('2'))->toMatchArray(['status' => 'empty'])
        ->and(pgsqlFailedJobShow('9223372036854775807'))->toMatchArray(['status' => 'empty']);
});

it('FSP7 shows the job without a grant on payload: the payload, job_class and attempts are not shown', function () {
    config(['runtime-mcp.database.masked_columns' => [...(array) config('runtime-mcp.database.masked_columns'), 'payload']]);
    pgsqlFailedJobShowServe();
    $id = FailedJobs::log(SendInvoiceJob::class, new RuntimeException('boom'), '2026-10-01T10:00:00Z', payload: FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 1));

    $envelope = pgsqlFailedJobShow($id);

    expect($envelope)->toMatchArray([
        'status' => 'partial',
        'note' => 'The read-only role cannot read `failed_jobs.payload`, so the payload, job_class and attempts are not shown.',
        'truncated' => false,
    ])
        ->and(data_get($envelope, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.job_class'))->toBeNull()
        ->and(data_get($envelope, 'data.attempts'))->toBeNull()
        ->and(data_get($envelope, 'data.exception'))->toStartWith('RuntimeException: boom in ');
});

it('FSP8 is unavailable, never empty, for a missing, denied or unreadable table', function (string $case) {
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => ['public.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = pgsqlFailedJobShowServe($config);
    // Laravel's provider would write to queue.failed.table: for `jobs` an id that is not there is enough.
    $id = $case === 'a default denied table' ? 'f-1' : FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');

    if ($case === 'missing') {
        Schema::connection('testing_pgsql')->drop('failed_jobs');
    } elseif ($case === 'no grant') {
        DB::connection('testing_pgsql')->statement('REVOKE SELECT ON failed_jobs FROM "'.$role->name().'"');
    }

    $note = match ($case) {
        'missing', 'no grant' => 'Table `failed_jobs` does not exist, or the read-only role cannot read it.',
        'denied', 'denied in its schema' => 'Table `failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        'denied_tables not a list' => FailedJobReader::BAD_DENIED_TABLES,
        default => 'Table `jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
    };

    expect(pgsqlFailedJobShow($id))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FSP9 names the statement timeout when the read is stopped (a lock held by another session)', function () {
    pgsqlFailedJobShowServe(['runtime-mcp.database.statement_timeout_ms' => 300]);
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');
    $locker = DB::connection('testing_pgsql');
    $locker->beginTransaction();

    try {
        $locker->statement('LOCK TABLE failed_jobs IN ACCESS EXCLUSIVE MODE');

        expect(pgsqlFailedJobShow($id))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Reading table `failed_jobs` was stopped by the 300 ms statement timeout.',
        ]);
    } finally {
        $locker->rollBack();
    }
});

it('FSP10 shows a job whose payload is not valid JSON (no JSON is parsed in SQL), and reports nothing', function () {
    pgsqlFailedJobShowServe();
    pgsqlFailedJobShowInsert('bad-json', 'O:8:"stdClass":1:{s:5:"email";s:24:"ada.customer@example.com";}', 'E: x');
    Exceptions::fake();

    $envelope = pgsqlFailedJobShow('bad-json');

    expect($envelope)->toMatchArray(['status' => 'partial', 'note' => FailedJobReader::PAYLOAD_INVALID])
        ->and(data_get($envelope, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.exception'))->toBe('E: x');
    expect((string) json_encode($envelope))->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    Exceptions::assertNothingReported();
});

it('FSP11 says empty for an unknown id, names a missing uuid column, and gives ReadOnlySession\'s note for a failed connection', function () {
    pgsqlFailedJobShowServe();
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');

    expect(pgsqlFailedJobShow('8f1c0000-0000-0000-0000-000000000000'))->toMatchArray([
        'status' => 'empty', 'note' => 'No failed job with id `8f1c0000-0000-0000-0000-000000000000` in table `failed_jobs`.', 'data' => [],
    ])
        // A uuid table compares text: a number is an id that is not there.
        ->and(pgsqlFailedJobShow('12'))->toMatchArray(['status' => 'empty']);

    pgsqlFailedJobShowServe(['queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
        Schema::connection($connection)->create('old_failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });

    expect(pgsqlFailedJobShow('8f1c'))->toMatchArray([
        'status' => 'unavailable', 'note' => 'Table `old_failed_jobs` has no `uuid` column, but queue.failed.driver is database-uuids.',
    ]);

    pgsqlFailedJobShowServe(['database.connections.'.ReadOnlyRole::CONNECTION.'.password' => 'wrong']);
    Exceptions::fake();

    expect(pgsqlFailedJobShow('8f1c'))->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::CONNECTION_FAILED]);
    Exceptions::assertNothingReported();
});

it('FSP12 with a table prefix and a schema-qualified table: reads the real table, and a prefixed denied_tables entry denies it', function () {
    pgsqlFailedJobShowServe([
        'database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_',
        'queue.failed.table' => 'public.failed_jobs',
    ], function (string $connection): void {
        Schema::connection($connection)->create('pfx_failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });
    pgsqlFailedJobShowInsert('prefixed', FailedJobs::payload(), 'RuntimeException: boom in /app/x.php:1', 'pfx_failed_jobs');

    expect(pgsqlFailedJobShow('prefixed'))->toMatchArray(['status' => 'ok'])
        ->and(data_get(pgsqlFailedJobShow('prefixed'), 'data.exception'))->toBe('RuntimeException: boom in /app/x.php:1');

    // The role was made before this entry, so only the PHP check stands between the agent and the row.
    config(['runtime-mcp.database.denied_tables' => ['pfx_failed_jobs']]);

    expect(pgsqlFailedJobShow('prefixed'))->toMatchArray([
        'status' => 'unavailable', 'note' => 'Table `public.failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
    ]);
});

it('FSP13 is unavailable, never empty, for an id that cannot match an integer key on a missing, denied or unreadable table', function (string $case) {
    // 01-24 triage (review, Suspected 1; 01-21: "an unreadable table is unavailable, never empty"; DoD 3).
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => ['public.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = pgsqlFailedJobShowServe(['queue.failed.driver' => 'database', ...$config]);

    if ($case === 'missing') {
        Schema::connection('testing_pgsql')->drop('failed_jobs');
    } elseif ($case === 'no grant') {
        DB::connection('testing_pgsql')->statement('REVOKE SELECT ON failed_jobs FROM "'.$role->name().'"');
    }

    $note = match ($case) {
        'missing', 'no grant' => 'Table `failed_jobs` does not exist, or the read-only role cannot read it.',
        'denied', 'denied in its schema' => 'Table `failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        'denied_tables not a list' => FailedJobReader::BAD_DENIED_TABLES,
        default => 'Table `jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
    };

    foreach (['abc', '1abc', '12'] as $id) {
        expect(pgsqlFailedJobShow($id))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
    }
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FSP14 says empty, and reports nothing, for an id that is not a uuid on a table whose uuid column has the uuid type', function () {
    // 01-24 triage (review m2): an app migration with $table->uuid('uuid'). PostgreSQL: `uuid` = 'f-1' fails with
    // 22P02 ("invalid input syntax for type uuid"), which is no such job.
    pgsqlFailedJobShowServe(['queue.failed.table' => 'uuid_failed_jobs'], function (string $connection): void {
        Schema::connection($connection)->create('uuid_failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');
    Exceptions::fake();

    foreach (['12', 'f-1', '8f1c0000-0000-0000-0000-000000000000'] as $other) {
        expect(pgsqlFailedJobShow($other))->toMatchArray([
            'status' => 'empty', 'note' => "No failed job with id `{$other}` in table `uuid_failed_jobs`.", 'data' => [],
        ]);
    }

    expect(pgsqlFailedJobShow($id))->toMatchArray(['status' => 'ok'])
        ->and(data_get(pgsqlFailedJobShow($id), 'data.id'))->toBe($id);
    Exceptions::assertNothingReported();
});
