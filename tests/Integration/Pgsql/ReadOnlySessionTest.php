<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.2, 5.13, 11.2 ("SQL bypass attempts", "Session reset"), 01-14: the session frame on
 * PostgreSQL, as 01-11's read-only role (SELECT only, no write grant). The statement timeout is 500 ms
 * so the suite stays fast. testing_pgsql is the privileged connection (postgres, a superuser in CI).
 * On PostgreSQL the read-only transaction stops a write before the grant check (25006, not 42501).
 */

beforeEach(fn () => Databases::requiresPostgres());

/** @param  array<string, mixed>  $config */
function pgsqlSessionRole(array $config = []): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', ['runtime-mcp.database.statement_timeout_ms' => 500, ...$config]);
}

/**
 * @template T
 *
 * @param  Closure(Connection): T  $work
 * @return T
 */
function pgsqlSessionRun(Closure $work): mixed
{
    return app(ReadOnlySession::class)->run($work);
}

/** The first column of the first row of $sql, run inside the frame. */
function pgsqlSessionScalar(string $sql): mixed
{
    return pgsqlSessionRun(fn (Connection $connection): mixed => $connection->scalar($sql));
}

/**
 * What the frame threw for $sql, with the seconds it took.
 *
 * @return array{QueryRejected, float}
 */
function pgsqlSessionRejected(string $sql): array
{
    $start = microtime(true);

    try {
        pgsqlSessionRun(fn (Connection $connection): array => $connection->select($sql));
    } catch (QueryRejected $e) {
        return [$e, microtime(true) - $start];
    }

    throw new RuntimeException("Nothing was thrown for: {$sql}");
}

/** @return array{int, int} rows in orders and users, counted by the privileged connection */
function pgsqlSessionCounts(): array
{
    $db = DB::connection('testing_pgsql');

    return [(int) $db->table('orders')->count(), (int) $db->table('users')->count()];
}

/** The first column of the first row of $sql, run outside the frame on the read-only connection. */
function pgsqlSessionOutside(string $sql): mixed
{
    return DB::connection(ReadOnlyRole::CONNECTION)->scalar($sql);
}

function pgsqlSessionBackendPid(Connection $connection): int
{
    $pid = $connection->scalar('SELECT pg_backend_pid()');

    return is_int($pid) ? $pid : throw new RuntimeException('No backend pid.');
}

/** A function owned by the superuser that inserts an order (SECURITY DEFINER): the role grant does not apply inside it. */
function pgsqlSessionDefinerFunction(): void
{
    DB::connection('testing_pgsql')->getPdo()->exec(<<<'SQL'
        CREATE OR REPLACE FUNCTION rmcp_write_order() RETURNS int LANGUAGE plpgsql SECURITY DEFINER AS $$
        BEGIN
            INSERT INTO orders (user_id, number, total, status) VALUES (1, 'X-1', 1.00, 'new');
            RETURN 1;
        END $$
        SQL);

    currentTest()->beforeApplicationDestroyed(function (): void {
        DB::connection('testing_pgsql')->getPdo()->exec('DROP FUNCTION IF EXISTS rmcp_write_order()');
    });
}

it('P1 runs the work in a read-only transaction under the timeout, returns its result, and rolls back', function () {
    pgsqlSessionRole();

    $inside = pgsqlSessionRun(fn (Connection $connection): array => [
        $connection->transactionLevel(),
        $connection->scalar('SHOW transaction_read_only'),
        $connection->scalar('SHOW statement_timeout'),
        $connection->getName(),
    ]);

    expect($inside)->toBe([1, 'on', '500ms', ReadOnlyRole::CONNECTION])
        ->and(DB::connection(ReadOnlyRole::CONNECTION)->transactionLevel())->toBe(0)
        ->and(pgsqlSessionOutside('SHOW statement_timeout'))->toBe('0');
});

it('P2 stacked statements fail at the driver (native prepare)', function () {
    pgsqlSessionRole();
    $before = pgsqlSessionCounts();

    [$e] = pgsqlSessionRejected('SELECT 1; DELETE FROM orders');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and([$e->sqlState, $e->driverCode])->toBe(['42601', null])
        ->and($e->getMessage())->toContain('cannot insert multiple commands into a prepared statement')
        ->and(pgsqlSessionCounts())->toBe($before);
});

