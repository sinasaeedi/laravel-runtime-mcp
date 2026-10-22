<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.2, 5.13, 11.2 ("SQL bypass attempts", "Session reset"), 01-14: the session frame on MySQL 8.4
 * and MariaDB (11.4 and 10.11 in CI: testing_mysql has driver mariadb there), as 01-11's read-only
 * role. Timeout 500 ms. testing_mysql is the privileged connection (root).
 *
 * On MySQL and MariaDB the grant stops a plain write before the read-only transaction (1142, not
 * 1792): only the definer-rights function (M5) proves the transaction layer here.
 */

// 125 million rows: about 4 s on MySQL 8.4 and 9 s on MariaDB without a timeout, so a broken timeout
// fails the test in seconds, never hangs it. With 500 ms it raises 3024 (MySQL) or 1969 (MariaDB).
const MYSQL_SESSION_LONG_QUERY = 'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 500) SELECT COUNT(*) AS c FROM r a, r b, r c';

beforeEach(fn () => Databases::requiresMysql());

/** @param  array<string, mixed>  $config */
function mysqlSessionRole(array $config = []): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_mysql', ['runtime-mcp.database.statement_timeout_ms' => 500, ...$config]);
}

function mysqlSessionIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/**
 * @template T
 *
 * @param  Closure(Connection): T  $work
 * @return T
 */
function mysqlSessionRun(Closure $work): mixed
{
    return app(ReadOnlySession::class)->run($work);
}

/** The first column of the first row of $sql, run inside the frame. */
function mysqlSessionScalar(string $sql): mixed
{
    return mysqlSessionRun(fn (Connection $connection): mixed => $connection->scalar($sql));
}

/**
 * What the frame threw for $sql, with the seconds it took.
 *
 * @return array{QueryRejected, float}
 */
function mysqlSessionRejected(string $sql): array
{
    $start = microtime(true);

    try {
        mysqlSessionRun(fn (Connection $connection): array => $connection->select($sql));
    } catch (QueryRejected $e) {
        return [$e, microtime(true) - $start];
    }

    throw new RuntimeException("Nothing was thrown for: {$sql}");
}

/** @return array{int, int} rows in orders and users, counted by the privileged connection */
function mysqlSessionCounts(): array
{
    $db = DB::connection('testing_mysql');

    return [(int) $db->table('orders')->count(), (int) $db->table('users')->count()];
}

/** The timeout variable of this server, read inside a frame or a transaction. */
function mysqlSessionTimeout(Connection $connection): mixed
{
    return $connection->scalar(mysqlSessionIsMaria() ? 'SELECT @@SESSION.max_statement_time' : 'SELECT @@SESSION.max_execution_time');
}

function mysqlSessionKill(Connection $connection): void
{
    $id = $connection->scalar('SELECT CONNECTION_ID()');

    DB::connection('testing_mysql')->statement('KILL '.(is_int($id) ? $id : throw new RuntimeException('No connection id.')));
}

/**
 * A definer-rights function owned by root that inserts an order. MySQL 8.4 has binary logging on, so
 * root needs DETERMINISTIC (else 1418); MariaDB accepts both forms.
 */
function mysqlSessionDefinerFunction(ReadOnlyRole $role): void
{
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
}

it('M1 runs the work in a read-only transaction under the timeout, returns its result, and ends the session', function () {
    mysqlSessionRole();

    $inside = mysqlSessionRun(fn (Connection $connection): array => [$connection->transactionLevel(), mysqlSessionTimeout($connection)]);

    expect($inside)->toBe([1, mysqlSessionIsMaria() ? 0.5 : 500])
        ->and(DB::connection(ReadOnlyRole::CONNECTION)->transactionLevel())->toBe(0);
});

it('M2 a second and a third call on the same connection work (the reset disconnects, the next call reconnects)', function () {
    mysqlSessionRole();

    expect(mysqlSessionScalar('SELECT 1'))->toBe(1)
        ->and(mysqlSessionScalar('SELECT 2'))->toBe(2)
        ->and(mysqlSessionScalar('SELECT 3'))->toBe(3);
});

it('M3 stacked statements fail at the driver (native prepare)', function () {
    mysqlSessionRole();
    $before = mysqlSessionCounts();

    [$e] = mysqlSessionRejected('SELECT 1; DELETE FROM orders');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->driverCode)->toBe(1064)
        ->and(mysqlSessionCounts())->toBe($before);
});

it('M4 a plain write is refused by the grant first (1142), and no data changes', function (string $sql) {
    mysqlSessionRole();
    $before = mysqlSessionCounts();

    [$e] = mysqlSessionRejected($sql);

    expect($e)->toBeInstanceOf(QueryPermissionDenied::class)
        ->and($e->driverCode)->toBe(1142)
        ->and(mysqlSessionCounts())->toBe($before);
})->with([
    'insert' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1.00, 'new')"],
    'delete' => ['DELETE FROM orders'],
]);

