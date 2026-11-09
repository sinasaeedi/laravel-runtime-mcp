<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * 01-23 on MySQL and MariaDB (spec 4.2, 4.3, 6.6, 6.8, rules 8 and 11): the same cases as the PostgreSQL file (FJP),
 * plus NO_BACKSLASH_ESCAPES (FJM14). CI runs this file on MySQL 8.4, MariaDB 11.4 and 10.11. MariaDB reads an
 * invalid JSON payload as NULL, where PostgreSQL and MySQL refuse the query (FJM10). Keep the two files in step.
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

function mysqlFailedJobsIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function mysqlFailedJobs(string $tool, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call($tool, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<array<array-key, mixed>>
 */
function mysqlFailedJobsJobs(array $envelope): array
{
    $jobs = data_get($envelope, 'data.jobs');
    expect($jobs)->toBeArray();
    assert(is_array($jobs));

    return array_values(array_filter($jobs, is_array(...)));
}

/** @return array{string, string, string, string} four failures, newest first; the two newest in the same second */
function mysqlFailedJobsSeed(): array
{
    $old = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28: Operation timed out'), '2026-10-01T21:00:00Z', 'invoices');
    $mid = FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('Order 1234 not found'), '2026-10-01T22:00:00Z');
    $first = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7: Failed to connect'), '2026-10-01T23:30:00Z', 'invoices');
    $second = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 6: Could not resolve host'), '2026-10-01T23:30:00Z', 'invoices', 'sqs');

    return [$second, $first, $mid, $old];
}

it('FJM1 lists the newest failures first (a tie: the newer row first), with the one-line reason, in both eras', function (ProtocolEra $era) {
    FailedJobs::serve('testing_mysql');
    [$second, $first, $mid, $old] = mysqlFailedJobsSeed();

    $envelope = mysqlFailedJobs('queue_failed_jobs_list', [], $era);
    $jobs = mysqlFailedJobsJobs($envelope);

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and(array_column($jobs, 'id'))->toBe([$second, $first, $mid, $old])
        ->and(array_slice($jobs[0], 0, 5))->toBe([
            'id' => $second,
            'job_class' => 'App\\Jobs\\SendInvoice',
            'queue' => 'invoices',
            'connection' => 'sqs',
            'failed_at' => '2026-10-01T23:30:00+00:00',
        ])
        ->and($jobs[0]['exception'])->toStartWith('Illuminate\\Http\\Client\\ConnectionException: cURL error 6: Could not resolve host in ')
        ->and($jobs[2]['exception'])->toStartWith('RuntimeException: Order 1234 not found in ');
    expect((string) json_encode($envelope))->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    expect((string) json_encode($envelope))->not->toContain('Stack trace');
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('FJM2 filters exactly by job_class (with backslashes), queue, connection and an inclusive window', function () {
    FailedJobs::serve('testing_mysql');
    [$second, $first, $mid, $old] = mysqlFailedJobsSeed();
    $ids = fn (array $arguments): array => array_column(mysqlFailedJobsJobs(mysqlFailedJobs('queue_failed_jobs_list', $arguments)), 'id');

    expect($ids(['job_class' => 'App\\Jobs\\SendInvoice']))->toBe([$second, $first, $old])
        ->and($ids(['job_class' => 'SendInvoice']))->toBe([])
        ->and($ids(['queue' => 'default']))->toBe([$mid])
        ->and($ids(['connection' => 'sqs']))->toBe([$second])
        ->and($ids(['queue' => 'invoices', 'connection' => 'redis']))->toBe([$first, $old])
        ->and($ids(['from' => '2026-10-01T22:00:00Z']))->toBe([$second, $first, $mid])
        ->and($ids(['to' => '2026-10-01T22:00:00Z']))->toBe([$mid, $old])
        ->and($ids(['from' => '2026-10-01', 'to' => '2026-10-01']))->toBe([$second, $first, $mid, $old])
        ->and($ids(['from' => '2026-10-02T00:30:00+01:00', 'to' => '2026-10-02']))->toBe([$second, $first]);
});

it('FJM3 limits in the database: limit + 1 rows, partial with a note; empty names the filters and the table', function () {
    FailedJobs::serve('testing_mysql');
    mysqlFailedJobsSeed();

    $two = mysqlFailedJobs('queue_failed_jobs_list', ['limit' => 2]);
    $none = mysqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\Nope', 'from' => '2026-10-01']);

    expect($two['status'])->toBe('partial')
        ->and($two['note'])->toBe('More failed jobs match; showing the newest 2. Narrow with from and to, or job_class.')
        ->and($two['truncated'])->toBeFalse()
        ->and(mysqlFailedJobsJobs($two))->toHaveCount(2)
        ->and($none['status'])->toBe('empty')
        ->and($none['note'])->toBe('No failed jobs match job_class=App\\Jobs\\Nope, from 2026-10-01T00:00:00+00:00 in table `failed_jobs`.')
        ->and($none['data'])->toBe(['jobs' => []]);
});

it('FJM4 counts by job class and by exception class in the database, in the last 24 hours by default', function () {
    FailedJobs::serve('testing_mysql');
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28'), '2026-10-01T10:00:00Z');
    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7'), '2026-10-01T11:00:00Z');
    FailedJobs::log('App\\Jobs\\SyncOrder', new LogicException(''), '2026-10-02T08:00:00Z');
    FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('outer', 0, new InvalidArgumentException('inner')), '2026-10-02T08:30:00Z');
    FailedJobs::log('App\\Jobs\\Old', new RuntimeException('old'), '2026-09-30T08:00:00Z');

    expect(mysqlFailedJobs('queue_failed_jobs_summary'))->toMatchArray(['status' => 'ok', 'data' => [
        'from' => '2026-10-01T09:00:00+00:00',
        'to' => '2026-10-02T09:00:00+00:00',
        'total' => 4,
        'by_job_class' => [['job_class' => 'App\\Jobs\\SendInvoice', 'count' => 2], ['job_class' => 'App\\Jobs\\SyncOrder', 'count' => 2]],
        'by_exception_class' => [
            ['exception_class' => 'Illuminate\\Http\\Client\\ConnectionException', 'count' => 2],
            ['exception_class' => 'InvalidArgumentException', 'count' => 1],
            ['exception_class' => 'LogicException', 'count' => 1],
        ],
    ]])
        ->and(mysqlFailedJobs('queue_failed_jobs_summary', ['to' => '2026-09-30']))->toMatchArray(['status' => 'ok'])
        ->and(mysqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-09-01', 'to' => '2026-09-02']))->toMatchArray([
            'status' => 'empty',
            'note' => 'No failed jobs between 2026-09-01T00:00:00+00:00 and 2026-09-02T23:59:59+00:00 in table `failed_jobs`.',
        ]);
});

it('FJM5 shows the 50 largest groups and says when more exist', function () {
    FailedJobs::serve('testing_mysql');
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    for ($i = 1; $i <= 51; $i++) {
        FailedJobs::log(sprintf('App\\Jobs\\Job%02d', $i), new RuntimeException('x'), '2026-10-02T08:00:00Z');
    }

    $envelope = mysqlFailedJobs('queue_failed_jobs_summary');

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe('More than 50 job classes failed in this window; by_job_class shows the 50 with the most failures.')
        ->and(data_get($envelope, 'data.by_job_class'))->toHaveCount(50)
        ->and(data_get($envelope, 'data.by_job_class.0'))->toBe(['job_class' => 'App\\Jobs\\Job01', 'count' => 1])
        ->and(data_get($envelope, 'data.by_exception_class'))->toBe([['exception_class' => 'RuntimeException', 'count' => 51]]);
});

it('FJM6 never selects the payload or the exception whole: only the projections leave the database', function () {
    FailedJobs::serve('testing_mysql');
    mysqlFailedJobsSeed();
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$sql): void {
        if ($event->connectionName === ReadOnlyRole::CONNECTION && str_contains($event->sql, 'failed_jobs')) {
            $sql[] = $event->sql;
        }
    });

    mysqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']);
    mysqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01']);

    expect($sql)->toHaveCount(4); // the list, then the count and the two groups

    foreach ($sql as $statement) {
        $left = str_replace(["JSON_EXTRACT(`payload`, '$.displayName')", 'SUBSTRING_INDEX(`exception`, CHAR(10), 1)'], '', $statement);

        expect($left)->not->toContain('`payload`');
        expect($left)->not->toContain('`exception`');
        expect($statement)->not->toMatch('/select \*|\.\*/i');
    }
});

