<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec 11.2 ("SQL bypass attempts", "Row limit", "Session reset"), end to end on PostgreSQL: every attempt of
 * 01-14 (ReadOnlySessionTest P2-P7) and 01-15 (QueryRunnerTest QP15, QP17), sent as a real db_query call over HTTP
 * with a valid key and a Gate, in the strict environment prod-eu, as 01-11's read-only role. Each attempt is refused
 * with its note and changes no row (counted by the privileged connection); the row limit is `partial` with 500 rows;
 * a session lock is gone in the next call. 01-20's DbQueryTest already sends plain writes (DQP8), a missing table
 * (DQP9) and SET/LOCK (DQP12).
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** The password value of user 1: the test looks for it in every response. */
const SQL_PGSQL_PASSWORD = 'pw-7f3a-never-shown';

const SQL_PGSQL_READABLE = 'Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.';

function sqlPgsqlServe(): void
{
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', [
        'runtime-mcp.enabled' => true,
        'cache.limiter' => 'array',
        'runtime-mcp.database.statement_timeout_ms' => 500,
    ], SecuritySuite::ENVIRONMENT, function (string $privileged): void {
        $db = DB::connection($privileged);
        $db->table('users')->update(['password' => SQL_PGSQL_PASSWORD]);
        $db->getPdo()->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION rmcp_write_order() RETURNS int LANGUAGE plpgsql SECURITY DEFINER AS $$
            BEGIN
                INSERT INTO orders (user_id, number, total, status) VALUES (1, 'X-1', 1.00, 'new');
                RETURN 1;
            END $$
            SQL);
    });

    currentTest()->beforeApplicationDestroyed(function (): void {
        DB::connection('testing_pgsql')->getPdo()->exec('DROP FUNCTION IF EXISTS rmcp_write_order()');
    });

    Gates::allow('db_query');
}

/** @return array<array-key, mixed> the envelope, after one audit entry for the call */
function sqlPgsqlQuery(string $sql): array
{
    $response = FixtureTools::call('db_query', ['sql' => $sql]);

    expect($response->getContent())->not->toContain(SQL_PGSQL_PASSWORD);

    return FixtureTools::envelope($response);
}

/** @return array{int, int, int} rows in orders, users and order_items, counted by the privileged connection */
function sqlPgsqlCounts(): array
{
    $db = DB::connection('testing_pgsql');

    return [$db->table('orders')->count(), $db->table('users')->count(), $db->table('order_items')->count()];
}

it('SQP1 refuses every write attempt with its note, and no row changes', function (string $sql, string $note) {
    sqlPgsqlServe();
    $before = sqlPgsqlCounts();

    expect(sqlPgsqlQuery($sql))->toBe(['environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => $note, 'truncated' => false, 'data' => null])
        ->and(sqlPgsqlCounts())->toBe($before)
        ->and(DB::connection('testing_pgsql')->table('orders')->value('status'))->toBe('paid')
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_query', 'decision' => AuditEntry::DECISION_ALLOWED, 'outcome' => AuditEntry::OUTCOME_SUCCESS, 'params' => ['sql' => $sql]]);
})->with([
    'stacked statements (the driver)' => ['SELECT 1; DELETE FROM orders', 'The database rejected the query (SQLSTATE 42601): ERROR:  cannot insert multiple commands into a prepared statement.'],
    'an insert (the read-only transaction)' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1.00, 'new')", DbQuery::WROTE],
    'a data-modifying CTE' => ['WITH d AS (DELETE FROM orders RETURNING *) SELECT * FROM d', 'The database rejected the query (SQLSTATE 0A000): ERROR:  WITH clause containing a data-modifying statement must be at the top level.'],
    'EXPLAIN ANALYZE of a write' => ['EXPLAIN ANALYZE DELETE FROM orders', DbQuery::WROTE],
    'a temporary table' => ['CREATE TEMP TABLE rmcp_t (id int)', DbQuery::WROTE],
    'a SECURITY DEFINER function that writes' => ['SELECT rmcp_write_order()', DbQuery::WROTE],
]);

it('SQP2 stops pg_sleep() at the statement timeout, also when the same statement turns the timeout off', function (string $sql) {
    sqlPgsqlServe();
    $start = microtime(true);

    expect(sqlPgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => sprintf(DbQuery::TIMED_OUT, 500), 'data' => null])
        ->and(microtime(true) - $start)->toBeLessThan(2.0);
})->with([
    'pg_sleep' => ['SELECT pg_sleep(10)'],
    'set_config in the same statement' => ["SELECT set_config('statement_timeout', '0', true), pg_sleep(10)"],
]);

