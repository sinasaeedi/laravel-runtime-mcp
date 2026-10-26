<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyCommitRefused;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransactionEnded;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransactionUnavailable;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\WriteAttempts;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ReadOnlyStage;

/*
 * Spec 5.9, 11.2 ("Read-only transaction for app code"), 01-16, on SQLite (PRAGMA query_only): the
 * write_attempt tool runs behind the whole pipeline over HTTP, on the `testing` connection. The
 * PostgreSQL and MySQL halves are tests/Integration/{Pgsql,Mysql}/ReadOnlyTransactionTest.php.
 *
 * No RefreshDatabase: it holds a transaction on the default connection, and open() refuses that (RF19).
 * The tables are made after the boot: bootWith() makes a new app, so a new :memory: database.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function readOnlyStageServe(array $config = []): void
{
    WriteAttempts::serve($config);
    WriteAttempts::tables('testing');
}

/** The note of a failed call, with the checks every failed call shares. */
function readOnlyStageFailedNote(string $action): string
{
    $envelope = WriteAttempts::call($action);

    expect($envelope['status'])->toBe('unavailable');

    return is_string($envelope['note']) ? $envelope['note'] : throw new RuntimeException('The envelope has no note.');
}

function readOnlyStageQueryOnly(string $connection = 'testing'): mixed
{
    return DB::connection($connection)->scalar('PRAGMA query_only');
}

it('RF1 blocks an insert from a tool: the write note, no row, an audited error', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('insert'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(WriteAttempts::ran())->toBeTrue();

    $entry = AuditLog::entries()[0];
    expect($entry['outcome'])->toBe('error')
        ->and($entry['error_class'])->toBe(QueryException::class);
});

it('RF2 blocks a retrieved observer that writes while a tool loads the model', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('observer'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_events'))->toBe(0);
});

it('RF3 blocks an accessor that calls increment()', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('accessor'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(DB::table('ro_orders')->value('views'))->toBe(0);
});

it('RF4 gives the cache note for a database cache write, and writes no row', function (string $action) {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote($action))->toBe(WriteAttemptExplainer::CACHE)
        ->and(WriteAttempts::count('testing', 'cache'))->toBe(0)
        ->and(WriteAttempts::count('testing', 'cache_locks'))->toBe(0);
})->with(['a put' => ['cache_database'], 'a lock' => ['cache_lock']]);

it('RF5 lets an array cache write through: it is not a database write', function () {
    readOnlyStageServe();

    expect(WriteAttempts::call('cache_array')['status'])->toBe('ok');
});

it('RF6 blocks a write in a nested DB::transaction() (a savepoint stays read-only)', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('nested'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(DB::connection('testing')->transactionLevel())->toBe(0);
});

it('RF7 refuses an unbalanced DB::commit(), and a write after a caught refusal is still blocked', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('commit'))->toContain(ReadOnlyCommitRefused::class.': '.ReadOnlyCommitRefused::MESSAGE)
        ->and(readOnlyStageFailedNote('commit_caught'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1);
});

it('RF8 blocks DDL from a tool', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('ddl'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(Schema::connection('testing')->hasTable('ro_created'))->toBeFalse();
});

it('RF9 restores the connection after the call: level 0, query_only off, no mark, writes work again', function () {
    readOnlyStageServe();

    $envelope = WriteAttempts::call('read');
    DB::table('ro_orders')->insert(['n' => 'after the call']);

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['data'])->toBe(['value' => 1])
        ->and(DB::connection('testing')->transactionLevel())->toBe(0)
        ->and(readOnlyStageQueryOnly())->toBe(0)
        ->and(app(SessionLedger::class)->isReadOnly('testing'))->toBeFalse()
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(2);
});

it('RF10 puts back a session default that was read-only before the call', function () {
    readOnlyStageServe();
    DB::connection('testing')->statement('PRAGMA query_only = ON');

    expect(WriteAttempts::call('read')['status'])->toBe('ok')
        ->and(readOnlyStageQueryOnly())->toBe(1);

    DB::connection('testing')->statement('PRAGMA query_only = OFF');
});

