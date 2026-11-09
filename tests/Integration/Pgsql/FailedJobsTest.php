<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
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
 * 01-23 on PostgreSQL (spec 4.2, 4.3, 6.6, 6.8, rules 8 and 11): the table sources, read as 01-11's read-only role on
 * the read-only connection inside ReadOnlySession (01-14). Laravel's own provider writes every failed job
 * (FailedJobs::log()). testing_pgsql is the privileged connection; the default connection stays SQLite.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function pgsqlFailedJobs(string $tool, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call($tool, $arguments, $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<array<array-key, mixed>>
 */
function pgsqlFailedJobsJobs(array $envelope): array
{
    $jobs = data_get($envelope, 'data.jobs');
    expect($jobs)->toBeArray();
    assert(is_array($jobs));

    return array_values(array_filter($jobs, is_array(...)));
}

/** @return array{string, string, string, string} four failures, newest first; the two newest in the same second */
function pgsqlFailedJobsSeed(): array
{
    $old = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28: Operation timed out'), '2026-10-01T21:00:00Z', 'invoices');
    $mid = FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('Order 1234 not found'), '2026-10-01T22:00:00Z');
    $first = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7: Failed to connect'), '2026-10-01T23:30:00Z', 'invoices');
    $second = FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 6: Could not resolve host'), '2026-10-01T23:30:00Z', 'invoices', 'sqs');

    return [$second, $first, $mid, $old];
}

it('FJP1 lists the newest failures first (a tie: the newer row first), with the one-line reason, in both eras', function (ProtocolEra $era) {
    FailedJobs::serve('testing_pgsql');
    [$second, $first, $mid, $old] = pgsqlFailedJobsSeed();

    $envelope = pgsqlFailedJobs('queue_failed_jobs_list', [], $era);
    $jobs = pgsqlFailedJobsJobs($envelope);

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

it('FJP2 filters exactly by job_class (with backslashes), queue, connection and an inclusive window', function () {
    FailedJobs::serve('testing_pgsql');
    [$second, $first, $mid, $old] = pgsqlFailedJobsSeed();
    $ids = fn (array $arguments): array => array_column(pgsqlFailedJobsJobs(pgsqlFailedJobs('queue_failed_jobs_list', $arguments)), 'id');

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

it('FJP3 limits in the database: limit + 1 rows, partial with a note; empty names the filters and the table', function () {
    FailedJobs::serve('testing_pgsql');
    pgsqlFailedJobsSeed();

    $two = pgsqlFailedJobs('queue_failed_jobs_list', ['limit' => 2]);
    $none = pgsqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\Nope', 'from' => '2026-10-01']);

    expect($two['status'])->toBe('partial')
        ->and($two['note'])->toBe('More failed jobs match; showing the newest 2. Narrow with from and to, or job_class.')
        ->and($two['truncated'])->toBeFalse()
        ->and(pgsqlFailedJobsJobs($two))->toHaveCount(2)
        ->and($none['status'])->toBe('empty')
        ->and($none['note'])->toBe('No failed jobs match job_class=App\\Jobs\\Nope, from 2026-10-01T00:00:00+00:00 in table `failed_jobs`.')
        ->and($none['data'])->toBe(['jobs' => []]);
});

it('FJP4 counts by job class and by exception class in the database, in the last 24 hours by default', function () {
    FailedJobs::serve('testing_pgsql');
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 28'), '2026-10-01T10:00:00Z');
    FailedJobs::log('App\\Jobs\\SendInvoice', new ConnectionException('cURL error 7'), '2026-10-01T11:00:00Z');
    FailedJobs::log('App\\Jobs\\SyncOrder', new LogicException(''), '2026-10-02T08:00:00Z');
    FailedJobs::log('App\\Jobs\\SyncOrder', new RuntimeException('outer', 0, new InvalidArgumentException('inner')), '2026-10-02T08:30:00Z');
    FailedJobs::log('App\\Jobs\\Old', new RuntimeException('old'), '2026-09-30T08:00:00Z');

    expect(pgsqlFailedJobs('queue_failed_jobs_summary'))->toMatchArray(['status' => 'ok', 'data' => [
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
        ->and(pgsqlFailedJobs('queue_failed_jobs_summary', ['to' => '2026-09-30']))->toMatchArray(['status' => 'ok'])
        ->and(pgsqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-09-01', 'to' => '2026-09-02']))->toMatchArray([
            'status' => 'empty',
            'note' => 'No failed jobs between 2026-09-01T00:00:00+00:00 and 2026-09-02T23:59:59+00:00 in table `failed_jobs`.',
        ]);
});

it('FJP5 shows the 50 largest groups and says when more exist', function () {
    FailedJobs::serve('testing_pgsql');
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));

    for ($i = 1; $i <= 51; $i++) {
        FailedJobs::log(sprintf('App\\Jobs\\Job%02d', $i), new RuntimeException('x'), '2026-10-02T08:00:00Z');
    }

    $envelope = pgsqlFailedJobs('queue_failed_jobs_summary');

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe('More than 50 job classes failed in this window; by_job_class shows the 50 with the most failures.')
        ->and(data_get($envelope, 'data.by_job_class'))->toHaveCount(50)
        ->and(data_get($envelope, 'data.by_job_class.0'))->toBe(['job_class' => 'App\\Jobs\\Job01', 'count' => 1])
        ->and(data_get($envelope, 'data.by_exception_class'))->toBe([['exception_class' => 'RuntimeException', 'count' => 51]]);
});

it('FJP6 never selects the payload or the exception whole: only the projections leave the database', function () {
    FailedJobs::serve('testing_pgsql');
    pgsqlFailedJobsSeed();
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$sql): void {
        if ($event->connectionName === ReadOnlyRole::CONNECTION && str_contains($event->sql, 'failed_jobs')) {
            $sql[] = $event->sql;
        }
    });

    pgsqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']);
    pgsqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01']);

    expect($sql)->toHaveCount(4); // the list, then the count and the two groups

    foreach ($sql as $statement) {
        $left = str_replace(['("payload")::json ->> \'displayName\'', 'split_part("exception", chr(10), 1)'], '', $statement);

        expect($left)->not->toContain('"payload"');
        expect($left)->not->toContain('"exception"');
        expect($statement)->not->toMatch('/select \*|\.\*/i');
    }
});

it('FJP7 reads a `database` table by id, and says that a database-uuids table needs its uuid column', function () {
    FailedJobs::serve('testing_pgsql', ['queue.failed.driver' => 'database', 'queue.failed.table' => 'old_failed_jobs'], function (string $connection): void {
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

    expect(array_column(pgsqlFailedJobsJobs(pgsqlFailedJobs('queue_failed_jobs_list')), 'id'))->toBe([$b, $a])
        ->and($a)->toMatch('/^\d+$/');

    config(['queue.failed.driver' => 'database-uuids']);

    expect(pgsqlFailedJobs('queue_failed_jobs_list'))->toMatchArray([
        'status' => 'unavailable', 'note' => 'Table `old_failed_jobs` has no `uuid` column, but queue.failed.driver is database-uuids.',
    ]);
});

it('FJP8 is unavailable, never empty, for a missing, denied or unreadable table', function (string $case) {
    $config = match ($case) {
        'denied' => ['runtime-mcp.database.denied_tables' => ['failed_jobs']],
        'denied in its schema' => ['runtime-mcp.database.denied_tables' => ['public.failed_jobs']],
        'denied_tables not a list' => ['runtime-mcp.database.denied_tables' => 'failed_jobs'],
        'a default denied table' => ['queue.failed.table' => 'jobs'],
        default => [],
    };
    $role = FailedJobs::serve('testing_pgsql', $config);

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

    foreach (FailedJobs::TOOLS as $tool) {
        expect(pgsqlFailedJobs($tool))->toMatchArray(['status' => 'unavailable', 'note' => $note]);
    }

    // With a job_class filter too: a refused table is not reported as a refused payload column.
    expect(pgsqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']))->toMatchArray(['status' => 'unavailable', 'note' => $note]);
})->with(['missing', 'no grant', 'denied', 'denied in its schema', 'denied_tables not a list', 'a default denied table']);

it('FJP9 works without a grant on payload: job_class is not shown, and the job_class filter is unavailable', function () {
    // 01-11's docs let an operator grant failed_jobs without payload; the role is made from this mask.
    config(['runtime-mcp.database.masked_columns' => [...(array) config('runtime-mcp.database.masked_columns'), 'payload']]);
    FailedJobs::serve('testing_pgsql');
    pgsqlFailedJobsSeed();
    currentTest()->travelTo(new DateTimeImmutable('2026-10-02T09:00:00Z'));
    $hidden = 'The read-only role cannot read `failed_jobs.payload`, so job_class is not shown.';

    $list = pgsqlFailedJobs('queue_failed_jobs_list');
    $summary = pgsqlFailedJobs('queue_failed_jobs_summary');

    expect($list['status'])->toBe('partial')
        ->and($list['note'])->toBe($hidden)
        ->and(array_column(pgsqlFailedJobsJobs($list), 'job_class'))->toBe([null, null, null, null])
        ->and(data_get($list, 'data.jobs.0.exception'))->toStartWith('Illuminate\\Http\\Client\\ConnectionException: cURL error 6')
        ->and($summary)->toMatchArray(['status' => 'partial', 'note' => $hidden])
        ->and(data_get($summary, 'data.by_job_class'))->toBe([])
        ->and(data_get($summary, 'data.total'))->toBe(4)
        ->and(pgsqlFailedJobs('queue_failed_jobs_list', ['job_class' => 'App\\Jobs\\SendInvoice']))->toMatchArray([
            'status' => 'unavailable', 'note' => 'The read-only role cannot read `failed_jobs.payload`, so the job_class filter cannot be used.',
        ]);
});

it('FJP10 says a payload is not valid JSON, never crashes', function () {
    FailedJobs::serve('testing_pgsql');
    DB::connection('testing_pgsql')->table('failed_jobs')->insert([
        'uuid' => 'bad-json', 'connection' => 'redis', 'queue' => 'default', 'payload' => 'not json', 'exception' => 'E: x', 'failed_at' => '2026-10-01 10:00:00',
    ]);
    Exceptions::fake();

    foreach (FailedJobs::TOOLS as $tool) {
        expect(pgsqlFailedJobs($tool, ['from' => '2026-10-01']))->toMatchArray([
            'status' => 'unavailable', 'note' => 'A payload in table `failed_jobs` is not valid JSON, so the job classes cannot be read.',
        ]);
    }

    Exceptions::assertNothingReported();
});

it('FJP11 names the statement timeout when a read is stopped (a lock held by another session)', function () {
    FailedJobs::serve('testing_pgsql', ['runtime-mcp.database.statement_timeout_ms' => 300]);
    $locker = DB::connection('testing_pgsql');
    $locker->beginTransaction();

    try {
        $locker->statement('LOCK TABLE failed_jobs IN ACCESS EXCLUSIVE MODE');

        foreach (FailedJobs::TOOLS as $tool) {
            expect(pgsqlFailedJobs($tool))->toMatchArray([
                'status' => 'unavailable',
                'note' => 'Reading table `failed_jobs` was stopped by the 300 ms statement timeout. Narrow the window with from and to.',
            ]);
        }
    } finally {
        $locker->rollBack();
    }
});

it('FJP12 redacts the first line whole before the cut: the SQL keeps 2,000 characters, PHP cuts to 300', function () {
    FailedJobs::serve('testing_pgsql');
    $token = 'abcdefghijklmnopqrstuvwxyz0123456789';
    FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException(str_repeat('x', 249).' Bearer '.$token.' '.str_repeat('y', 3000)), '2026-10-01T10:00:00Z');

    $line = data_get(pgsqlFailedJobs('queue_failed_jobs_list'), 'data.jobs.0.exception');
    assert(is_string($line));

    expect(mb_strlen($line))->toBe(300)->and($line)->toContain('Bearer [redacted:bearer]');
    expect($line)->not->toContain(substr($token, 0, 8));
});

it('FJP13 bounds the work: 10,000 failures with 20 KB traces list in under 1 s, with less than 8 MB more peak memory', function () {
    // The list must finish well inside the default 5,000 ms timeout; the summary reads every trace (on MySQL about
    // 4 s for these 200 MB locally), so this test gives it room. A summary past the timeout is unavailable (FJ*11).
    FailedJobs::serve('testing_pgsql', ['runtime-mcp.database.statement_timeout_ms' => 60000]);
    // Built in the database: 200 MB of traces never pass through PHP, also not to write them.
    DB::connection('testing_pgsql')->statement(<<<'SQL'
        INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at)
        SELECT 'bulk-' || g, 'redis', 'default',
               '{"uuid":"bulk-' || g || '","displayName":"App\\Jobs\\Bulk' || (g % 7) || '","data":{"command":"O:8:\"stdClass\":0:{}"}}',
               'RuntimeException: bulk ' || g || ' in /app/x.php:1' || chr(10) || repeat('#0 /app/vendor/frame.php(12): call()' || chr(10), 540),
               timestamp '2026-10-01 00:00:00' + g * interval '1 second'
        FROM generate_series(1, 10000) AS g
        SQL);

    memory_reset_peak_usage();
    $before = memory_get_peak_usage();
    $started = hrtime(true);
    $envelope = pgsqlFailedJobs('queue_failed_jobs_list', ['limit' => 100]);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($envelope['status'])->toBe('partial')
        ->and(pgsqlFailedJobsJobs($envelope))->toHaveCount(100)
        ->and(data_get($envelope, 'data.jobs.0.id'))->toBe('bulk-10000')
        ->and($seconds)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and(data_get(pgsqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01', 'to' => '2026-10-01']), 'data.total'))->toBe(10000);
});

it('FJP15 gives ReadOnlySession\'s own message when the read-only connection cannot be opened, never the host or the user', function () {
    FailedJobs::serve('testing_pgsql', ['database.connections.'.ReadOnlyRole::CONNECTION.'.password' => 'wrong']);
    Exceptions::fake();

    foreach (FailedJobs::TOOLS as $tool) {
        expect(pgsqlFailedJobs($tool))->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::CONNECTION_FAILED]);
    }

    Exceptions::assertNothingReported();
});

it('FJP16 with a table prefix and a schema-qualified table: reads the real table, and a prefixed denied_tables entry denies it', function () {
    // 01-23 triage (m2, m3 of the review): Laravel writes queue.failed.table `public.failed_jobs` to public.pfx_failed_jobs.
    FailedJobs::serve('testing_pgsql', [
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
    DB::connection('testing_pgsql')->table('pfx_failed_jobs')->insert([
        'uuid' => 'prefixed', 'connection' => 'redis', 'queue' => 'default', 'payload' => FailedJobs::payload(),
        'exception' => 'RuntimeException: boom in /app/x.php:1', 'failed_at' => '2026-10-01 10:00:00',
    ]);

    expect(array_column(pgsqlFailedJobsJobs(pgsqlFailedJobs('queue_failed_jobs_list')), 'id'))->toBe(['prefixed'])
        ->and(data_get(pgsqlFailedJobs('queue_failed_jobs_summary', ['from' => '2026-10-01', 'to' => '2026-10-01']), 'data.total'))->toBe(1);

    // The role was made before this entry, so only the PHP check stands between the agent and the rows.
    config(['runtime-mcp.database.denied_tables' => ['pfx_failed_jobs']]);

    foreach (FailedJobs::TOOLS as $tool) {
        expect(pgsqlFailedJobs($tool))->toMatchArray([
            'status' => 'unavailable', 'note' => 'Table `public.failed_jobs` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.',
        ]);
    }
});