it('P3 a write fails in the read-only transaction, before the grant check', function (string $sql) {
    pgsqlSessionRole();
    $before = pgsqlSessionCounts();

    [$e] = pgsqlSessionRejected($sql);

    expect($e)->toBeInstanceOf(ReadOnlyViolation::class)
        ->and($e->sqlState)->toBe('25006')
        ->and(pgsqlSessionCounts())->toBe($before);
})->with([
    'insert' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1.00, 'new')"],
    'delete' => ['DELETE FROM orders'],
    'data-modifying CTE' => ['WITH d AS (DELETE FROM orders RETURNING *) SELECT * FROM d'],
    'EXPLAIN ANALYZE of a write' => ['EXPLAIN ANALYZE DELETE FROM orders'],
    'temp table' => ['CREATE TEMP TABLE rmcp_t (id int)'],
    'temporary table' => ['CREATE TEMPORARY TABLE rmcp_t (id int)'],
]);

it('P4 a SECURITY DEFINER function that writes is stopped by the transaction, not by the role', function () {
    pgsqlSessionRole();
    pgsqlSessionDefinerFunction();
    [$orders] = pgsqlSessionCounts();

    // Control: outside the frame the role's call writes (definer rights; PUBLIC has EXECUTE on PostgreSQL).
    DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT rmcp_write_order()');
    expect(pgsqlSessionCounts()[0])->toBe($orders + 1);

    [$e] = pgsqlSessionRejected('SELECT rmcp_write_order()');

    expect($e)->toBeInstanceOf(ReadOnlyViolation::class)
        ->and($e->sqlState)->toBe('25006')
        ->and(pgsqlSessionCounts()[0])->toBe($orders + 1);
});

