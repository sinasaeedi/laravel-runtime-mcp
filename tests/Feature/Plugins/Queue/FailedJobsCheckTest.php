<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyRolePrivileges;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor\FailedJobsCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor\QueuesCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ReadOnlyConnection;

/*
 * 01-23 Part E (spec 7.3): the "Failed jobs" doctor check, config only. And the 01-14 re-review's half of this story
 * (Sina, 2026-10-29): with a table source the queue plugin needs the read-only connection, so 01-10's boot rule and
 * 01-13's "Read-only role" check cover it with the queue plugin alone.
 */

/** Only the queue plugin is on. */
const FAILED_JOBS_QUEUE_ONLY = ['runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false];

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}>
 */
function failedJobsCheckResults(array $config = []): array
{
    currentTest()->bootWith($config);
    $checks = app(QueuePlugin::class)->doctorChecks();

    expect($checks)->toHaveCount(2)
        ->and($checks[0])->toBeInstanceOf(QueuesCheck::class)
        ->and($checks[1])->toBeInstanceOf(FailedJobsCheck::class)
        ->and($checks[1]->title())->toBe('Failed jobs');

    return array_map(fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix], $checks[1]->run());
}

/* Config that makes both connections name one database. */
const FAILED_JOBS_SAME_DATABASE = [
    // Testbench: queue.failed.database is unset here, so the default connection (SQLite :memory:, as testing_readonly).
    'queue.failed.database unset: the default connection' => ['queue.failed.database' => null],
    'a replica keeps its name' => [
        'database.connections.primary' => ['driver' => 'pgsql', 'host' => 'db-primary', 'database' => 'shop'],
        'database.connections.replica' => ['driver' => 'pgsql', 'host' => 'db-replica', 'database' => 'shop'],
        'queue.failed.database' => 'primary',
        'runtime-mcp.database.connection' => 'replica',
    ],
    'a url sets the database' => [
        'database.connections.primary' => ['url' => 'pgsql://app:secret@db/shop'],
        'database.connections.replica' => ['driver' => 'pgsql', 'database' => 'shop'],
        'queue.failed.database' => 'primary',
        'runtime-mcp.database.connection' => 'replica',
    ],
];

it('FC1 passes when the read-only connection and queue.failed.database name the same database', function (string $case) {
    expect(failedJobsCheckResults(FAILED_JOBS_SAME_DATABASE[$case]))->toBe([['pass', 'The failed-job tools read table `failed_jobs` on the read-only connection.', null]]);
})->with(array_keys(FAILED_JOBS_SAME_DATABASE));

it('FC2 warns when the two connections name different databases, and prints no database or connection name', function () {
    $results = failedJobsCheckResults([
        'database.connections.jobsconn' => ['driver' => 'pgsql', 'host' => 'dbhost', 'database' => 'alphadb'],
        'database.connections.roconn' => ['driver' => 'pgsql', 'host' => 'dbhost', 'database' => 'betadb'],
        'queue.failed.database' => 'jobsconn',
        'runtime-mcp.database.connection' => 'roconn',
    ]);

    expect($results)->toBe([['warn', sprintf(FailedJobsCheck::DIFFERENT, 'failed_jobs'), FailedJobsCheck::DIFFERENT_FIX]]);

    foreach (['alphadb', 'betadb', 'jobsconn', 'roconn', 'dbhost'] as $name) {
        expect((string) json_encode($results))->not->toContain($name);
    }
});

