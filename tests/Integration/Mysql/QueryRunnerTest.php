<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryNotLimitable;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Limits\MySqlLimiter;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryResult;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryRunner;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 5.2, 11.2 ("Row limit", the masked-column half of "SQL bypass attempts"), rule 8, 01-15:
 * the query runner on MySQL 8.4 and MariaDB (11.4 and 10.11 in CI), as 01-11's read-only role: wrap or
 * refuse, never the bare statement. Timeout 500 ms unless a test says otherwise.
 */

// Ten rows in a derived table; six of them cross-joined give 1,000,000 rows.
const MYSQL_RUNNER_TEN = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 '
    .'UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';

beforeEach(fn () => Databases::requiresMysql());

/** @param  array<string, mixed>  $config */
function mysqlRunnerRole(array $config = []): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_mysql', ['runtime-mcp.database.statement_timeout_ms' => 500, ...$config]);
}

function mysqlRunnerIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

function mysqlRunner(string $sql, ?int $maxRows = null): QueryResult
{
    return app(QueryRunner::class)->run($sql, $maxRows);
}

/**
 * What the runner threw for $sql, with the seconds it took.
 *
 * @return array{QueryRejected, float}
 */
function mysqlRunnerRejected(string $sql): array
{
    $start = microtime(true);

    try {
        mysqlRunner($sql);
    } catch (QueryRejected $e) {
        return [$e, microtime(true) - $start];
    }

    throw new RuntimeException("Nothing was thrown for: {$sql}");
}

/**
 * The attempts the limiter made for $sql inside the session frame, whatever it threw.
 *
 * @return list<LimitStrategy>
 */
function mysqlRunnerAttempts(string $sql): array
{
    $seen = [];
    $limiter = new MySqlLimiter(function (LimitStrategy $strategy) use (&$seen): void {
        $seen[] = $strategy;
    });

    try {
        app(ReadOnlySession::class)->run(fn (Connection $connection): array => $limiter->execute($connection, $sql, 501));
    } catch (QueryRejected) {
        // The attempts are the point here.
    }

    return $seen;
}

/** Nine more orders (ten in all), written by the privileged connection: rows need no grant. */
function mysqlRunnerTenOrders(): void
{
    $rows = [];

    for ($i = 2; $i <= 10; $i++) {
        $rows[] = ['user_id' => 1, 'number' => "SO-{$i}", 'total' => $i, 'status' => 'paid', 'created_at' => "2026-10-{$i} 09:00:00"];
    }

    DB::connection('testing_mysql')->table('orders')->insert($rows);
}

it('QM1 a plain SELECT is wrapped: all rows, the columns in order, and no more when exactly max_rows match', function () {
    mysqlRunnerRole();
    mysqlRunnerTenOrders();

    $result = mysqlRunner('SELECT * FROM orders');

    expect($result->strategy)->toBe(LimitStrategy::Wrapped)
        ->and($result->columns)->toBe(['id', 'user_id', 'number', 'total', 'status', 'created_at'])
        ->and($result->rowCount)->toBe(10)
        ->and(count($result->rows))->toBe(10)
        ->and($result->hasMore)->toBeFalse()
        // Exactly max_rows rows: the extra row did not come back.
        ->and(mysqlRunner('SELECT * FROM orders', 10)->hasMore)->toBeFalse()
        ->and($result->orderMayBeLost)->toBe(mysqlRunnerIsMaria());
});

it('QM2 trailing semicolons, blanks and a trailing comment still wrap', function (string $sql) {
    mysqlRunnerRole();

    expect(mysqlRunner($sql)->strategy)->toBe(LimitStrategy::Wrapped);
})->with([
    'a semicolon' => ['SELECT * FROM orders;'],
    'blanks and semicolons' => ["SELECT * FROM orders ; ;\n\t "],
    'a -- comment' => ['SELECT * FROM orders -- recent'],
    'a # comment' => ['SELECT * FROM orders # recent'],
]);

