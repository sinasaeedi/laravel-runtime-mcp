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
 * 01-22 on MySQL and MariaDB (the same file runs on both): the database queue driver counts on the default
 * connection, inside 01-16's read-only transaction, each count in a savepoint. Kept in step with the
 * PostgreSQL file by hand (ids QOP -> QOM).
 */

const MYSQL_QUEUE_TABLES = ['rmcp_queue_jobs', 'rmcp_queue_missing'];

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(function () {
    AuditLog::clear();
    QueueJobs::drop('testing_mysql', ...MYSQL_QUEUE_TABLES);
});

/**
 * MySQL or MariaDB is the default connection. `old` reads a table that does not exist; `main` reads rmcp_queue_jobs.
 *
 * @param  array<string, mixed>  $names
 * @param  array<string, int>  $jobs
 * @return array<array-key, mixed> the envelope; Exceptions is faked
 */
function mysqlQueueOverview(array $names, array $jobs): array
{
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'cache.limiter' => 'array',
        'database.default' => 'testing_mysql',
        'queue.connections.old' => ['driver' => 'database', 'connection' => 'testing_mysql', 'table' => 'rmcp_queue_missing', 'queue' => 'default'],
        'queue.connections.main' => ['driver' => 'database', 'connection' => 'testing_mysql', 'table' => 'rmcp_queue_jobs', 'queue' => 'default'],
        'runtime-mcp.queue.names' => $names,
    ]);
    Gates::allow('queue_overview');
    Exceptions::fake(); // after the boot: bootWith() builds a new app
    QueueJobs::drop('testing_mysql', 'rmcp_queue_missing');
    QueueJobs::create('testing_mysql', 'rmcp_queue_jobs', $jobs);

    return FixtureTools::envelope(FixtureTools::call('queue_overview'));
}

it('QOM1 measures the next queue after a failed count', function () {
    $envelope = mysqlQueueOverview(['old' => ['default'], 'main' => ['default', 'emails']], ['default' => 3, 'emails' => 1]);
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

it('QOM2 counts inside the read-only transaction, and leaves the session as it found it for the next call', function () {
    $first = mysqlQueueOverview(['main' => ['default', 'emails']], ['default' => 2]);
    $second = FixtureTools::envelope(FixtureTools::call('queue_overview'));

    expect($first['status'])->toBe('ok')
        ->and(data_get($first, 'data.queues.*.size'))->toBe([2, 0])
        ->and($second)->toBe($first)
        ->and(DB::connection('testing_mysql')->transactionLevel())->toBe(0);

    Exceptions::assertNothingReported();
});
