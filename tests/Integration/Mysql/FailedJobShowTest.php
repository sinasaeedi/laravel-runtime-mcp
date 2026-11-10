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
 * 01-24 on MySQL and MariaDB (spec 5.6 "Job payloads", 6.7, rules 8 and 11): the same cases as the PostgreSQL file
 * (FSP). CI runs this file on MySQL 8.4, MariaDB 11.4 and 10.11. LENGTH() counts bytes here and CHAR_LENGTH()
 * characters; MySQL and MariaDB read '1abc' as 1 against an integer column (FSM6 proves the trap). Keep the two files
 * in step.
 */

beforeEach(function () {
    Databases::requiresMysql();
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
function mysqlFailedJobShowServe(array $config = [], ?Closure $extend = null): ReadOnlyRole
{
    $role = FailedJobs::serve('testing_mysql', $config, $extend);
    Gates::allow(FailedJobs::SHOW);

    return $role;
}

/** @return array<array-key, mixed> */
function mysqlFailedJobShow(string $id, ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call(FailedJobs::SHOW, ['id' => $id], $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/** One row that Laravel's provider could not write (bad JSON, a huge trace), with the privileged connection. */
function mysqlFailedJobShowInsert(string $uuid, string $payload, string $exception, string $table = 'failed_jobs'): void
{
    DB::connection('testing_mysql')->table($table)->insert([
        'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'payload' => $payload, 'exception' => $exception,
        'failed_at' => '2026-10-01 10:00:00',
    ]);
}

/**
 * The SQL that the read-only connection ran on failed_jobs during $call.
 *
 * @return list<string>
 */
function mysqlFailedJobShowSql(Closure $call): array
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

it('FSM1 shows a real failed job: metadata, Laravel\'s attempts, the whole trace, the payload with its data masked, in both eras', function (ProtocolEra $era) {
    mysqlFailedJobShowServe();
    $exception = new ConnectionException('cURL error 28: Operation timed out');
    // A Redis job that failed on its third try: the stored body says 2 (RedisJob::attempts() adds 1).
    $id = FailedJobs::log(SendInvoiceJob::class, $exception, '2026-10-01T23:14:02Z', 'invoices', 'redis', FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 2));

    $envelope = mysqlFailedJobShow($id, $era);
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

it('FSM2 never returns or audits job data: the serialized job and the keys of createPayloadUsing() are masked', function () {
    mysqlFailedJobShowServe();
    Queue::createPayloadUsing(fn (): array => ['tenant' => 'acme-tenant', 'user_email' => 'ada.hook@example.com']);
    $payload = FailedJobs::realPayload(new SendInvoiceJob);
    Queue::createPayloadUsing(null);
    $id = FailedJobs::log(SendInvoiceJob::class, new RuntimeException('Invoice failed'), '2026-10-01T10:00:00Z', payload: $payload);

    $envelope = mysqlFailedJobShow($id);
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

it('FSM3 never selects a payload over 1,000,000 bytes (counted in bytes, not characters): partial, job_class not shown', function () {
    mysqlFailedJobShowServe();
    // 600,000 characters, 1,200,000 bytes: a character count would read it.
    $large = (string) json_encode(['uuid' => 'big', 'displayName' => 'App\\Jobs\\Big', 'data' => ['command' => str_repeat('é', 600_000)]], JSON_UNESCAPED_UNICODE);
    mysqlFailedJobShowInsert('big', $large, 'RuntimeException: big in /app/x.php:1');
    $envelope = null;

    $sql = mysqlFailedJobShowSql(function () use (&$envelope): void {
        $envelope = mysqlFailedJobShow('big');
    });

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('CASE WHEN LENGTH(`payload`) <= 1000000 THEN `payload` END as payload')
        ->and($sql[0])->toContain('left(`exception`, 202000) as exception')
        ->and($sql[0])->toContain('CHAR_LENGTH(`exception`) as exception_characters')
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

it('FSM4 cuts an exception over 200,000 characters after redaction: the note in characters, truncated in bytes', function () {
    mysqlFailedJobShowServe(['runtime-mcp.output.max_bytes' => 2_000_000]);
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    // Two-byte characters before and after the cut: characters and bytes differ on both sides.
    // "Bearer " and the token's first 5 characters end at character 200,000: a cut before redaction leaves
    // "Bearer abcde", which no pattern matches (the bearer pattern needs 8). Redacted first, the marker is cut instead.
    $text = 'RuntimeException: '.str_repeat('é', 199_969).' Bearer '.$token.' '.str_repeat('ü', 312_331);
    mysqlFailedJobShowInsert('long', FailedJobs::payload(), $text);

    $envelope = mysqlFailedJobShow('long');
    $exception = data_get($envelope, 'data.exception');
    assert(is_string($exception));

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe(sprintf(FailedJobReader::EXCEPTION_CUT, number_format(mb_strlen($text)), '200,000'))
        ->and($envelope['truncated'])->toBe(strlen($text) - strlen(mb_substr($text, 0, 200_000)))
        ->and(mb_strlen($exception))->toBe(200_000)
        ->and($exception)->toEndWith(' Bearer [reda');
    expect($exception)->not->toContain('Bearer abc');
});

it('FSM5 redacts a secret inside the trace (a password in a URL), and shows the rest of the message', function () {
    mysqlFailedJobShowServe();
    $id = FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('Cannot reach postgres://app:s3cret@db/app for 12 Acacia Avenue'), '2026-10-01T10:00:00Z');

    $exception = data_get(mysqlFailedJobShow($id), 'data.exception');

    // Spec 5.6: redaction removes secret formats; other text from an exception message stays (a documented limit).
    expect($exception)->toContain('postgres://app:[redacted:password]@db/app for 12 Acacia Avenue')
        ->and($exception)->not->toContain('s3cret');
});

it('FSM6 answers an id that cannot match an integer key with empty, and the id never reaches SQL (01-21 Q4); a valid id is read', function () {
    mysqlFailedJobShowServe(['queue.failed.driver' => 'database', 'queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
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

    // The trap: MySQL and MariaDB compare '1abc' with an integer column as 1, so a query would show job 1.
    expect(DB::connection('testing_mysql')->table('old_failed_jobs')->where('id', '1abc')->count())->toBe(1);

    foreach (['abc', '1abc', '01', '0', '9223372036854775808', '99999999999999999999', '-1', 'a1'] as $bad) {
        $envelope = null;
        $sql = mysqlFailedJobShowSql(function () use (&$envelope, $bad): void {
            $envelope = mysqlFailedJobShow($bad);
        });

        // 01-24 triage: the read runs (a table that cannot be read stays unavailable), selects no row, binds nothing.
        expect($sql)->toHaveCount(1)
            ->and($sql[0])->toEndWith(' where 1 = 0 limit 1')
            ->and(str_contains($sql[0], '?'))->toBeFalse()
            ->and($envelope)->toMatchArray(['status' => 'empty', 'note' => "No failed job with id `{$bad}` in table `old_failed_jobs`."]);
    }

    expect($id)->toBe('1')
        ->and(mysqlFailedJobShow($id))->toMatchArray(['status' => 'ok'])
        ->and(data_get(mysqlFailedJobShow($id), 'data.id'))->toBe('1')
        ->and(mysqlFailedJobShow('2'))->toMatchArray(['status' => 'empty'])
        ->and(mysqlFailedJobShow('9223372036854775807'))->toMatchArray(['status' => 'empty']);
});

it('FSM7 shows the job without a grant on payload: the payload, job_class and attempts are not shown', function () {
    config(['runtime-mcp.database.masked_columns' => [...(array) config('runtime-mcp.database.masked_columns'), 'payload']]);
    mysqlFailedJobShowServe();
    $id = FailedJobs::log(SendInvoiceJob::class, new RuntimeException('boom'), '2026-10-01T10:00:00Z', payload: FailedJobs::realPayload(new SendInvoiceJob, redisAttempts: 1));

    $envelope = mysqlFailedJobShow($id);

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

it('FSM8 is unavailable, never empty, for a missing, denied or unreadable table', function (string $case) {
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => [DB::connection('testing_mysql')->getDatabaseName().'.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = mysqlFailedJobShowServe($config);
    // Laravel's provider would write to queue.failed.table: for `jobs` an id that is not there is enough.
    $id = $case === 'a default denied table' ? 'f-1' : FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');

    if ($case === 'missing') {
        Schema::connection('testing_mysql')->drop('failed_jobs');
    } elseif ($case === 'no grant') {
        $database = DB::connection('testing_mysql')->getDatabaseName();
        DB::connection('testing_mysql')->statement("REVOKE SELECT ON `{$database}`.`failed_jobs` FROM '{$role->name()}'@'%'");
    }

    $note = match ($case) {
        'missing', 'no grant' => 'Table `failed_jobs` does not exist, or the read-only role cannot read it.',
        'denied', 'denied in its schema' => 'Table `failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        'denied_tables not a list' => FailedJobReader::BAD_DENIED_TABLES,
        default => 'Table `jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
    };

    expect(mysqlFailedJobShow($id))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FSM9 names the statement timeout when the read is stopped (a lock held by another session)', function () {
    mysqlFailedJobShowServe(['runtime-mcp.database.statement_timeout_ms' => 300]);
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');
    $locker = DB::connection('testing_mysql');
    $locker->beginTransaction();

    try {
        // A write lock: the read waits for it until the statement timeout stops it.
        $locker->statement('LOCK TABLES failed_jobs WRITE');

        expect(mysqlFailedJobShow($id))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Reading table `failed_jobs` was stopped by the 300 ms statement timeout.',
        ]);
    } finally {
        $locker->statement('UNLOCK TABLES');
        $locker->rollBack();
    }
});

it('FSM10 shows a job whose payload is not valid JSON (no JSON is parsed in SQL), and reports nothing', function () {
    mysqlFailedJobShowServe();
    mysqlFailedJobShowInsert('bad-json', 'O:8:"stdClass":1:{s:5:"email";s:24:"ada.customer@example.com";}', 'E: x');
    Exceptions::fake();

    $envelope = mysqlFailedJobShow('bad-json');

    expect($envelope)->toMatchArray(['status' => 'partial', 'note' => FailedJobReader::PAYLOAD_INVALID])
        ->and(data_get($envelope, 'data.payload'))->toBe(Redactor::PAYLOAD)
        ->and(data_get($envelope, 'data.exception'))->toBe('E: x');
    expect((string) json_encode($envelope))->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    Exceptions::assertNothingReported();
});

it('FSM11 says empty for an unknown id, names a missing uuid column, and gives ReadOnlySession\'s note for a failed connection', function () {
    mysqlFailedJobShowServe();
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('x'), '2026-10-01T10:00:00Z');

    expect(mysqlFailedJobShow('8f1c0000-0000-0000-0000-000000000000'))->toMatchArray([
        'status' => 'empty', 'note' => 'No failed job with id `8f1c0000-0000-0000-0000-000000000000` in table `failed_jobs`.', 'data' => [],
    ])
        // A uuid table compares text: a number is an id that is not there.
        ->and(mysqlFailedJobShow('12'))->toMatchArray(['status' => 'empty']);

    mysqlFailedJobShowServe(['queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
        Schema::connection($connection)->create('old_failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });

    expect(mysqlFailedJobShow('8f1c'))->toMatchArray([
        'status' => 'unavailable', 'note' => 'Table `old_failed_jobs` has no `uuid` column, but queue.failed.driver is database-uuids.',
    ]);

    mysqlFailedJobShowServe(['database.connections.'.ReadOnlyRole::CONNECTION.'.password' => 'wrong']);
    Exceptions::fake();

    expect(mysqlFailedJobShow('8f1c'))->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::CONNECTION_FAILED]);
    Exceptions::assertNothingReported();
});

it('FSM12 with a table prefix and a schema-qualified table: reads the real table, and a prefixed denied_tables entry denies it', function () {
    $database = DB::connection('testing_mysql')->getDatabaseName();
    mysqlFailedJobShowServe([
        'database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_',
        'queue.failed.table' => $database.'.failed_jobs',
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
    mysqlFailedJobShowInsert('prefixed', FailedJobs::payload(), 'RuntimeException: boom in /app/x.php:1', 'pfx_failed_jobs');

    expect(mysqlFailedJobShow('prefixed'))->toMatchArray(['status' => 'ok'])
        ->and(data_get(mysqlFailedJobShow('prefixed'), 'data.exception'))->toBe('RuntimeException: boom in /app/x.php:1');

    // The role was made before this entry, so only the PHP check stands between the agent and the row.
    config(['runtime-mcp.database.denied_tables' => ['pfx_failed_jobs']]);

    expect(mysqlFailedJobShow('prefixed'))->toMatchArray([
        'status' => 'unavailable', 'note' => "Table `{$database}.failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.",
    ]);
});

it('FSM13 is unavailable, never empty, for an id that cannot match an integer key on a missing, denied or unreadable table', function (string $case) {
    // 01-24 triage (review, Suspected 1; 01-21: "an unreadable table is unavailable, never empty"; DoD 3).
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => [DB::connection('testing_mysql')->getDatabaseName().'.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = mysqlFailedJobShowServe(['queue.failed.driver' => 'database', ...$config]);

    if ($case === 'missing') {
        Schema::connection('testing_mysql')->drop('failed_jobs');
    } elseif ($case === 'no grant') {
        $database = DB::connection('testing_mysql')->getDatabaseName();
        DB::connection('testing_mysql')->statement("REVOKE SELECT ON `{$database}`.`failed_jobs` FROM '{$role->name()}'@'%'");
    }

    $note = match ($case) {
        'missing', 'no grant' => 'Table `failed_jobs` does not exist, or the read-only role cannot read it.',
        'denied', 'denied in its schema' => 'Table `failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        'denied_tables not a list' => FailedJobReader::BAD_DENIED_TABLES,
        default => 'Table `jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
    };

    foreach (['abc', '1abc', '12'] as $id) {
        expect(mysqlFailedJobShow($id))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
    }
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FSM14 says empty, and reports nothing, for an id that is not a uuid on a table whose uuid column has the uuid type', function () {
    // 01-24 triage (review m2): an app migration with $table->uuid('uuid'). MySQL: char(36); MariaDB 10.7+: a native
    // UUID column, which compares 'f-1' as no row.
    mysqlFailedJobShowServe(['queue.failed.table' => 'uuid_failed_jobs'], function (string $connection): void {
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
        expect(mysqlFailedJobShow($other))->toMatchArray([
            'status' => 'empty', 'note' => "No failed job with id `{$other}` in table `uuid_failed_jobs`.", 'data' => [],
        ]);
    }

    expect(mysqlFailedJobShow($id))->toMatchArray(['status' => 'ok'])
        ->and(data_get(mysqlFailedJobShow($id), 'data.id'))->toBe($id);
    Exceptions::assertNothingReported();
});
