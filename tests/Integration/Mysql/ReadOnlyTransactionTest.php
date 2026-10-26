<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyCommitRefused;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\WriteAttempts;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ReadOnlyStage;

/*
 * Spec 5.9, 11.2 ("Read-only transaction for app code"), 01-16 on MySQL 8.4 and MariaDB (11.4 and
 * 10.11 in CI: testing_mysql has driver mariadb there). The default connection is testing_mysql with
 * root, so only the read-only transaction stops a write (1792).
 *
 * MySQL and MariaDB do not retry a 1792, so the session default still stops a write after an unbalanced
 * commit (RM7); PostgreSQL does (Pgsql RP7).
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function mysqlReadOnlyServe(array $config = []): void
{
    WriteAttempts::serve(['database.default' => 'testing_mysql', ...$config]);
    WriteAttempts::tables('testing_mysql');

    currentTest()->beforeApplicationDestroyed(function (): void {
        $db = DB::connection('testing_mysql');
        $db->rollBack(0);
        // A test may leave the session read-only (RM10); the drop needs it writable.
        $db->statement('SET SESSION TRANSACTION READ WRITE');
        WriteAttempts::drop('testing_mysql');
    });
}

function mysqlReadOnlyNote(string $action): string
{
    $envelope = WriteAttempts::call($action);

    expect($envelope['status'])->toBe('unavailable');

    return is_string($envelope['note']) ? $envelope['note'] : throw new RuntimeException('The envelope has no note.');
}

function mysqlReadOnlyIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/** The session read-only variable: MySQL 8.4 has transaction_read_only only, MariaDB 10.11 tx_read_only only. */
function mysqlReadOnlySession(): mixed
{
    $variable = mysqlReadOnlyIsMaria() ? 'tx_read_only' : 'transaction_read_only';

    return DB::connection('testing_mysql')->scalar('SELECT @@SESSION.'.$variable, [], false);
}

it('RM1 blocks an insert with 1792: the write note, no row', function () {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote('insert'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1);
});

it('RM2 blocks a retrieved observer and an accessor that write', function (string $action) {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_mysql', 'ro_events'))->toBe(0)
        ->and(DB::connection('testing_mysql')->table('ro_orders')->value('views'))->toBe(0);
})->with(['an observer' => ['observer'], 'an accessor' => ['accessor'], 'a returned model' => ['returned_model']]);

it('RM3 gives the cache note for a database cache put and a lock', function (string $action) {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::CACHE)
        ->and(WriteAttempts::count('testing_mysql', 'cache'))->toBe(0)
        ->and(WriteAttempts::count('testing_mysql', 'cache_locks'))->toBe(0);
})->with(['a put' => ['cache_database'], 'a lock' => ['cache_lock']]);

it('RM4 blocks a write in a nested DB::transaction() and DDL', function (string $action) {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1)
        ->and(Schema::connection('testing_mysql')->hasTable('ro_created'))->toBeFalse();
})->with(['nested' => ['nested'], 'DDL' => ['ddl']]);

it('RM5 refuses an unbalanced DB::commit(), and a write after a caught refusal is still blocked', function () {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote('commit'))->toContain(ReadOnlyCommitRefused::MESSAGE)
        ->and(mysqlReadOnlyNote('commit_caught'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1);
});