it('RF11 lets a caught write go on to read on SQLite (only PostgreSQL aborts the transaction)', function () {
    readOnlyStageServe();

    expect(WriteAttempts::call('caught_write'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);
});

it('RF12 fails the call when app code reconnects: the transaction ended under it', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('reconnect'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing`'))
        ->and(AuditLog::entries()[0]['error_class'])->toBe(ReadOnlyTransactionEnded::class);
});

it('RF13 holds a listed connection read-only too, and only when it is listed', function () {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => ['testing_readonly']]);
    WriteAttempts::tables('testing_readonly');

    expect(readOnlyStageFailedNote('insert_listed'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing_readonly', 'ro_orders'))->toBe(1)
        ->and(readOnlyStageQueryOnly('testing_readonly'))->toBe(0);

    config(['runtime-mcp.read_only_transactions.connections' => null]);

    expect(WriteAttempts::call('insert_listed')['status'])->toBe('ok')
        ->and(WriteAttempts::count('testing_readonly', 'ro_orders'))->toBe(2);
});

it('RF14 does not run the tool when a listed connection is unknown', function () {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => ['nope']]);

    expect(readOnlyStageFailedNote('read'))->toBe(sprintf(ReadOnlyStage::NOT_OPENED, "connection `nope` is not in config('database.connections')."))
        ->and(WriteAttempts::ran())->toBeFalse()
        ->and(AuditLog::entries()[0]['error_class'])->toBe(ReadOnlyTransactionUnavailable::class);
});

it('RF15 closes the connections it opened when a later one fails (a partial open)', function () {
    readOnlyStageServe([
        'database.connections.ro_sqlsrv' => ['driver' => 'sqlsrv', 'host' => '/nonexistent/rmcp', 'database' => 'x', 'username' => 'x', 'password' => 'x'],
        'runtime-mcp.read_only_transactions.connections' => ['testing_readonly', 'ro_sqlsrv'],
    ]);

    expect(readOnlyStageFailedNote('read'))->toBe(sprintf(
        ReadOnlyStage::NOT_OPENED,
        'connection `ro_sqlsrv` uses the `sqlsrv` driver; read-only transactions support PostgreSQL, MySQL, MariaDB and SQLite.',
    ))
        ->and(WriteAttempts::ran())->toBeFalse();

    foreach (['testing', 'testing_readonly'] as $name) {
        expect(DB::connection($name)->transactionLevel())->toBe(0, $name)
            ->and(readOnlyStageQueryOnly($name))->toBe(0, $name)
            ->and(app(SessionLedger::class)->isReadOnly($name))->toBeFalse($name);
    }
});

it('RF16 fails closed for a read_only_transactions.connections value of the wrong type', function (mixed $value) {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => $value]);

    expect(readOnlyStageFailedNote('read'))->toBe(sprintf(ReadOnlyStage::NOT_OPENED, 'read_only_transactions.connections is not a list of connection names.'))
        ->and(WriteAttempts::ran())->toBeFalse();
})->with([
    'a string' => ['testing_readonly'],
    'an empty name' => [['']],
    'a nested list' => [[['testing_readonly']]],
    'a map' => [['x' => 'testing_readonly']],
]);

it('RF17 refuses a connection that is already in a transaction, and leaves that transaction alone', function () {
    readOnlyStageServe();
    DB::connection('testing')->beginTransaction();

    expect(readOnlyStageFailedNote('read'))->toBe(sprintf(
        ReadOnlyStage::NOT_OPENED,
        'connection `testing` is already in a transaction (a test with RefreshDatabase, or app code that left one open).',
    ))
        ->and(WriteAttempts::ran())->toBeFalse()
        ->and(DB::connection('testing')->transactionLevel())->toBe(1);

    DB::connection('testing')->rollBack();
});

it('RF18 discards a connection whose session default cannot be restored, keeps the result, and reports why', function () {
    readOnlyStageServe();
    $before = DB::connection('testing');
    $before->beforeExecuting(function (string $query): void {
        if ($query === 'PRAGMA query_only = OFF') {
            throw new RuntimeException('restore failed');
        }
    });

    expect(WriteAttempts::call('read')['status'])->toBe('ok')
        ->and(DB::connection('testing'))->not->toBe($before);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'restore failed');
});

it('RF19 fails closed when database.default names no connection', function () {
    readOnlyStageServe(['database.default' => 'missing']);

    expect(readOnlyStageFailedNote('read'))->toBe(sprintf(ReadOnlyStage::NOT_OPENED, 'database.default does not name a connection in config/database.php.'))
        ->and(WriteAttempts::ran())->toBeFalse();
});

it('RF20 never prints a connection name that does not look like one', function () {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => ['rmcp_'.str_repeat('k', 43).'!']]);

    $note = readOnlyStageFailedNote('read');

    expect($note)->toBe(sprintf(ReadOnlyStage::NOT_OPENED, "a listed connection is not in config('database.connections')."))
        ->and(str_contains($note, 'rmcp_'))->toBeFalse();
});

it('RF22 reads the session default with the write PDO of a read/write split connection', function () {
    readOnlyStageServe([
        'database.connections.testing.read' => ['database' => '/nonexistent/rmcp/read.sqlite'],
        'database.connections.testing.write' => [],
    ]);

    // Only the call: a count() at level 0 here would read from the missing read database.
    expect(WriteAttempts::call('read'))->toMatchArray(['status' => 'ok', 'data' => ['value' => 1]]);
});

it('RF23 fails the call when app code commits through the PDO, and the session default still blocks the write', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('pdo_commit'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing`'))
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(readOnlyStageQueryOnly())->toBe(0);
});

it('RF24 opens the default connection once when it is listed again', function () {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => ['testing', 'testing_readonly', 'testing_readonly']]);

    expect(WriteAttempts::call('read')['status'])->toBe('ok');
});