it('FJM7 reads a `database` table by id, and says that a database-uuids table needs its uuid column', function () {
    FailedJobs::serve('testing_mysql', ['queue.failed.driver' => 'database', 'queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
        Schema::connection($connection)->create('old_failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    });
    $a = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('a'), '2026-10-01T10:00:00Z');
    $b = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException('b'), '2026-10-01T10:00:00Z');

    expect(array_column(mysqlFailedJobsJobs(mysqlFailedJobs('queue_failed_jobs_list')), 'id'))->toBe([$b, $a])
        ->and($a)->toMatch('/^\d+$/');

    config(['queue.failed.driver' => 'database-uuids']);

    expect(mysqlFailedJobs('queue_failed_jobs_list'))->toMatchArray([
        'status' => 'unavailable', 'note' => 'Table `old_failed_jobs` has no `uuid` column, but queue.failed.driver is database-uuids.',
    ]);
});

it('FJM8 is unavailable, never empty, for a missing, denied or unreadable table', function (string $case) {
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => [DB::connection('testing_mysql')->getDatabaseName().'.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = FailedJobs::serve('testing_mysql', $config);

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

    foreach (FailedJobs::TOOLS as $tool) {
        expect(mysqlFailedJobs($tool))->toMatchArray(['status' => 'unavailable', 'note' => $note]);
    }

    // With a job_class filter too: a refused table is not reported as a refused payload column.
    expect(mysqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']))->toMatchArray(['status' => 'unavailable', 'note' => $note]);
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FJM9 works without a grant on payload: job_class is not shown, and the job_class filter is unavailable', function () {
    // 01-11's docs let an operator grant failed_jobs without payload; the role is made from this mask.
    config(['runtime-mcp.database.masked_columns' => [...(array) config('runtime-mcp.database.masked_columns'), 'payload']]);
    FailedJobs::serve('testing_mysql');
    mysqlFailedJobsSeed();
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));
    $hidden = 'The read-only role cannot read `failed_jobs.payload`, so job_class is not shown.';

    $list = mysqlFailedJobs('queue_failed_jobs_list');
    $summary = mysqlFailedJobs('queue_failed_jobs_summary');

    expect($list['status'])->toBe('partial')
        ->and($list['note'])->toBe($hidden)
        ->and(array_column(mysqlFailedJobsJobs($list), 'job_class'))->toBe([null, null, null, null])
        ->and(data_get($list, 'data.jobs.0.exception'))->toStartWith('Illuminate\\Http\\Client\\ConnectionException: cURL error 6')
        ->and($summary)->toMatchArray(['status' => 'partial', 'note' => $hidden])
        ->and(data_get($summary, 'data.by_job_class'))->toBe([])
        ->and(data_get($summary, 'data.total'))->toBe(4)
        ->and(mysqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']))->toMatchArray([
            'status' => 'unavailable', 'note' => 'The read-only role cannot read `failed_jobs.payload`, so the job_class filter cannot be used.',
        ]);
});

