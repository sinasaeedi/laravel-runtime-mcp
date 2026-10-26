<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyCommitRefused;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\WriteAttempts;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ReadOnlyStage;

/*
 * Spec 5.9, 11.2 ("Read-only transaction for app code"), 01-16 on PostgreSQL. The default connection
 * is testing_pgsql with the privileged user, so only the read-only transaction stops a write.
 *
 * RP7 is the reason for RefuseReadOnlyCommit (Sina, Q1): Laravel lists "SQLSTATE[25006]: Read only sql
 * transaction" as a lost connection, so at level 0 it retries the write on a new session, and it is saved.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function pgsqlReadOnlyServe(array $config = []): void
{
    WriteAttempts::serve(['database.default' => 'testing_pgsql', ...$config]);
    WriteAttempts::tables('testing_pgsql');

    currentTest()->beforeApplicationDestroyed(function (): void {
        $db = DB::connection('testing_pgsql');
        $db->rollBack(0);
        // A test may leave the session read-only (RP10); the drop needs it writable.
        $db->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE');
        WriteAttempts::drop('testing_pgsql');
    });
}

function pgsqlReadOnlyNote(string $action): string
{
    $envelope = WriteAttempts::call($action);

    expect($envelope['status'])->toBe('unavailable');

    return is_string($envelope['note']) ? $envelope['note'] : throw new RuntimeException('The envelope has no note.');
}

function pgsqlReadOnlySetting(string $name): mixed
{
    return DB::connection('testing_pgsql')->scalar('SHOW '.$name, [], false);
}

it('RP1 blocks an insert with 25006: the write note, no row', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('insert'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1);
});

it('RP2 blocks a retrieved observer and an accessor that write', function (string $action) {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_events'))->toBe(0)
        ->and(DB::connection('testing_pgsql')->table('ro_orders')->value('views'))->toBe(0);
})->with(['an observer' => ['observer'], 'an accessor' => ['accessor'], 'a returned model' => ['returned_model']]);

it('RP3 gives the cache note for a database cache put (25006) and a lock (25P02 after the caught insert)', function (string $action) {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::CACHE)
        ->and(WriteAttempts::count('testing_pgsql', 'cache'))->toBe(0)
        ->and(WriteAttempts::count('testing_pgsql', 'cache_locks'))->toBe(0);
})->with(['a put' => ['cache_database'], 'a lock' => ['cache_lock']]);

it('RP4 blocks a write in a nested DB::transaction() and DDL', function (string $action) {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote($action))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1)
        ->and(Schema::connection('testing_pgsql')->hasTable('ro_created'))->toBeFalse();
})->with(['nested' => ['nested'], 'DDL' => ['ddl']]);

it('RP5 refuses an unbalanced DB::commit(), and a write after a caught refusal is still blocked', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('commit'))->toContain(ReadOnlyCommitRefused::MESSAGE)
        ->and(pgsqlReadOnlyNote('commit_caught'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1);
});

it('RP6 gives the aborted note when app code caught a blocked write and read on (25P02)', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('caught_write'))->toBe(WriteAttemptExplainer::ABORTED)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1);
});

it('RP7 proves the trap: without the listener an unbalanced commit lets the next write through, and close() says so', function () {
    pgsqlReadOnlyServe();
    app(Dispatcher::class)->forget(TransactionCommitting::class);

    expect(pgsqlReadOnlyNote('commit'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_pgsql`'))
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(2);
});

it('RP8 restores the session after the call: level 0, the default off, no mark, writes work', function () {
    pgsqlReadOnlyServe();

    expect(WriteAttempts::call('read'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);

    DB::connection('testing_pgsql')->table('ro_orders')->insert(['n' => 'after the call']);

    expect(DB::connection('testing_pgsql')->transactionLevel())->toBe(0)
        ->and(pgsqlReadOnlySetting('default_transaction_read_only'))->toBe('off')
        ->and(pgsqlReadOnlySetting('transaction_read_only'))->toBe('off')
        ->and(app(SessionLedger::class)->isReadOnly('testing_pgsql'))->toBeFalse()
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(2);
});

it('RP9 puts back a session default that was read-only before the call', function () {
    pgsqlReadOnlyServe();
    DB::connection('testing_pgsql')->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');

    expect(WriteAttempts::call('read')['status'])->toBe('ok')
        ->and(pgsqlReadOnlySetting('default_transaction_read_only'))->toBe('on');
});

it('RP10 fails the call when app code reconnects; the write on the new session is saved (a stated limit)', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('reconnect'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_pgsql`'))
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(2);
});

it('RP11 reads the session default with the write PDO of a read/write split connection', function () {
    pgsqlReadOnlyServe([
        'database.connections.testing_pgsql.read' => ['host' => '/nonexistent/rmcp'],
        'database.connections.testing_pgsql.write' => [],
    ]);

    expect(WriteAttempts::call('read'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);
});

it('RP12 lets 01-14 run in a savepoint on the same connection, then runs its deferred reset (Case B)', function () {
    pgsqlReadOnlyServe();
    config(['runtime-mcp.database.connection' => 'testing_pgsql']);
    DB::connection('testing_pgsql')->statement("SET SESSION application_name = 'rp12'");

    expect(WriteAttempts::call('session'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]])
        ->and(app(SessionLedger::class)->pendingResets())->toBe([])
        ->and(pgsqlReadOnlySetting('application_name'))->not->toBe('rp12');
});

it('RP14 fails the call when app code commits through the PDO; the session default blocks the write (level 1: no retry)', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('pdo_commit'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_pgsql`'))
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1)
        ->and(pgsqlReadOnlySetting('default_transaction_read_only'))->toBe('off');
});

it('RP13 does not run the tool when a listed connection cannot connect, names no host, and closes the default', function () {
    pgsqlReadOnlyServe([
        'database.connections.ro_down' => ['driver' => 'pgsql', 'host' => '/nonexistent/rmcp', 'database' => 'x', 'username' => 'x', 'password' => 'x'],
        'runtime-mcp.read_only_transactions.connections' => ['ro_down'],
    ]);

    $note = pgsqlReadOnlyNote('read');

    expect($note)->toBe(sprintf(ReadOnlyStage::NOT_OPENED, 'connection `ro_down` failed (PDOException).'))
        ->and(WriteAttempts::ran())->toBeFalse()
        ->and(DB::connection('testing_pgsql')->transactionLevel())->toBe(0)
        ->and(pgsqlReadOnlySetting('default_transaction_read_only'))->toBe('off');

    Exceptions::assertReported(PDOException::class);
});

it('RP15 begins a new read-only transaction after an unbalanced DB::rollBack(): the write fails at level 1, no retry, no row', function () {
    pgsqlReadOnlyServe();

    expect(pgsqlReadOnlyNote('rollback'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_pgsql', 'ro_orders'))->toBe(1)
        ->and(DB::connection('testing_pgsql')->transactionLevel())->toBe(0)
        ->and(pgsqlReadOnlySetting('default_transaction_read_only'))->toBe('off');
});
