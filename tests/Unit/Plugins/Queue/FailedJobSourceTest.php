<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;

/*
 * 01-23 Part A: queue.failed, read as Laravel's QueueServiceProvider::registerFailedJobServices() reads it (12.69 and
 * 13.36), and requiresReadOnlyConnection() from the same rule. Config only, so a plain Repository is enough.
 */

/**
 * @return array{string, string|null, string|null, string|null, string|null, bool, bool}
 *                                                                                       kind, table, idColumn, path, note, dynamoDb, requiresReadOnlyConnection
 */
function failedJobSource(mixed $failed): array
{
    $config = new Repository(['queue' => ['failed' => $failed]]);
    $source = FailedJobSource::fromConfig($config);

    return [$source->kind, $source->table, $source->idColumn, $source->path, $source->note, $source->dynamoDb, (new QueuePlugin($config))->requiresReadOnlyConnection()];
}

const FAILED_JOB_SOURCES = [
    // Laravel's default (and Testbench's): the table, by uuid.
    'database-uuids' => [['driver' => 'database-uuids', 'database' => 'mysql', 'table' => 'failed_jobs'], ['table', 'failed_jobs', 'uuid', null, null, false, true]],
    // Not a Laravel driver name: it works through the table fallback, by id.
    'database' => [['driver' => 'database', 'table' => 'failed_jobs'], ['table', 'failed_jobs', 'id', null, null, false, true]],
    'no driver key, a table' => [['table' => 'failed_jobs'], ['table', 'failed_jobs', 'id', null, null, false, true]],
    'an unknown driver, a table' => [['driver' => 'redis', 'table' => 'jobs_failed'], ['table', 'jobs_failed', 'id', null, null, false, true]],
    'a schema-qualified table' => [['driver' => 'database-uuids', 'table' => 'ops.failed_jobs'], ['table', 'ops.failed_jobs', 'uuid', null, null, false, true]],
    'driver null' => [['driver' => null, 'table' => 'failed_jobs'], ['none', null, null, null, FailedJobSource::NULL_DRIVER, false, false]],
    "driver 'null'" => [['driver' => 'null', 'table' => 'failed_jobs'], ['none', null, null, null, FailedJobSource::NULL_DRIVER, false, false]],
    'file, default path' => [['driver' => 'file'], ['file', null, null, null, null, false, false]],
    'file, a path' => [['driver' => 'file', 'path' => '/var/app/failed.json', 'table' => 'failed_jobs'], ['file', null, null, '/var/app/failed.json', null, false, false]],
    'file, an empty path' => [['driver' => 'file', 'path' => ''], ['file', null, null, null, null, false, false]],
    'dynamodb' => [['driver' => 'dynamodb', 'table' => 'failed_jobs', 'region' => 'us-east-1'], ['none', null, null, null, FailedJobSource::DYNAMODB, true, false]],
    'an unknown driver, no table' => [['driver' => 'redis'], ['none', null, null, null, FailedJobSource::NO_TABLE, false, false]],
    'no driver, no table' => [[], ['none', null, null, null, FailedJobSource::NO_TABLE, false, false]],
    'a null table' => [['driver' => 'database', 'table' => null], ['none', null, null, null, FailedJobSource::NO_TABLE, false, false]],
    'database-uuids, no table' => [['driver' => 'database-uuids'], ['none', null, null, null, FailedJobSource::BAD_TABLE, false, false]],
    'an empty table' => [['driver' => 'database-uuids', 'table' => ''], ['none', null, null, null, FailedJobSource::BAD_TABLE, false, false]],
    'a table that is not a name' => [['driver' => 'database', 'table' => 'failed jobs; drop'], ['none', null, null, null, FailedJobSource::BAD_TABLE, false, false]],
    'a table with two dots' => [['table' => 'a.b.c'], ['none', null, null, null, FailedJobSource::BAD_TABLE, false, false]],
    'a table that is not a string' => [['table' => 5], ['none', null, null, null, FailedJobSource::BAD_TABLE, false, false]],
    'not an array' => ['database-uuids', ['none', null, null, null, FailedJobSource::NOT_AN_ARRAY, false, false]],
    'missing' => [null, ['none', null, null, null, FailedJobSource::NOT_AN_ARRAY, false, false]],
];

it('FS1 reads queue.failed as Laravel picks its provider, and needs the read-only connection only for a table', function (mixed $failed, array $expected) {
    expect(failedJobSource($failed))->toBe($expected);
})->with(FAILED_JOB_SOURCES);

it('FS2 never shows a table name that is not name-shaped, and allows the longest names', function () {
    $long = str_repeat('t', 64);

    expect(failedJobSource(['table' => $long])[1])->toBe($long)
        ->and(failedJobSource(['table' => $long.'t'])[4])->toBe(FailedJobSource::BAD_TABLE)
        ->and(failedJobSource(['table' => "failed_jobs\n"])[4])->toBe(FailedJobSource::BAD_TABLE)
        ->and(failedJobSource(['table' => 'pfx_failed$jobs'])[1])->toBe('pfx_failed$jobs');
});