it('FJM10 says a payload is not valid JSON on MySQL, never crashes; MariaDB reads it as no job class', function () {
    FailedJobs::serve('testing_mysql');
    DB::connection('testing_mysql')->table('failed_jobs')->insert([
        'uuid' => 'bad-json', 'connection' => 'redis', 'queue' => 'default', 'payload' => 'not json', 'exception' => 'E: x', 'failed_at' => '2026-10-01 10:00:00',
    ]);
    Exceptions::fake();

    if (mysqlFailedJobsIsMaria()) {
        expect(mysqlFailedJobs('queue_failed_jobs_list', ['from' => '2026-10-01']))->toMatchArray(['status' => 'ok'])
            ->and(data_get(mysqlFailedJobs('queue_failed_jobs_list', ['from' => '2026-10-01']), 'data.jobs.0.job_class'))->toBeNull()
            ->and(data_get(mysqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01']), 'data.by_job_class'))->toBe([['job_class' => null, 'count' => 1]]);
    } else {
        foreach (FailedJobs::TOOLS as $tool) {
            expect(mysqlFailedJobs($tool, ['from' => '2026-10-01']))->toMatchArray([
                'status' => 'unavailable', 'note' => 'A payload in table `failed_jobs` is not valid JSON, so the job classes cannot be read.',
            ]);
        }
    }

    Exceptions::assertNothingReported();
});

it('FJM11 names the statement timeout when a read is stopped (a lock held by another session)', function () {
    FailedJobs::serve('testing_mysql', ['runtime-mcp.database.statement_timeout_ms' => 300]);
    $locker = DB::connection('testing_mysql');
    $locker->beginTransaction();

    try {
        // A write lock: the read waits for it until the statement timeout stops it.
        $locker->statement('LOCK TABLES failed_jobs WRITE');

        foreach (FailedJobs::TOOLS as $tool) {
            expect(mysqlFailedJobs($tool))->toMatchArray([
                'status' => 'unavailable',
                'note' => 'Reading table `failed_jobs` was stopped by the 300 ms statement timeout. Narrow the window with from and to.',
            ]);
        }
    } finally {
        $locker->statement('UNLOCK TABLES');
        $locker->rollBack();
    }
});

it('FJM12 redacts the first line whole before the cut: the SQL keeps 2,000 characters, PHP cuts to 300', function () {
    FailedJobs::serve('testing_mysql');
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException(str_repeat('x', 249).' Bearer '.$token.' '.str_repeat('y', 3000)), '2026-10-01T10:00:00Z');

    $line = data_get(mysqlFailedJobs('queue_failed_jobs_list'), 'data.jobs.0.exception');
    assert(is_string($line));

    expect(mb_strlen($line))->toBe(300)->and($line)->toContain('Bearer [redacted:bearer]');
    expect($line)->not->toContain(substr($token, 0, 8));
});

it('FJM13 bounds the work: 10,000 failures with 20 KB traces list in under 1 s, with less than 8 MB more peak memory', function () {
    // The list must finish well inside the default 5,000 ms timeout; the summary reads every trace (on MySQL about
    // 4 s for these 200 MB locally), so this test gives it room. A summary past the timeout is unavailable (FJ*11).
    FailedJobs::serve('testing_mysql', ['runtime-mcp.database.statement_timeout_ms' => 60000]);
    // Built in the database: 200 MB of traces never pass through PHP, also not to write them.
    DB::connection('testing_mysql')->statement(<<<'SQL'
        INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at)
        SELECT CONCAT('bulk-', g), 'redis', 'default',
               CONCAT('{"uuid":"bulk-', g, '","displayName":"Bulk', g % 7, '"}'),
               CONCAT('RuntimeException: bulk ', g, ' in /app/x.php:1', CHAR(10), REPEAT(CONCAT('#0 /app/vendor/frame.php(12): call()', CHAR(10)), 540)),
               TIMESTAMP '2026-10-01 00:00:00' + INTERVAL g SECOND
        FROM (SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + 1 AS g
              FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
              CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
              CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
              CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e) AS n
        SQL);

    memory_reset_peak_usage();
    $before = memory_get_peak_usage();
    $started = hrtime(true);
    $envelope = mysqlFailedJobs('queue_failed_jobs_list', ['limit' => 100]);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($envelope['status'])->toBe('partial')
        ->and(mysqlFailedJobsJobs($envelope))->toHaveCount(100)
        ->and(data_get($envelope, 'data.jobs.0.id'))->toBe('bulk-10000')
        ->and($seconds)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and(data_get(mysqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01', 'to' => '2026-10-01']), 'data.total'))->toBe(10000);
});

it('FJM14 reads the first line and the class also under NO_BACKSLASH_ESCAPES, where \'\\n\' is two characters', function () {
    FailedJobs::serve('testing_mysql', [
        'database.connections.'.ReadOnlyRole::CONNECTION.'.modes' => ['STRICT_TRANS_TABLES', 'NO_BACKSLASH_ESCAPES'],
    ]);
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));
    FailedJobs::log('App\\Jobs\\SyncOrder', new LogicException(''), '2026-10-02T08:00:00Z');
    $mode = DB::connection(ReadOnlyRole::CONNECTION)->selectOne('SELECT @@SESSION.sql_mode AS mode');

    expect(is_object($mode) && is_string($mode->mode ?? null) ? $mode->mode : '')->toContain('NO_BACKSLASH_ESCAPES')
        ->and(data_get(mysqlFailedJobs('queue_failed_jobs_list'), 'data.jobs.0.exception'))->toMatch('/^LogicException in \\S+:\\d+$/')
        ->and(data_get(mysqlFailedJobs('queue_failed_jobs_summary'), 'data.by_exception_class'))->toBe([['exception_class' => 'LogicException', 'count' => 1]]);
});