it('M5 a definer-rights function that writes is stopped by the read-only transaction (1792)', function () {
    $role = mysqlSessionRole();
    mysqlSessionDefinerFunction($role);
    [$orders] = mysqlSessionCounts();

    // Control: outside the frame the role's call writes (definer rights).
    DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT rmcp_write_order()');
    expect(mysqlSessionCounts()[0])->toBe($orders + 1);

    [$e] = mysqlSessionRejected('SELECT rmcp_write_order()');

    expect($e)->toBeInstanceOf(ReadOnlyViolation::class)
        ->and([$e->sqlState, $e->driverCode])->toBe(['25006', 1792])
        ->and(mysqlSessionCounts()[0])->toBe($orders + 1);
});

it('M6 EXPLAIN ANALYZE and ANALYZE of a write fail, each by the rule of its server', function () {
    mysqlSessionRole();
    $before = mysqlSessionCounts();

    [$explain] = mysqlSessionRejected('EXPLAIN ANALYZE DELETE FROM orders');
    [$analyze] = mysqlSessionRejected('ANALYZE DELETE FROM orders');

    // MySQL has EXPLAIN ANALYZE and no ANALYZE <statement>; MariaDB has it the other way round.
    $expected = mysqlSessionIsMaria()
        ? [[QueryFailed::class, 1064], [QueryPermissionDenied::class, 1142]]
        : [[QueryPermissionDenied::class, 1142], [QueryFailed::class, 1064]];

    expect([[$explain::class, $explain->driverCode], [$analyze::class, $analyze->driverCode]])->toBe($expected)
        ->and(mysqlSessionCounts())->toBe($before);
});

it('M7 a temporary table is refused: the role has no CREATE TEMPORARY TABLES (1044), and TEMP is not MySQL syntax', function () {
    mysqlSessionRole();

    [$temporary] = mysqlSessionRejected('CREATE TEMPORARY TABLE rmcp_t (id int)');
    [$temp] = mysqlSessionRejected('CREATE TEMP TABLE rmcp_t (id int)');

    expect([$temporary::class, $temporary->driverCode])->toBe([QueryPermissionDenied::class, 1044])
        ->and([$temp::class, $temp->driverCode])->toBe([QueryFailed::class, 1064]);
});

