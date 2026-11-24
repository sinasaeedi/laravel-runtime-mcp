<?php

declare(strict_types=1);

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
 * 01-30, spec 14 ("No tool returns an empty result when it could not look"), rule 11, on PostgreSQL: the database
 * tools of SecuritySuite::DATABASE_TOOLS in the case where they cannot look, as 01-11's read-only role. The SQLite cases
 * are in tests/Feature/SecuritySuite/NeverSilentlyEmptyTest.php.
 *
 * NEP4 is the decision that 01-21's triage left for 01-30: a relation that the database refuses in frame 2 makes the
 * whole model_show call unavailable, and no attribute of the record is shown (fail closed, rule 11).
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

function neverEmptyPgsqlServe(): void
{
    $models = ModelShowSchema::MODELS;
    $models['order']['relations'][] = 'customerSortedBySecret';
    ModelShowSchema::serve('testing_pgsql', ['runtime-mcp.models' => $models], SecuritySuite::ENVIRONMENT);
    Gates::allow(...SecuritySuite::DATABASE_TOOLS);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function neverEmptyPgsql(string $tool, array $arguments): array
{
    return FixtureTools::envelope(FixtureTools::call($tool, $arguments));
}

it('NEP1 db_schema: a missing table is unavailable, never empty', function () {
    neverEmptyPgsqlServe();

    expect(neverEmptyPgsql('db_schema', ['table' => 'nope']))->toBe([
        'environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => sprintf(DbSchema::NOT_READABLE, 'nope'), 'truncated' => false, 'data' => null,
    ]);
});

it('NEP2 db_query: a missing table is unavailable with the database message, never empty', function () {
    neverEmptyPgsqlServe();

    expect(neverEmptyPgsql('db_query', ['sql' => 'SELECT * FROM nope']))->toBe([
        'environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable',
        'note' => 'The database rejected the query (SQLSTATE 42P01): ERROR:  relation "nope" does not exist.', 'truncated' => false, 'data' => null,
    ]);
});

it('NEP3 model_show: a table the role cannot read is unavailable, never empty', function () {
    neverEmptyPgsqlServe();

    expect(neverEmptyPgsql('model_show', ['model' => 'session', 'key' => 'abc']))->toBe([
        'environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => sprintf(ModelShow::NOT_READABLE, 'session', 'sessions'), 'truncated' => false, 'data' => null,
    ]);
});

it('NEP4 model_show: a relation that the database refuses makes the whole call unavailable, and shows no attribute of the record', function (array $with) {
    neverEmptyPgsqlServe();
    // 01-21: an unexpected database error gives the SQLSTATE only, and the app's error log gets the exception.
    Exceptions::fake();

    $response = FixtureTools::call('model_show', ['model' => 'order', 'key' => 1, 'with' => $with]);

    expect(FixtureTools::envelope($response))->toBe([
        'environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => sprintf(ModelShow::REFUSED, 'order', '42501'), 'truncated' => false, 'data' => null,
    ])
        ->and($response->getContent())->not->toContain('A-1')
        ->and($response->getContent())->not->toContain('Widget');
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryPermissionDenied::class);
})->with([
    'alone' => [['customerSortedBySecret']],
    'after a relation that reads' => [['items', 'customerSortedBySecret']],
]);
