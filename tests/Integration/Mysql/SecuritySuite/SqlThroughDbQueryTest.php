<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
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
 * 01-30, spec 11.2 ("SQL bypass attempts", "Row limit", "Session reset"), end to end on MySQL 8.4 and MariaDB (CI runs
 * this file on MySQL 8.4, MariaDB 11.4 and 10.11): every attempt of 01-14 (ReadOnlySessionTest M3-M10) and 01-15
 * (QueryRunnerTest QM10, QM12, QM13), as a real db_query call over HTTP in prod-eu, as 01-11's read-only user. On
 * MySQL and MariaDB db_query runs only what the row-limit wrap accepts (01-15), so a write statement is refused before
 * it runs; a function that writes reaches the read-only transaction. No attempt changes a row.
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

const SQL_MYSQL_PASSWORD = 'pw-7f3a-never-shown';

function sqlMysqlIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/**
 * "MySQL could not run this query inside the row limit, so it was not run." or the MariaDB sentence (MySqlLimiter).
 *
 * @return non-empty-string
 */
function sqlMysqlNotLimitable(): string
{
    return (sqlMysqlIsMaria() ? 'MariaDB' : 'MySQL').' could not run this query inside the row limit, so it was not run.';
}

/** A table name as the server names it in a permission error: MariaDB adds the database for some forms. */
function sqlMysqlTable(string $table, bool $qualified): string
{
    return $qualified && sqlMysqlIsMaria() ? DB::connection('testing_mysql')->getDatabaseName().'.'.$table : $table;
}

/** @return non-empty-string */
function sqlMysqlReadable(bool $qualified = false): string
{
    return 'Table `'.sqlMysqlTable('users', $qualified).'` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.';
}

function sqlMysqlServe(): void
{
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_mysql', [
        'runtime-mcp.enabled' => true,
        'cache.limiter' => 'array',
        'runtime-mcp.database.statement_timeout_ms' => 500,
    ], SecuritySuite::ENVIRONMENT, function (string $privileged): void {
        DB::connection($privileged)->table('users')->update(['password' => SQL_MYSQL_PASSWORD]);
    });

    $db = DB::connection('testing_mysql');
    $database = $db->getDatabaseName();
    $db->getPdo()->exec('DROP FUNCTION IF EXISTS rmcp_write_order');
    $db->getPdo()->exec(<<<'SQL'
        CREATE FUNCTION rmcp_write_order() RETURNS INT DETERMINISTIC MODIFIES SQL DATA SQL SECURITY DEFINER
        BEGIN
            INSERT INTO orders (user_id, number, total, status) VALUES (1, 'X-1', 1.00, 'new');
            RETURN 1;
        END
        SQL);
    $db->getPdo()->exec("GRANT EXECUTE ON FUNCTION `{$database}`.`rmcp_write_order` TO '{$role->name()}'@'%'");

    currentTest()->beforeApplicationDestroyed(function (): void {
        DB::connection('testing_mysql')->getPdo()->exec('DROP FUNCTION IF EXISTS rmcp_write_order');
    });

    Gates::allow('db_query');
}

/** @return array<array-key, mixed> */
function sqlMysqlQuery(string $sql): array
{
    $response = FixtureTools::call('db_query', ['sql' => $sql]);

    expect($response->getContent())->not->toContain(SQL_MYSQL_PASSWORD);

    return FixtureTools::envelope($response);
}

/** @return array{int, int, int} */
function sqlMysqlCounts(): array
{
    $db = DB::connection('testing_mysql');

    return [$db->table('orders')->count(), $db->table('users')->count(), $db->table('order_items')->count()];
}

it('SQM1 refuses every write statement before it runs, and no row changes', function (string $sql) {
    sqlMysqlServe();
    $before = sqlMysqlCounts();
    $envelope = sqlMysqlQuery($sql);

    expect($envelope)->toMatchArray(['environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'data' => null])
        ->and($envelope['note'])->toBeString()->toStartWith(sqlMysqlNotLimitable())
        ->and(sqlMysqlCounts())->toBe($before)
        ->and(AuditLog::entries()[0] ?? null)->toMatchArray(['tool' => 'db_query', 'decision' => AuditEntry::DECISION_ALLOWED, 'outcome' => AuditEntry::OUTCOME_SUCCESS, 'params' => ['sql' => $sql]]);
})->with([
    'stacked statements' => ['SELECT 1; DELETE FROM orders'],
    'an insert' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1.00, 'new')"],
    'a delete' => ['DELETE FROM orders'],
    'a CTE before a delete' => ['WITH d AS (SELECT 1) DELETE FROM orders'],
    'EXPLAIN ANALYZE of a write' => ['EXPLAIN ANALYZE DELETE FROM orders'],
    'ANALYZE of a write (MariaDB)' => ['ANALYZE DELETE FROM orders'],
    'a temporary table' => ['CREATE TEMPORARY TABLE rmcp_t (id int)'],
]);

it('SQM2 stops a definer-rights function that writes in the read-only transaction (1792), and no row changes', function () {
    sqlMysqlServe();
    $before = sqlMysqlCounts();

    expect(sqlMysqlQuery('SELECT rmcp_write_order()'))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::WROTE, 'data' => null])
        ->and(sqlMysqlCounts())->toBe($before);
});