/* Config, then the warning. */
const FAILED_JOBS_NOT_COMPARED = [
    'no read-only connection' => [['runtime-mcp.database.connection' => null], FailedJobsCheck::NOT_CHECKED],
    'a blank read-only connection' => [['runtime-mcp.database.connection' => '  '], FailedJobsCheck::NOT_CHECKED],
    'a read-only connection not in config/database.php' => [['runtime-mcp.database.connection' => 'nope'], FailedJobsCheck::UNKNOWN],
    'queue.failed.database not in config/database.php' => [['queue.failed.database' => 'nope'], FailedJobsCheck::UNKNOWN],
    'a connection with no database' => [['database.connections.nodb' => ['driver' => 'pgsql'], 'queue.failed.database' => 'nodb'], FailedJobsCheck::UNKNOWN],
    'a malformed url' => [['database.connections.badurl' => ['url' => 'http://[::1'], 'queue.failed.database' => 'badurl'], FailedJobsCheck::UNKNOWN],
];

it('FC3 does not compare until both connections name a database', function (string $case) {
    [$config, $message] = FAILED_JOBS_NOT_COMPARED[$case];

    expect(failedJobsCheckResults($config))->toBe([['warn', $message, null]]);
})->with(array_keys(FAILED_JOBS_NOT_COMPARED));

it('FC4 passes for the file source and warns for DynamoDB and every source that stores nothing', function (mixed $failed, array $expected) {
    expect(failedJobsCheckResults(['queue.failed' => $failed]))->toBe([$expected]);
})->with([
    'file' => [['driver' => 'file'], ['pass', 'The failed-job tools read the failed-job file (at most 10,000,000 bytes).', null]],
    'dynamodb' => [['driver' => 'dynamodb', 'table' => 'failed_jobs'], ['warn', FailedJobsCheck::DYNAMODB, FailedJobsCheck::DYNAMODB_FIX]],
    'null' => [['driver' => null], ['warn', FailedJobSource::NULL_DRIVER, FailedJobsCheck::NONE_FIX]],
    'no table' => [['driver' => 'redis'], ['warn', FailedJobSource::NO_TABLE, FailedJobsCheck::NONE_FIX]],
    'not an array' => ['file', ['warn', FailedJobSource::NOT_AN_ARRAY, FailedJobsCheck::NONE_FIX]],
]);

it('FC5 makes the boot rule name the queue plugin for a table source, and not for the other sources', function (mixed $failed, ?string $message) {
    currentTest()->bootWith([...FAILED_JOBS_QUEUE_ONLY, 'queue.failed' => $failed, 'runtime-mcp.database.connection' => null], 'production');
    $issues = app(ReadOnlyConnection::class)->check();

    expect($issues[0]->message ?? null)->toBe($message)
        ->and($issues[0]->severity->value ?? null)->toBe($message === null ? null : 'error');
})->with([
    'database-uuids' => [['driver' => 'database-uuids', 'table' => 'failed_jobs'], 'RUNTIME_MCP_DB_CONNECTION is not set. The `queue` plugin needs a separate read-only connection.'],
    'database' => [['driver' => 'database', 'table' => 'failed_jobs'], 'RUNTIME_MCP_DB_CONNECTION is not set. The `queue` plugin needs a separate read-only connection.'],
    'no driver, a table' => [['table' => 'failed_jobs'], 'RUNTIME_MCP_DB_CONNECTION is not set. The `queue` plugin needs a separate read-only connection.'],
    'file' => [['driver' => 'file'], null],
    'null' => [['driver' => null], null],
    'dynamodb' => [['driver' => 'dynamodb', 'table' => 'failed_jobs'], null],
]);

it('FC6 runs the Read-only role check with the queue plugin alone for a table source, and not for the file source', function () {
    $results = function (array $failed): array {
        currentTest()->bootWith([...FAILED_JOBS_QUEUE_ONLY, 'queue.failed' => $failed]);

        return array_map(fn (CheckResult $result): string => $result->status->value.' '.$result->message, app(ReadOnlyRolePrivileges::class)->run());
    };

    // The test read-only connection is SQLite, so a check that runs fails on the driver (01-13).
    expect($results(['driver' => 'database-uuids', 'table' => 'failed_jobs'])[0])->toStartWith('fail ')
        ->and($results(['driver' => 'file']))->toBe(['pass Not needed: no active plugin reads the database.']);
});