it('SQP3 never shows a masked column, in any SQL form', function (string $sql, string $note) {
    sqlPgsqlServe();

    expect(sqlPgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null])
        ->and(DB::connection('testing_pgsql')->table('users')->value('password'))->toBe(SQL_PGSQL_PASSWORD);
})->with([
    'SELECT *' => ['SELECT * FROM users', SQL_PGSQL_READABLE],
    'the column' => ['SELECT password FROM users', SQL_PGSQL_READABLE],
    'row_to_json' => ['SELECT row_to_json(u) FROM users u', SQL_PGSQL_READABLE],
    'to_jsonb' => ['SELECT to_jsonb(u) FROM users u', SQL_PGSQL_READABLE],
    'a whole-row reference' => ['SELECT u FROM users u', SQL_PGSQL_READABLE],
    'u.*' => ['SELECT u.* FROM users u', SQL_PGSQL_READABLE],
    'a view' => ['SELECT * FROM user_secrets', 'Table `user_secrets` does not exist, or the read-only role cannot read it.'],
    'a scalar subquery' => ['SELECT (SELECT password FROM users LIMIT 1)', SQL_PGSQL_READABLE],
    'a derived table' => ['SELECT x.p FROM (SELECT password AS p FROM users) x', SQL_PGSQL_READABLE],
    'TABLE' => ['TABLE users', SQL_PGSQL_READABLE],
]);

it('SQP4 cuts a large result inside the database: the poison row is never computed, wrapped or by cursor', function (string $sql) {
    sqlPgsqlServe();

    // A full fetch, or one row past 601, raises 21000 (a scalar subquery with two rows): partial with 500 rows proves
    // that the database stopped at max_rows + 1 (01-15 QP15). A memory reading proves nothing here (libpq's memory).
    $envelope = sqlPgsqlQuery($sql);

    expect($envelope)->toMatchArray(['status' => 'partial', 'note' => sprintf(DbQuery::MORE_ROWS, 500)])
        ->and(data_get($envelope, 'data.row_count'))->toBe(500)
        ->and(data_get($envelope, 'data.rows.499'))->toBe([500]);
})->with([
    'wrapped' => ['SELECT CASE WHEN g > 600 THEN (SELECT g UNION ALL SELECT g) ELSE g END AS v FROM generate_series(1, 2000000) g'],
    'cursor' => ['SELECT CASE WHEN g > 600 THEN (SELECT g UNION ALL SELECT g) ELSE g END AS v FROM generate_series(1, 2000000) g; -- trailing'],
]);

it('SQP5 takes an advisory lock in one call and leaves none for the next (DISCARD ALL)', function () {
    sqlPgsqlServe();
    // Advisory locks are global to the server: a random key keeps parallel runs apart.
    $key = random_int(1_000_000, 2_000_000_000);
    $privileged = DB::connection('testing_pgsql');

    $taken = sqlPgsqlQuery("SELECT pg_advisory_lock({$key})");
    $free = $privileged->scalar("SELECT pg_try_advisory_lock({$key})");
    $privileged->select('SELECT pg_advisory_unlock_all()');
    $next = sqlPgsqlQuery("SELECT pg_try_advisory_lock({$key}) AS got");

    expect($taken['status'])->toBe('ok')
        ->and($free)->toBeTrue()
        ->and(data_get($next, 'data.rows'))->toBe([[true]]);
});