it('SQM3 stops a slow query at the statement timeout', function (string $sql) {
    sqlMysqlServe();
    $start = microtime(true);

    expect(sqlMysqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => sprintf(DbQuery::TIMED_OUT, 500), 'data' => null])
        ->and(microtime(true) - $start)->toBeLessThan(2.0);
})->with([
    'SLEEP' => ['SELECT SLEEP(10)'],
    'a long join' => ['WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 500) SELECT COUNT(*) AS c FROM r a, r b, r c'],
]);

it('SQM4 never shows a masked column, in any SQL form', function (string $sql, bool $qualified) {
    sqlMysqlServe();

    expect(sqlMysqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => sqlMysqlReadable($qualified), 'data' => null]);
})->with([
    'SELECT *' => ['SELECT * FROM users', true],
    'the column' => ['SELECT password FROM users', false],
    'u.*' => ['SELECT u.* FROM users u', true],
    'JSON_OBJECT' => ["SELECT JSON_OBJECT('p', password) FROM users", false],
    'a scalar subquery' => ['SELECT (SELECT password FROM users LIMIT 1)', false],
    'a derived table' => ['SELECT x.p FROM (SELECT password AS p FROM users) x', false],
]);

it('SQM5 refuses a view with no grant, and TABLE users by the rule of its server', function () {
    sqlMysqlServe();

    $view = sqlMysqlQuery('SELECT * FROM user_secrets');
    $table = sqlMysqlQuery('TABLE users');

    expect($view)->toMatchArray(['status' => 'unavailable', 'note' => 'Table `'.sqlMysqlTable('user_secrets', true).'` does not exist, or the read-only role cannot read it.'])
        ->and($table['status'])->toBe('unavailable')
        // MariaDB does not wrap TABLE (QM13): not limitable, never run. MySQL runs it and the grant refuses it.
        ->and($table['note'])->toBeString()->toStartWith(sqlMysqlIsMaria() ? sqlMysqlNotLimitable() : sqlMysqlReadable());
});

it('SQM6 cuts a large result inside MariaDB (poison row), and MySQL refuses the full derived table it would build', function () {
    sqlMysqlServe();

    $envelope = sqlMysqlQuery('WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 1000) '
        .'SELECT CASE WHEN n > 600 THEN (SELECT n UNION ALL SELECT n) ELSE n END AS v FROM s');

    if (sqlMysqlIsMaria()) {
        expect($envelope)->toMatchArray(['status' => 'partial', 'note' => sprintf(DbQuery::MORE_ROWS, 500).' '.DbQuery::MARIADB_ORDER])
            ->and(data_get($envelope, 'data.row_count'))->toBe(500);
    } else {
        // MySQL 8.4 builds a derived table with a CTE in full before the LIMIT (01-15): the poison row raises 21000, and
        // the answer says so. 01-15's QM11 memory test is the row-limit proof on MySQL.
        expect($envelope)->toMatchArray(['status' => 'unavailable', 'note' => 'The database rejected the query (SQLSTATE 21000): Subquery returns more than 1 row.']);
    }
});

it('SQM7 takes a named lock in one call and leaves it free for the next (disconnect)', function () {
    sqlMysqlServe();
    // Named locks are global to the server: a random name keeps parallel runs apart.
    $lock = 'rmcp_'.bin2hex(random_bytes(4));

    $taken = sqlMysqlQuery("SELECT GET_LOCK('{$lock}', 0) AS l");
    $free = DB::connection('testing_mysql')->scalar("SELECT IS_FREE_LOCK('{$lock}')");
    $next = sqlMysqlQuery("SELECT GET_LOCK('{$lock}', 0) AS l");

    expect(data_get($taken, 'data.rows'))->toBe([[1]])
        ->and($free)->toBe(1)
        ->and(data_get($next, 'data.rows'))->toBe([[1]]);
});