it('RM6 lets a caught write go on to read (MySQL does not abort the transaction)', function () {
    mysqlReadOnlyServe();

    expect(WriteAttempts::call('caught_write'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);
});

it('RM7 without the listener, the session default still stops the write after an unbalanced commit', function () {
    mysqlReadOnlyServe();
    app(Dispatcher::class)->forget(TransactionCommitting::class);

    expect(mysqlReadOnlyNote('commit'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_mysql`'))
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1);
});

it('RM8 restores the session after the call: level 0, read-only off, no mark, writes work', function () {
    mysqlReadOnlyServe();

    expect(WriteAttempts::call('read'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);

    DB::connection('testing_mysql')->table('ro_orders')->insert(['n' => 'after the call']);

    expect(DB::connection('testing_mysql')->transactionLevel())->toBe(0)
        ->and(mysqlReadOnlySession())->toBe(0)
        ->and(app(SessionLedger::class)->isReadOnly('testing_mysql'))->toBeFalse()
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(2);
});

it('RM9 puts back a session default that was read-only before the call', function () {
    mysqlReadOnlyServe();
    DB::connection('testing_mysql')->statement('SET SESSION TRANSACTION READ ONLY');

    expect(WriteAttempts::call('read')['status'])->toBe('ok')
        ->and(mysqlReadOnlySession())->toBe(1);
});

it('RM10 fails the call when app code reconnects; the write on the new session is saved (a stated limit)', function () {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote('reconnect'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_mysql`'))
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(2);
});

it('RM11 does not run the tool when the session is lost inside open()', function () {
    mysqlReadOnlyServe(['database.connections.ro_killer' => config('database.connections.testing_mysql')]);
    $killed = false;

    // The SET ran on this session; Laravel reconnects inside beginTransaction() and starts a read-write one.
    DB::connection('testing_mysql')->beforeStartingTransaction(function (Connection $starting) use (&$killed): void {
        if (! $killed) {
            $killed = true;
            $id = $starting->scalar('SELECT CONNECTION_ID()');
            DB::connection('ro_killer')->statement('KILL '.(is_int($id) ? $id : throw new RuntimeException('No connection id.')));
        }
    });

    expect(mysqlReadOnlyNote('read'))->toBe(sprintf(ReadOnlyStage::NOT_OPENED, 'connection `testing_mysql` was lost while the read-only transaction was set up.'))
        ->and($killed)->toBeTrue()
        ->and(WriteAttempts::ran())->toBeFalse()
        ->and(DB::connection('testing_mysql')->transactionLevel())->toBe(0);
});

it('RM12 lets 01-14 run in a savepoint on the same connection, then runs its deferred reset (Case B)', function () {
    mysqlReadOnlyServe();
    config(['runtime-mcp.database.connection' => 'testing_mysql', 'runtime-mcp.database.statement_timeout_ms' => 700]);
    $timeout = mysqlReadOnlyIsMaria() ? 'max_statement_time' : 'max_execution_time';

    expect(WriteAttempts::call('session'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]])
        ->and(app(SessionLedger::class)->pendingResets())->toBe([])
        // The reset left the PDO null; the next call, with no query between, reconnects before it opens.
        ->and(WriteAttempts::call('read')['status'])->toBe('ok');

    // The reset disconnected: the agent timeout of the savepoint did not outlive the call (MariaDB gives 0.000000).
    $value = DB::connection('testing_mysql')->scalar('SELECT @@SESSION.'.$timeout, [], false);

    expect(is_numeric($value) && (float) $value === 0.0)->toBeTrue('The session still has the agent timeout.');
});

it('RM14 fails the call when app code commits through the PDO; the session default blocks the write', function () {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote('pdo_commit'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_mysql`'))
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1)
        ->and(mysqlReadOnlySession())->toBe(0);
});

it('RM13 reads the session default with the write PDO of a read/write split connection', function () {
    mysqlReadOnlyServe([
        'database.connections.testing_mysql.read' => ['unix_socket' => '/nonexistent/rmcp.sock'],
        'database.connections.testing_mysql.write' => [],
    ]);

    expect(WriteAttempts::call('read'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);
});

it('RM15 begins a new read-only transaction after an unbalanced DB::rollBack(): the write is blocked, no row', function () {
    mysqlReadOnlyServe();

    expect(mysqlReadOnlyNote('rollback'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_mysql', 'ro_orders'))->toBe(1)
        ->and(DB::connection('testing_mysql')->transactionLevel())->toBe(0)
        ->and(mysqlReadOnlySession())->toBe(0);
});
