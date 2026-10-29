<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Limits\PostgresLimiter;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryResult;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryRunner;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 5.2, 11.2 ("Row limit", the masked-column half of "SQL bypass attempts"), rule 8, 01-15:
 * the query runner on PostgreSQL, as 01-11's read-only role: wrap, else cursor, else bare. Timeout
 * 500 ms unless a test says otherwise. testing_pgsql is the privileged connection.
 */

beforeEach(fn () => Databases::requiresPostgres());

/** @param  array<string, mixed>  $config */
function pgsqlRunnerRole(array $config = []): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', ['runtime-mcp.database.statement_timeout_ms' => 500, ...$config]);
}

function pgsqlRunner(string $sql, ?int $maxRows = null): QueryResult
{
    return app(QueryRunner::class)->run($sql, $maxRows);
}

/**
 * What the runner threw for $sql, with the seconds it took.
 *
 * @return array{QueryRejected, float}
 */
function pgsqlRunnerRejected(string $sql): array
{
    $start = microtime(true);

    try {
        pgsqlRunner($sql);
    } catch (QueryRejected $e) {
        return [$e, microtime(true) - $start];
    }

    throw new RuntimeException("Nothing was thrown for: {$sql}");
}

/**
 * The attempts the chain made for $sql inside the session frame, whatever it threw.
 *
 * @return list<LimitStrategy>
 */