it('P5 the timeout stops pg_sleep(), also when the same statement turns the timeout off', function (string $sql) {
    pgsqlSessionRole();

    [$e, $seconds] = pgsqlSessionRejected($sql);

    expect($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($e instanceof QueryTimedOut ? $e->timeoutMs : null)->toBe(500)
        ->and($e->sqlState)->toBe('57014')
        ->and($seconds)->toBeLessThan(2.0);
})->with([
    'pg_sleep' => ['SELECT pg_sleep(10)'],
    'set_config in the same statement' => ["SELECT set_config('statement_timeout', '0', true), pg_sleep(10)"],
]);

it('P6 an advisory lock does not survive the call (DISCARD ALL)', function () {
    pgsqlSessionRole();

    // Advisory locks are global to the server: a random key keeps parallel runs apart.
    $key = random_int(1_000_000, 2_000_000_000);

    expect(pgsqlSessionScalar("SELECT pg_advisory_lock({$key})"))->toBe('');

    $privileged = DB::connection('testing_pgsql');
    $got = $privileged->scalar("SELECT pg_try_advisory_lock({$key})");
    $privileged->select('SELECT pg_advisory_unlock_all()');

    expect($got)->toBeTrue()
        ->and(pgsqlSessionScalar("SELECT pg_try_advisory_lock({$key})"))->toBeTrue();
});

it('P7 a session setting does not survive into the next call on the same connection', function () {
    pgsqlSessionRole();

    pgsqlSessionScalar("SELECT set_config('search_path', 'pg_temp', false)");

    // DISCARD ALL resets to the role's default ("$user", public), not to Laravel's connect-time 'public'.
    expect(pgsqlSessionScalar('SHOW search_path'))->toBe('"$user", public')
        ->and(pgsqlSessionOutside('SHOW statement_timeout'))->toBe('0');
});

it('P8 native prepares are forced, also when the app config turns emulation on, and the setting is restored', function () {
    pgsqlSessionRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.options' => [PDO::ATTR_EMULATE_PREPARES => true]]);
    $connection = DB::connection(ReadOnlyRole::CONNECTION);

    // Control: with emulation, PostgreSQL accepts two statements in one call.
    expect($connection->select('SELECT 1 AS a; SELECT 2 AS b'))->toHaveCount(1);

    [$e] = pgsqlSessionRejected('SELECT 1 AS a; SELECT 2 AS b');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->sqlState)->toBe('42601')
        ->and($connection->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeTrue();
});

it('P9 a connection killed during the work: the call throws QueryFailed, and the next call works', function () {
    pgsqlSessionRole();

    $thrown = null;

    try {
        pgsqlSessionRun(function (Connection $connection): array {
            DB::connection('testing_pgsql')->select('SELECT pg_terminate_backend(?)', [pgsqlSessionBackendPid($connection)]);

            return $connection->select('SELECT 1');
        });
    } catch (QueryRejected $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryFailed::class)
        ->and(pgsqlSessionScalar('SELECT 1'))->toBe(1);
});

it('P10 a connection lost while the frame starts: QueryFailed, and the work never runs', function () {
    pgsqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $pid = pgsqlSessionBackendPid($connection);
    $killed = false;
    $ran = false;

    // Laravel reconnects inside beginTransaction() and sends BEGIN on a new session.
    $connection->beforeStartingTransaction(function () use ($pid, &$killed): void {
        if (! $killed) {
            $killed = true;
            DB::connection('testing_pgsql')->select('SELECT pg_terminate_backend(?)', [$pid]);
        }
    });

    $thrown = null;

    try {
        pgsqlSessionRun(function () use (&$ran): bool {
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
        ->and(DB::connection(ReadOnlyRole::CONNECTION))->not->toBe($connection)
        ->and(pgsqlSessionScalar('SELECT 1'))->toBe(1);
});

it('P11 Case B: inside a read-only transaction the frame uses a savepoint and the timeout, and leaves the reset pending', function () {
    pgsqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->beginTransaction();
    $connection->statement('SET TRANSACTION READ ONLY');
    $ledger = app(SessionLedger::class);

    $inside = pgsqlSessionRun(fn (Connection $c): array => [$c->transactionLevel(), $c->scalar('SHOW statement_timeout')]);
    [$e, $seconds] = pgsqlSessionRejected('SELECT pg_sleep(10)');

    expect($inside)->toBe([2, '500ms'])
        ->and($e)->toBeInstanceOf(QueryTimedOut::class)
        ->and($seconds)->toBeLessThan(2.0)
        ->and($connection->transactionLevel())->toBe(1)
        ->and($connection->scalar('SHOW statement_timeout'))->toBe('0')
        ->and($ledger->pendingResets())->toBe([ReadOnlyRole::CONNECTION]);

    $ledger->reset(ReadOnlyRole::CONNECTION);

    expect($connection->transactionLevel())->toBe(0)
        ->and($ledger->pendingResets())->toBe([]);
});

it('P12 Case B: a read-write outer transaction is refused before any SQL runs, even when the ledger marks it', function () {
    pgsqlSessionRole();
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->beginTransaction();
    app(SessionLedger::class)->markReadOnly(ReadOnlyRole::CONNECTION);
    $ran = false;
    $thrown = null;

    try {
        pgsqlSessionRun(function () use (&$ran): bool {
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
});

it('P13 a bad timeout value gives 5000 ms inside the frame, never 0', function () {
    pgsqlSessionRole(['runtime-mcp.database.statement_timeout_ms' => 'abc']);

    expect(pgsqlSessionScalar('SHOW statement_timeout'))->toBe('5s');
});

it('P14 the exception message is the database message, never the SQL or the connection', function () {
    pgsqlSessionRole();

    [$e] = pgsqlSessionRejected('SELECT pg_sleep(10) AS secret_marker');

    expect(str_contains($e->getMessage(), 'secret_marker'))->toBeFalse()
        ->and(str_contains($e->getMessage(), 'Connection:'))->toBeFalse()
        ->and(str_contains((string) $e->getPrevious()?->getMessage(), 'secret_marker'))->toBeTrue();
});

it('P15 Case B: native prepares are forced in the savepoint too, and the app setting is restored after', function () {
    pgsqlSessionRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.options' => [PDO::ATTR_EMULATE_PREPARES => true]]);
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->beginTransaction();
    $connection->statement('SET TRANSACTION READ ONLY');

    [$e] = pgsqlSessionRejected('SELECT 1 AS a; SELECT 2 AS b');

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->sqlState)->toBe('42601')
        ->and($connection->transactionLevel())->toBe(1)
        ->and($connection->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeTrue();

    $connection->rollBack();
});
