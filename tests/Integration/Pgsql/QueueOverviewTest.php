<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\QueueJobs;

/*
 * 01-22 on PostgreSQL: the database queue driver counts on the default connection, inside 01-16's read-only
 * transaction. One failed statement aborts a PostgreSQL transaction ("current transaction is aborted"), so
 * each count runs in a savepoint. SQLite and MySQL do not abort, so only this file can see it.
 */

const PGSQL_QUEUE_TABLES = ['rmcp_queue_jobs', 'rmcp_queue_missing'];

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(function () {
    AuditLog::clear();
    QueueJobs::drop('testing_pgsql', ...PGSQL_QUEUE_TABLES);
});

/**
 * PostgreSQL is the default connection. `old` reads a table that does not exist; `main` reads rmcp_queue_jobs.
 *
 * @param  array<string, mixed>  $names
 * @param  array<string, int>  $jobs
 * @return array<array-key, mixed> the envelope; Exceptions is faked
 */
function pgsqlQueueOverview(array $names, array $jobs): array
{
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'cache.limiter' => 'array',
        'database.default' => 'testing_pgsql',
        'queue.connections.old' => ['driver' => 'database', 'connection' => 'testing_pgsql', 'table' => 'rmcp_queue_missing', 'queue' => 'default'],
        'queue.connections.main' => ['driver' => 'database', 'connection' => 'testing_pgsql', 'table' => 'rmcp_queue_jobs', 'queue' => 'default'],
        'runtime-mcp.queue.names' => $names,
    ]);
    Gates::allow('queue_overview');
    Exceptions::fake(); // after the boot: bootWith() builds a new app
    QueueJobs::drop('testing_pgsql', 'rmcp_queue_missing');
    QueueJobs::create('testing_pgsql', 'rmcp_queue_jobs', $jobs);

    return FixtureTools::envelope(FixtureTools::call('queue_overview'));
}

it('QOP1 measures the next queue after a failed count: the savepoint keeps the read-only transaction usable', function () {
    $envelope = pgsqlQueueOverview(['old' => ['default'], 'main' => ['default', 'emails']], ['default' => 3, 'emails' => 1]);
    $class = QueryException::class;

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe('1 of 3 queues could not be measured; see each entry.')
        ->and($envelope['data'])->toBe(['queues' => [
            ['connection' => 'old', 'driver' => 'database', 'queue' => 'default', 'size' => null, 'status' => 'unavailable',
                'note' => "Could not read the size of `default` on `old`: {$class}."],
            ['connection' => 'main', 'driver' => 'database', 'queue' => 'default', 'size' => 3, 'status' => 'ok', 'note' => null],
            ['connection' => 'main', 'driver' => 'database', 'queue' => 'emails', 'size' => 1, 'status' => 'ok', 'note' => null],
        ]]);

    Exceptions::assertReported(fn (QueryException $e): bool => str_contains($e->getMessage(), 'rmcp_queue_missing'));
    Exceptions::assertReportedCount(1);
});

it('QOP2 counts inside the read-only transaction, and leaves the session as it found it for the next call', function () {
    $first = pgsqlQueueOverview(['main' => ['default', 'emails']], ['default' => 2]);
    $second = FixtureTools::envelope(FixtureTools::call('queue_overview'));

    expect($first['status'])->toBe('ok')
        ->and(data_get($first, 'data.queues.*.size'))->toBe([2, 0])
        ->and($second)->toBe($first)
        ->and(DB::connection('testing_pgsql')->transactionLevel())->toBe(0)
        ->and(DB::connection('testing_pgsql')->scalar('SHOW transaction_read_only'))->toBe('off');

    Exceptions::assertNothingReported();
});