function pgsqlRunnerAttempts(string $sql): array
{
    $seen = [];
    $limiter = new PostgresLimiter(function (LimitStrategy $strategy) use (&$seen): void {
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
function pgsqlRunnerTenOrders(): void
{
    $rows = [];

    for ($i = 2; $i <= 10; $i++) {
        $rows[] = ['user_id' => 1, 'number' => "SO-{$i}", 'total' => $i, 'status' => 'paid', 'created_at' => "2026-10-{$i} 09:00:00"];
    }

    DB::connection('testing_pgsql')->table('orders')->insert($rows);
}

it('QP1 a plain SELECT is wrapped: all rows, the columns in order, and no more when exactly max_rows match', function () {
    pgsqlRunnerRole();
    pgsqlRunnerTenOrders();

    $result = pgsqlRunner('SELECT * FROM orders ORDER BY id');

    expect($result->strategy)->toBe(LimitStrategy::Wrapped)
        ->and($result->columns)->toBe(['id', 'user_id', 'number', 'total', 'status', 'created_at'])
        ->and($result->rowCount)->toBe(10)
        ->and(count($result->rows))->toBe(10)
        ->and($result->rows[0][2])->toBe('A-1')
        ->and($result->hasMore)->toBeFalse()
        // Exactly max_rows rows: the extra row did not come back.
        ->and(pgsqlRunner('SELECT * FROM orders', 10)->hasMore)->toBeFalse()
        ->and($result->orderMayBeLost)->toBeFalse();
});

it('QP2 trailing semicolons, blanks and a trailing -- comment still wrap', function (string $sql) {
    pgsqlRunnerRole();

    expect(pgsqlRunner($sql)->strategy)->toBe(LimitStrategy::Wrapped);
})->with([
    'a semicolon' => ['SELECT * FROM orders;'],
    'blanks and semicolons' => ["SELECT * FROM orders ; ;\n\t "],
    'a trailing comment' => ['SELECT * FROM orders -- recent'],
]);

it('QP3 a statement the wrapper rejects but a cursor holds is cut by FETCH', function () {
    pgsqlRunnerRole();
    pgsqlRunnerTenOrders();

    $result = pgsqlRunner('SELECT id FROM orders ORDER BY id; -- recent', 4);

    expect($result->strategy)->toBe(LimitStrategy::Cursor)
        ->and($result->columns)->toBe(['id'])
        ->and($result->rows)->toBe([[1], [2], [3], [4]])
        ->and($result->hasMore)->toBeTrue();
});

it('QP4 two columns with the same name are both kept', function () {
    pgsqlRunnerRole();

    $result = pgsqlRunner('SELECT o.id, u.id FROM orders o JOIN users u ON u.id = o.user_id');

    expect($result->strategy)->toBe(LimitStrategy::Wrapped)
        ->and($result->columns)->toBe(['id', 'id'])
        ->and($result->rows)->toBe([[1, 1]]);
});

it('QP5 EXPLAIN and SHOW reach the bare step', function (string $sql, string $column) {
    pgsqlRunnerRole();

    $result = pgsqlRunner($sql);

    expect($result->strategy)->toBe(LimitStrategy::Bare)
        ->and($result->columns)->toBe([$column])
        ->and($result->rowCount)->toBeGreaterThan(0);
})->with([
    'EXPLAIN' => ['EXPLAIN SELECT * FROM orders', 'QUERY PLAN'],
    'SHOW' => ['SHOW search_path', 'search_path'],
]);

it('QP6 the agent max_rows is used, and never above database.max_rows', function (?int $asked, int $config, int $rows) {
    pgsqlRunnerRole(['runtime-mcp.database.max_rows' => $config]);

    $result = pgsqlRunner('SELECT g FROM generate_series(1, 1000) g', $asked);

    expect($result->rowCount)->toBe($rows)
        ->and($result->rows[$rows - 1])->toBe([$rows])
        ->and($result->hasMore)->toBeTrue();
})->with([
    'the default' => [null, 500, 500],
    'smaller' => [20, 500, 20],
    'larger than the config' => [10_000, 500, 500],
    'zero is one' => [0, 500, 1],
    'a bad config gives 500' => [null, 0, 500],
]);

it('QP7 an empty result keeps its columns', function () {
    pgsqlRunnerRole();

    $result = pgsqlRunner('SELECT id, number FROM orders WHERE false');

    expect($result->columns)->toBe(['id', 'number'])
        ->and($result->rows)->toBe([])
        ->and($result->rowCount)->toBe(0)
        ->and($result->hasMore)->toBeFalse();
});

it('QP8 values are made JSON-safe: bytea unread, NaN and Infinity as text, the rest as PDO returns them', function () {
    pgsqlRunnerRole();

    $result = pgsqlRunner("SELECT '\\xdeadbeef'::bytea, 'NaN'::float8, 'Infinity'::float8, '-Infinity'::float8, 1.5::float8, 2, true, 1.50::numeric, NULL");

    // pdo_pgsql gives float8 as a float from PHP 8.4, as a string before (NaN too, so value() leaves it).
    $float = PHP_VERSION_ID >= 80400 ? 1.5 : '1.5';

    expect($result->rows)->toBe([[QueryRunner::BINARY, 'NaN', 'Infinity', '-Infinity', $float, 2, true, '1.50', null]])
        ->and(json_encode($result->rows, JSON_THROW_ON_ERROR))->toBeString();
});

it('QP9 the timeout stops the query once: no second attempt, under 1 s', function () {
    pgsqlRunnerRole();

    [$e, $seconds] = pgsqlRunnerRejected('SELECT pg_sleep(10)');

    expect($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($seconds)->toBeLessThan(1.0)
        ->and(pgsqlRunnerAttempts('SELECT pg_sleep(10)'))->toBe([LimitStrategy::Wrapped]);
});

it('QP10 a permission error is not retried in another form', function () {
    pgsqlRunnerRole();

    [$e] = pgsqlRunnerRejected('SELECT password FROM users');

    expect($e)->toBeInstanceOf(QueryPermissionDenied::class)
        ->and(pgsqlRunnerAttempts('SELECT password FROM users'))->toBe([LimitStrategy::Wrapped]);
});

it('QP11 only a syntax error moves down the chain, and the last error is the database\'s own', function () {
    pgsqlRunnerRole();

    [$e] = pgsqlRunnerRejected('SELEC 1');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->sqlState)->toBe('42601')
        ->and($e->getMessage())->toContain('"SELEC"')
        ->and(pgsqlRunnerAttempts('SELEC 1'))->toBe([LimitStrategy::Wrapped, LimitStrategy::Cursor, LimitStrategy::Bare])
        ->and(pgsqlRunnerAttempts('SELECT 1; -- c'))->toBe([LimitStrategy::Wrapped, LimitStrategy::Cursor]);
});

it('QP12 PDO reads ? as a placeholder; ?? is the jsonb operator', function () {
    pgsqlRunnerRole();

    [$e] = pgsqlRunnerRejected("SELECT '{\"a\":1}'::jsonb ? 'a'");

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->getMessage())->toContain('$1')
        ->and(pgsqlRunner("SELECT '{\"a\":1}'::jsonb ?? 'a'")->rows)->toBe([[true]]);
});

it('QP13 a bare COPY fails at once, and the next call works', function () {
    pgsqlRunnerRole();

    [$e, $seconds] = pgsqlRunnerRejected('COPY (SELECT 1) TO STDOUT');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($seconds)->toBeLessThan(1.0)
        ->and(pgsqlRunner('SELECT 1')->rows)->toBe([[1]]);
});

it('QP14 ORDER BY inside the wrap is kept', function () {
    pgsqlRunnerRole();
    pgsqlRunnerTenOrders();

    $result = pgsqlRunner('SELECT id FROM orders ORDER BY id DESC', 3);

    expect($result->rows)->toBe([[10], [9], [8]])
        ->and($result->orderMayBeLost)->toBeFalse();
});

it('QP15 row limit: no row after max_rows + 1 is computed, wrapped or by cursor (poison row)', function (string $sql, LimitStrategy $strategy) {
    // generate_series() in FROM stores all its rows before the first one: about 0.2 s idle, more under load.
    // The poison row is the proof here, not the time (Sina, 2026-10-23).
    pgsqlRunnerRole(['runtime-mcp.database.statement_timeout_ms' => 5000]);

    // A full fetch, or one row past 601, raises 21000 (a scalar subquery with two rows).
    $result = pgsqlRunner($sql);

    expect($result->strategy)->toBe($strategy)
        ->and($result->rowCount)->toBe(500)
        ->and($result->rows[499])->toBe([500])
        ->and($result->hasMore)->toBeTrue();
})->with([
    'wrapped' => ['SELECT CASE WHEN g > 600 THEN (SELECT g UNION ALL SELECT g) ELSE g END AS v FROM generate_series(1, 2000000) g', LimitStrategy::Wrapped],
    'cursor' => ['SELECT CASE WHEN g > 600 THEN (SELECT g UNION ALL SELECT g) ELSE g END AS v FROM generate_series(1, 2000000) g; -- trailing', LimitStrategy::Cursor],
]);

it('QP16 row limit: 2,000,000 rows of 1,000 bytes leave PHP memory flat', function () {
    pgsqlRunnerRole(['runtime-mcp.database.statement_timeout_ms' => 5000]);

    memory_reset_peak_usage();
    $before = memory_get_peak_usage(true);
    $result = pgsqlRunner("SELECT g, repeat('x', 1000) FROM generate_series(1, 2000000) g");
    $grown = memory_get_peak_usage(true) - $before;

    // libpq's own memory is not counted here: QP15 is the proof on PostgreSQL, this one guards PHP.
    expect($result->rowCount)->toBe(500)
        ->and($result->hasMore)->toBeTrue()
        ->and($grown)->toBeLessThan(32 * 1024 * 1024);
});

it('QP17 a masked column is unreadable in every SQL form', function (string $sql) {
    pgsqlRunnerRole();

    [$e] = pgsqlRunnerRejected($sql);

    expect($e)->toBeInstanceOf(QueryPermissionDenied::class)
        ->and($e->sqlState)->toBe('42501')
        // The data exists, so "denied" is never "empty"; the readable columns still read.
        ->and(DB::connection('testing_pgsql')->table('users')->value('password'))->toBe('hash')
        ->and(pgsqlRunner('SELECT id, name, email FROM users')->rows)->toBe([[1, 'Ada', 'ada@example.com']]);
})->with([
    'SELECT *' => ['SELECT * FROM users'],
    'the column' => ['SELECT password FROM users'],
    'row_to_json' => ['SELECT row_to_json(u) FROM users u'],
    'to_jsonb' => ['SELECT to_jsonb(u) FROM users u'],
    'a whole-row reference' => ['SELECT u FROM users u'],
    'u.*' => ['SELECT u.* FROM users u'],
    'a view' => ['SELECT * FROM user_secrets'],
    'a scalar subquery' => ['SELECT (SELECT password FROM users LIMIT 1)'],
    'a derived table' => ['SELECT x.p FROM (SELECT password AS p FROM users) x'],
    'TABLE' => ['TABLE users'],
]);

it('QP18 Case B: the chain runs in savepoints inside the outer read-only transaction, which survives', function () {
    pgsqlRunnerRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->beginTransaction();
    $connection->statement('SET TRANSACTION READ ONLY');

    $cursor = pgsqlRunner('SELECT 1 AS a; -- c');
    $bare = pgsqlRunner('SHOW search_path');

    expect($cursor->strategy)->toBe(LimitStrategy::Cursor)
        ->and($cursor->rows)->toBe([[1]])
        ->and($bare->strategy)->toBe(LimitStrategy::Bare)
        ->and($connection->transactionLevel())->toBe(1)
        ->and($connection->scalar('SELECT 2'))->toBe(2);

    app(SessionLedger::class)->reset(ReadOnlyRole::CONNECTION);
});

it('QP19 a 42601 that the query raises as it runs never reaches the bare step', function (string $sql) {
    pgsqlRunnerRole();

    // The query fails only in the wrapped and the cursor forms, so a bare run would read all 5000 rows into PHP.
    [$e] = pgsqlRunnerRejected($sql);

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->sqlState)->toBe('42601')
        ->and(pgsqlRunnerAttempts($sql))->toBe([LimitStrategy::Wrapped, LimitStrategy::Cursor]);
})->with([
    'query_to_xml()' => ["SELECT g, CASE WHEN position('runtime' || '_mcp' IN current_query()) > 0 THEN query_to_xml('SELEC', true, false, '') END AS t FROM generate_series(1, 5000) g"],
    'to_tsquery()' => ["SELECT g, CASE WHEN position('runtime' || '_mcp' IN current_query()) > 0 THEN to_tsquery('!')::text END AS t FROM generate_series(1, 5000) g"],
]);

it('QP20 a connection with PDO::ERRMODE_SILENT still throws inside the frame, and gets its mode back', function (bool $outer) {
    pgsqlRunnerRole();
    config()->set('database.connections.'.ReadOnlyRole::CONNECTION.'.options', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    DB::purge(ReadOnlyRole::CONNECTION);
    $connection = DB::connection(ReadOnlyRole::CONNECTION);

    if ($outer) {
        $connection->beginTransaction();
        $connection->statement('SET TRANSACTION READ ONLY');
    }

    // Silent, a denied column and a timeout were an empty result (rule 11).
    [$denied] = pgsqlRunnerRejected('SELECT password FROM users');
    [$timedOut] = pgsqlRunnerRejected('SELECT pg_sleep(2)');

    expect($denied)->toBeInstanceOf(QueryPermissionDenied::class)
        ->and($timedOut)->toBeInstanceOf(QueryTimedOut::class)
        ->and($connection->getPdo()->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_SILENT);

    if ($outer) {
        app(SessionLedger::class)->reset(ReadOnlyRole::CONNECTION);
    }
})->with(['Case A' => [false], 'Case B' => [true]]);