it('FJM15 gives ReadOnlySession\'s own message when the read-only connection cannot be opened, never the host or the user', function () {
    FailedJobs::serve('testing_mysql', ['database.connections.'.ReadOnlyRole::CONNECTION.'.password' => 'wrong']);
    Exceptions::fake();

    foreach (FailedJobs::TOOLS as $tool) {
        expect(mysqlFailedJobs($tool))->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::CONNECTION_FAILED]);
    }

    Exceptions::assertNothingReported();
});

it('FJM16 with a table prefix and a schema-qualified table: reads the real table, and a prefixed denied_tables entry denies it', function () {
    // 01-23 triage (m2, m3 of the review): Laravel writes queue.failed.table `<database>.failed_jobs` to <database>.pfx_failed_jobs.
    $database = DB::connection('testing_mysql')->getDatabaseName();
    FailedJobs::serve('testing_mysql', [
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
    DB::connection('testing_mysql')->table('pfx_failed_jobs')->insert([
        'uuid' => 'prefixed', 'connection' => 'redis', 'queue' => 'default', 'payload' => FailedJobs::payload(),
        'exception' => 'RuntimeException: boom in /app/x.php:1', 'failed_at' => '2026-10-01 10:00:00',
    ]);

    expect(array_column(mysqlFailedJobsJobs(mysqlFailedJobs('queue_failed_jobs_list')), 'id'))->toBe(['prefixed'])
        ->and(data_get(mysqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01', 'to' => '2026-10-01']), 'data.total'))->toBe(1);

    // The role was made before this entry, so only the PHP check stands between the agent and the rows.
    config(['runtime-mcp.database.denied_tables' => ['pfx_failed_jobs']]);

    foreach (FailedJobs::TOOLS as $tool) {
        expect(mysqlFailedJobs($tool))->toMatchArray([
            'status' => 'unavailable', 'note' => 'Table `'.$database.'.failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        ]);
    }
});