it('RF21 leaves the app own commits alone, also after a tool call in the same request', function () {
    readOnlyStageServe();

    WriteAttempts::call('read');
    DB::transaction(fn (): bool => DB::table('ro_orders')->insert(['n' => 'app commit']));

    // Level 0: a real commit. The count alone also sees a row inside a transaction that is still open.
    expect(DB::connection('testing')->transactionLevel())->toBe(0)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(2);
});

it('RF25 turns a model in the data into arrays inside the transaction, so its accessor cannot write', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('returned_model'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(DB::table('ro_orders')->value('views'))->toBe(0);
});

it('RF26 still closes the default connection when app code reconnected a listed one that cannot connect', function () {
    readOnlyStageServe(['runtime-mcp.read_only_transactions.connections' => ['testing_readonly']]);

    expect(readOnlyStageFailedNote('reconnect_listed_down'))->toBe(sprintf(ReadOnlyStage::ENDED, 'connection `testing_readonly`'))
        ->and(DB::connection('testing')->transactionLevel())->toBe(0)
        ->and(readOnlyStageQueryOnly())->toBe(0)
        ->and(app(SessionLedger::class)->isReadOnly('testing'))->toBeFalse();
});

it('RF27 refuses the commit on a connection whose config sets its own name', function () {
    readOnlyStageServe(['database.connections.testing.name' => 'primary']);

    expect(readOnlyStageFailedNote('commit'))->toContain(ReadOnlyCommitRefused::class.': '.ReadOnlyCommitRefused::MESSAGE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(app(SessionLedger::class)->isReadOnly('primary'))->toBeFalse();
});

it('RF28 begins a new read-only transaction after an unbalanced DB::rollBack(), so the write after it is blocked', function () {
    readOnlyStageServe();

    expect(readOnlyStageFailedNote('rollback'))->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(DB::connection('testing')->transactionLevel())->toBe(0)
        ->and(readOnlyStageQueryOnly())->toBe(0)
        ->and(app(SessionLedger::class)->isReadOnly('testing'))->toBeFalse();
});

it('RF29 leaves the app own rollbacks alone, also after a tool call in the same request', function () {
    readOnlyStageServe();

    WriteAttempts::call('read');
    DB::beginTransaction();
    DB::table('ro_orders')->insert(['n' => 'app rollback']);
    DB::rollBack();

    expect(DB::connection('testing')->transactionLevel())->toBe(0)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1);
});

it('RF30 leaves a rollback to a savepoint alone: the marked transaction is still open, and nothing more', function () {
    readOnlyStageServe();
    $connection = DB::connection('testing');
    $ledger = app(SessionLedger::class);
    $ledger->markReadOnly('testing');
    $connection->beginTransaction();
    $connection->beginTransaction();

    $connection->rollBack();
    $level = $connection->transactionLevel();

    // The mark first, as close() does it, or this rollBack(0) begins a new transaction.
    $ledger->forget('testing');
    $connection->rollBack(0);

    expect($level)->toBe(1)
        ->and($connection->transactionLevel())->toBe(0);
});