it('QM3 a statement the wrapper rejects is refused, never run bare, with the database\'s own message', function (string $sql, ?int $mysqlCode, int $mariaCode) {
    mysqlRunnerRole();
    $code = mysqlRunnerIsMaria() ? $mariaCode : $mysqlCode;

    if ($code === null) {
        Assert::markTestSkipped('MariaDB only.');
    }

    [$e] = mysqlRunnerRejected($sql);
    $name = mysqlRunnerIsMaria() ? 'MariaDB' : 'MySQL';

    expect($e)->toBeInstanceOf(QueryNotLimitable::class)
        ->and($e->driverCode)->toBe($code)
        ->and($e->getMessage())->toStartWith("{$name} could not run this query inside the row limit, so it was not run. On {$name}, db_query")
        ->and($e->getMessage())->toContain('The database said: ')
        ->and($e->getPrevious())->toBeInstanceOf(PDOException::class)
        ->and(mysqlRunnerAttempts($sql))->toBe([LimitStrategy::Wrapped]);
})->with([
    'a semicolon before a comment' => ['SELECT * FROM orders; -- recent', 1064, 1064],
    'EXPLAIN' => ['EXPLAIN SELECT * FROM orders', 1064, 1064],
    'SHOW' => ['SHOW TABLES', 1064, 1064],
    'a typo' => ['SELEC 1', 1064, 1064],
    'INTO a variable' => ['SELECT 1 INTO @v', 3954, 1064],
    'two columns named id' => ['SELECT o.id, u.id FROM orders o JOIN users u ON u.id = o.user_id', 1060, 1060],
    'MariaDB SET STATEMENT' => ['SET STATEMENT max_statement_time=0 FOR SELECT SLEEP(5)', null, 1064],
    'MariaDB ANALYZE' => ['ANALYZE SELECT * FROM orders', null, 1064],
]);

it('QM4 a typo shows the database\'s text, and the alias hint names the fix for duplicate columns', function () {
    mysqlRunnerRole();

    [$typo] = mysqlRunnerRejected('SELEC 1');
    [$duplicate] = mysqlRunnerRejected('SELECT o.id, u.id FROM orders o JOIN users u ON u.id = o.user_id');

    expect($typo->getMessage())->toContain('You have an error in your SQL syntax')
        ->and($duplicate->getMessage())->toContain('give each a unique alias, for example `orders.id AS order_id`')
        ->and($duplicate->getMessage())->toEndWith("The database said: Duplicate column name 'id'");
});