it('M8 the timeout stops a long query', function () {
    mysqlSessionRole();

    [$e, $seconds] = mysqlSessionRejected(MYSQL_SESSION_LONG_QUERY);

    expect($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($e instanceof QueryTimedOut ? $e->timeoutMs : null)->toBe(500)
        ->and($e->driverCode)->toBe(mysqlSessionIsMaria() ? 1969 : 3024)
        ->and($seconds)->toBeLessThan(2.0);
});

it('M9 SLEEP(10) is stopped by the timeout: MariaDB raises 1969, MySQL returns 1', function () {
    mysqlSessionRole();
    $start = microtime(true);
    $thrown = null;
    $slept = null;

    try {
        $slept = mysqlSessionScalar('SELECT SLEEP(10)');
    } catch (QueryRejected $e) {
        $thrown = $e;
    }

    expect(microtime(true) - $start)->toBeLessThan(2.0);

    if (mysqlSessionIsMaria()) {
        expect($thrown)->toBeInstanceOf(QueryTimedOut::class)
            ->and($thrown?->driverCode)->toBe(1969);
    } else {
        expect($thrown)->toBeNull()
            ->and($slept)->toBe(1);
    }
});

it('M10 a named lock and a user variable do not survive the call (disconnect)', function () {
    mysqlSessionRole();

    // Named locks are global to the server: a random name keeps parallel runs apart.
    $lock = 'rmcp_'.bin2hex(random_bytes(4));

    expect(mysqlSessionScalar("SELECT GET_LOCK('{$lock}', 0)"))->toBe(1)
        ->and(DB::connection('testing_mysql')->scalar("SELECT IS_FREE_LOCK('{$lock}')"))->toBe(1);

    mysqlSessionScalar('SELECT @x := 5');

    expect(mysqlSessionScalar('SELECT @x'))->toBeNull();
});

it('M11 native prepares are forced, also when the app config turns emulation on', function () {
    mysqlSessionRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.options' => [PDO::ATTR_EMULATE_PREPARES => true]]);

    // Control: with emulation, the server accepts two statements in one call.
    expect(DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT 1 AS a; SELECT 2 AS b'))->toHaveCount(1);

    [$e] = mysqlSessionRejected('SELECT 1 AS a; SELECT 2 AS b');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->driverCode)->toBe(1064);
});

it('M12 a connection killed during the work: the call throws QueryFailed, and the next call works', function () {
    mysqlSessionRole();
    $thrown = null;

    try {
        mysqlSessionRun(function (Connection $connection): array {
            mysqlSessionKill($connection);

            return $connection->select('SELECT 1');
        });
    } catch (QueryRejected $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryFailed::class)
        ->and(mysqlSessionScalar('SELECT 1'))->toBe(1);
});

it('M13 a connection lost while the frame starts: QueryFailed, and the work never runs', function () {
    mysqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $killed = false;
    $ran = false;

    // The SETs ran on this session; Laravel reconnects inside beginTransaction() and starts a new one.
    $connection->beforeStartingTransaction(function (Connection $starting) use (&$killed): void {
        if (! $killed) {
            $killed = true;
            mysqlSessionKill($starting);
        }
    });

    $thrown = null;

    try {
        mysqlSessionRun(function () use (&$ran): bool {
            $ran = true;

            return true;
        });
    } catch (QueryRejected $e) {
        $thrown = $e;
    }

    expect($killed)->toBeTrue()
        ->and($thrown)->toBeInstanceOf(QueryFailed::class)
        ->and($thrown?->getMessage())->toBe(ReadOnlySession::SESSION_LOST)
        ->and($ran)->toBeFalse()
        ->and(mysqlSessionScalar('SELECT 1'))->toBe(1);
});

it('M14 Case B: inside a marked read-only transaction the frame uses a savepoint and the timeout, and leaves the reset pending', function () {
    mysqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->statement('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $ledger = app(SessionLedger::class);
    $ledger->markReadOnly(ReadOnlyRole::CONNECTION);

    $inside = mysqlSessionRun(fn (Connection $c): array => [$c->transactionLevel(), mysqlSessionTimeout($c)]);
    [$e] = mysqlSessionRejected(MYSQL_SESSION_LONG_QUERY);
    $timeout = mysqlSessionIsMaria() ? 0.5 : 500;

    // The session variable outlives the savepoint rollback; 01-16's reset (a disconnect) ends it.
    expect($inside)->toBe([2, $timeout])
        ->and($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($connection->transactionLevel())->toBe(1)
        ->and(mysqlSessionTimeout($connection))->toBe($timeout)
        ->and($ledger->pendingResets())->toBe([ReadOnlyRole::CONNECTION]);

    $ledger->reset(ReadOnlyRole::CONNECTION);

    expect($connection->transactionLevel())->toBe(0)
        ->and($ledger->pendingResets())->toBe([])
        ->and(mysqlSessionScalar('SELECT 1'))->toBe(1);
});

it('M15 Case B: a transaction that the ledger does not mark (or no longer marks) is refused before any SQL runs', function (bool $markThenForget) {
    mysqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->statement('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();

    if ($markThenForget) {
        app(SessionLedger::class)->markReadOnly(ReadOnlyRole::CONNECTION);
        app(SessionLedger::class)->forget(ReadOnlyRole::CONNECTION);
    }

    $ran = false;
    $thrown = null;

    try {
        mysqlSessionRun(function () use (&$ran): bool {
            $ran = true;

            return true;
        });
    } catch (NotInReadOnlyTransaction $e) {
        $thrown = $e;
    }

    expect($thrown?->connection)->toBe(ReadOnlyRole::CONNECTION)
        ->and($ran)->toBeFalse()
        ->and($connection->transactionLevel())->toBe(1);

    $connection->rollBack();
})->with(['never marked' => [false], 'marked, then forgotten' => [true]]);

it('M16 a bad timeout value gives 5000 ms inside the frame, never 0', function () {
    mysqlSessionRole(['runtime-mcp.database.statement_timeout_ms' => -1]);

    expect(mysqlSessionRun(fn (Connection $connection): mixed => mysqlSessionTimeout($connection)))
        ->toBe(mysqlSessionIsMaria() ? 5.0 : 5000);
});

it('M17 MariaDB reached with the mysql driver gets the MariaDB timeout (isMaria)', function () {
    if (! mysqlSessionIsMaria()) {
        Assert::markTestSkipped('MariaDB only: MySQL has no other driver name.');
    }

    $role = mysqlSessionRole();
    config()->set('database.connections.rmcp_ro_as_mysql', [...$role->config(), 'driver' => 'mysql']);
    config()->set('runtime-mcp.database.connection', 'rmcp_ro_as_mysql');

    [$e] = mysqlSessionRejected('SELECT SLEEP(10)');

    expect(DB::connection('rmcp_ro_as_mysql')->getDriverName())->toBe('mysql')
        ->and($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($e->driverCode)->toBe(1969)
        // The reset disconnected: isMaria() must reconnect before it reads the server version.
        ->and(mysqlSessionScalar('SELECT 1'))->toBe(1);
});
