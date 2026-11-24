<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Models\Tools\ModelShow;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ModelShowSchema;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec 14 ("No tool returns an empty result when it could not look"), rule 11, on MySQL 8.4 and MariaDB: the
 * database tools in the case where they cannot look. NEM4 is the model_show decision of 01-30 (as NEP4): a relation that
 * the database refuses (1143, SQLSTATE 42000) makes the whole call unavailable.
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

function neverEmptyMysqlServe(): void
{
    $models = ModelShowSchema::MODELS;
    $models['order']['relations'][] = 'customerSortedBySecret';
    ModelShowSchema::serve('testing_mysql', ['runtime-mcp.models' => $models], SecuritySuite::ENVIRONMENT);
    Gates::allow(...SecuritySuite::DATABASE_TOOLS);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function neverEmptyMysql(string $tool, array $arguments): array
{
    return FixtureTools::envelope(FixtureTools::call($tool, $arguments));
}

/** @return array<string, mixed> an unavailable envelope with this note */
function neverEmptyMysqlUnavailable(string $note): array
{
    return ['environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => $note, 'truncated' => false, 'data' => null];
}

it('NEM1 db_schema: a missing table is unavailable, never empty', function () {
    neverEmptyMysqlServe();

    expect(neverEmptyMysql('db_schema', ['table' => 'nope']))->toBe(neverEmptyMysqlUnavailable(sprintf(DbSchema::NOT_READABLE, 'nope')));
});

it('NEM2 db_query: a missing table is unavailable, never empty', function () {
    neverEmptyMysqlServe();
    $connection = DB::connection('testing_mysql');
    // MariaDB names the table with its database (1146), MySQL 8.4 without it (1142 for a table the user cannot see).
    $table = $connection instanceof MySqlConnection && $connection->isMaria() ? $connection->getDatabaseName().'.nope' : 'nope';

    expect(neverEmptyMysql('db_query', ['sql' => 'SELECT * FROM nope']))->toBe(neverEmptyMysqlUnavailable(sprintf(DbSchema::NOT_READABLE, $table)));
});

it('NEM3 model_show: a table the role cannot read is unavailable, never empty', function () {
    neverEmptyMysqlServe();

    expect(neverEmptyMysql('model_show', ['model' => 'session', 'key' => 'abc']))->toBe(neverEmptyMysqlUnavailable(sprintf(ModelShow::NOT_READABLE, 'session', 'sessions')));
});

it('NEM4 model_show: a relation that the database refuses makes the whole call unavailable, and shows no attribute of the record', function (array $with) {
    neverEmptyMysqlServe();
    // 01-21: an unexpected database error gives the SQLSTATE only, and the app's error log gets the exception.
    Exceptions::fake();

    $response = FixtureTools::call('model_show', ['model' => 'order', 'key' => 1, 'with' => $with]);

    expect(FixtureTools::envelope($response))->toBe(neverEmptyMysqlUnavailable(sprintf(ModelShow::REFUSED, 'order', '42000')))
        ->and($response->getContent())->not->toContain('A-1')
        ->and($response->getContent())->not->toContain('Widget');
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryPermissionDenied::class);
})->with([
    'alone' => [['customerSortedBySecret']],
    'after a relation that reads' => [['items', 'customerSortedBySecret']],
]);