it('QM5 a top-level timeout hint is ignored inside the wrap: stopped at the session timeout', function () {
    mysqlRunnerRole();

    [$e, $seconds] = mysqlRunnerRejected('SELECT /*+ MAX_EXECUTION_TIME(60000) */ SLEEP(5)');

    expect($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($e->driverCode)->toBe(mysqlRunnerIsMaria() ? 1969 : 3024)
        ->and($seconds)->toBeLessThan(2.0)
        ->and(mysqlRunnerAttempts('SELECT SLEEP(10)'))->toBe([LimitStrategy::Wrapped]);
});

it('QM6 a permission error is not retried, and is not "not limitable"', function () {
    mysqlRunnerRole();

    [$e] = mysqlRunnerRejected('SELECT password FROM users');

    expect($e)->toBeInstanceOf(QueryPermissionDenied::class)
        ->and(mysqlRunnerAttempts('SELECT password FROM users'))->toBe([LimitStrategy::Wrapped]);
});

it('QM7 the agent max_rows is used, and never above database.max_rows', function (?int $asked, int $config, int $rows) {
    mysqlRunnerRole(['runtime-mcp.database.max_rows' => $config]);

    $result = mysqlRunner('SELECT a.d * 100 + b.d * 10 + c.d AS n FROM '.MYSQL_RUNNER_TEN.' a CROSS JOIN '.MYSQL_RUNNER_TEN.' b CROSS JOIN '.MYSQL_RUNNER_TEN.' c', $asked);

    expect($result->rowCount)->toBe($rows)
        ->and($result->hasMore)->toBeTrue();
})->with([
    'the default' => [null, 500, 500],
    'smaller' => [20, 500, 20],
    'larger than the config' => [10_000, 500, 500],
    'a bad config gives 500' => [null, -1, 500],
]);

it('QM8 an empty result keeps its columns', function () {
    mysqlRunnerRole();

    $result = mysqlRunner('SELECT id, number FROM orders WHERE 1 = 0');

    expect($result->columns)->toBe(['id', 'number'])
        ->and($result->rows)->toBe([])
        ->and($result->hasMore)->toBeFalse();
});

it('QM9 ORDER BY: MySQL keeps it; MariaDB loses it without an inner LIMIT and says so', function () {
    mysqlRunnerRole();
    mysqlRunnerTenOrders();

    $outer = mysqlRunner('SELECT id FROM orders ORDER BY id DESC', 3);
    $inner = mysqlRunner('SELECT id FROM orders ORDER BY id DESC LIMIT 50', 3);

    expect($inner->rows)->toBe([[10], [9], [8]])
        ->and($outer->orderMayBeLost)->toBe(mysqlRunnerIsMaria());

    if (mysqlRunnerIsMaria()) {
        // If a MariaDB release ever keeps the order, this fails: then orderMayBeLost can go.
        expect($outer->rows)->not->toBe([[10], [9], [8]]);
    } else {
        expect($outer->rows)->toBe([[10], [9], [8]]);
    }
});

it('QM10 row limit on MariaDB: no row after max_rows + 1 is computed (poison row)', function () {
    if (! mysqlRunnerIsMaria()) {
        // MySQL 8.4 builds a derived table with a CTE in full on the server before the LIMIT. QM11 is the proof there.
        Assert::markTestSkipped('MariaDB only.');
    }

    mysqlRunnerRole();

    // A full fetch, or one row past 601, raises 1242 (a scalar subquery with two rows).
    $result = mysqlRunner('WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 1000) '
        .'SELECT CASE WHEN n > 600 THEN (SELECT n UNION ALL SELECT n) ELSE n END AS v FROM s');

    expect($result->rowCount)->toBe(500)
        ->and($result->rows[499])->toBe([500])
        ->and($result->hasMore)->toBeTrue();
});

it('QM11 row limit: 2,000,000 rows of 1,000 bytes leave PHP memory flat (mysqlnd memory is counted)', function () {
    mysqlRunnerRole(['runtime-mcp.database.statement_timeout_ms' => 5000]);
    $ten = MYSQL_RUNNER_TEN;

    memory_reset_peak_usage();
    $before = memory_get_peak_usage(true);
    $result = mysqlRunner("SELECT a.d, REPEAT('x', 1000) AS pad FROM {$ten} a CROSS JOIN {$ten} b CROSS JOIN {$ten} c "
        ."CROSS JOIN {$ten} d CROSS JOIN {$ten} e CROSS JOIN {$ten} f CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1) g");
    $grown = memory_get_peak_usage(true) - $before;

    expect($result->rowCount)->toBe(500)
        ->and($result->hasMore)->toBeTrue()
        ->and($grown)->toBeLessThan(32 * 1024 * 1024);
});

it('QM12 a masked column is unreadable in every SQL form', function (string $sql) {
    mysqlRunnerRole();

    [$e] = mysqlRunnerRejected($sql);

    expect($e)->toBeInstanceOf(QueryPermissionDenied::class)
        // The data exists, so "denied" is never "empty"; the readable columns still read.
        ->and(DB::connection('testing_mysql')->table('users')->value('password'))->toBe('hash')
        ->and(mysqlRunner('SELECT id, name, email FROM users')->rows)->toBe([[1, 'Ada', 'ada@example.com']]);
})->with([
    'SELECT *' => ['SELECT * FROM users'],
    'the column' => ['SELECT password FROM users'],
    'u.*' => ['SELECT u.* FROM users u'],
    'JSON_OBJECT' => ["SELECT JSON_OBJECT('p', password) FROM users"],
    'a view' => ['SELECT * FROM user_secrets'],
    'a scalar subquery' => ['SELECT (SELECT password FROM users LIMIT 1)'],
    'a derived table' => ['SELECT x.p FROM (SELECT password AS p FROM users) x'],
]);

it('QM13 TABLE users: denied on MySQL, not limitable (and never run) on MariaDB', function () {
    mysqlRunnerRole();

    [$e] = mysqlRunnerRejected('TABLE users');

    expect($e)->toBeInstanceOf(mysqlRunnerIsMaria() ? QueryNotLimitable::class : QueryPermissionDenied::class);
});

it('QM14 MariaDB reached with the mysql driver is named MariaDB, and its order may be lost', function () {
    if (! mysqlRunnerIsMaria()) {
        Assert::markTestSkipped('MariaDB only: MySQL has no other driver name.');
    }

    $role = mysqlRunnerRole();
    config()->set('database.connections.rmcp_ro_as_mysql', [...$role->config(), 'driver' => 'mysql']);
    config()->set('runtime-mcp.database.connection', 'rmcp_ro_as_mysql');

    [$e] = mysqlRunnerRejected('SHOW TABLES');

    expect(DB::connection('rmcp_ro_as_mysql')->getDriverName())->toBe('mysql')
        ->and($e->getMessage())->toStartWith('MariaDB could not run')
        ->and(mysqlRunner('SELECT 1')->orderMayBeLost)->toBeTrue();
});

it('QM15 a BLOB comes back as PDO returns it, a string', function () {
    mysqlRunnerRole();

    expect(mysqlRunner("SELECT UNHEX('FFFE00') AS b, 1.5e0 AS d, 2 AS i, 1.50 AS n")->rows)
        ->toBe([["\xFF\xFE\x00", 1.5, 2, '1.50']]);
});
