<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 3.1, 5.2, 5.13, 01-14 Parts A and B without a database server: the driver check, the
 * connection choice, and the scoped ledger. The real frame runs in tests/Integration.
 */

/**
 * Runs ReadOnlySession and returns what it threw, and whether $work ran.
 *
 * @return array{?Throwable, bool}
 */
function sessionAttempt(): array
{
    $ran = false;

    try {
        app(ReadOnlySession::class)->run(function (Connection $connection) use (&$ran): bool {
            $ran = true;

            return true;
        });
    } catch (Throwable $e) {
        return [$e, $ran];
    }

    return [null, $ran];
}

it('F1 refuses SQLite with UnsupportedDriver, and never runs the work', function () {
    currentTest()->bootWith();

    [$thrown, $ran] = sessionAttempt();

    expect($thrown)->toBeInstanceOf(UnsupportedDriver::class)
        ->and($thrown instanceof UnsupportedDriver ? $thrown->driver : null)->toBe('sqlite')
        ->and($ran)->toBeFalse()
        ->and(config('runtime-mcp.database.connection'))->toBe(TestCase::READ_ONLY_CONNECTION);
});

it('F2 uses the default connection when database.connection is unset (relaxed environments only)', function (mixed $unset) {
    currentTest()->bootWith([
        'runtime-mcp.database.connection' => $unset,
        'database.connections.session_default' => ['driver' => 'sqlsrv', 'host' => 'db.invalid', 'database' => 'app'],
        'database.default' => 'session_default',
    ]);

    [$thrown, $ran] = sessionAttempt();

    // sqlsrv proves the default connection was chosen; Frames reads the driver name and never connects.
    expect($thrown instanceof UnsupportedDriver ? $thrown->driver : $thrown)->toBe('sqlsrv')
        ->and($ran)->toBeFalse();
})->with(['null' => [null], 'empty' => ['']]);

it('F3 the ledger is scoped: one per request, a new one after the scope ends (rule 14)', function () {
    currentTest()->bootWith();

    $ledger = app(SessionLedger::class);
    $ledger->markReadOnly('runtime_mcp_ro');
    $ledger->deferReset('runtime_mcp_ro');

    expect(app(SessionLedger::class))->toBe($ledger);

    app()->forgetScopedInstances();
    $fresh = app(SessionLedger::class);

    expect($fresh)->not->toBe($ledger)
        ->and($fresh->isReadOnly('runtime_mcp_ro'))->toBeFalse()
        ->and($fresh->pendingResets())->toBe([]);
});

it('F4 ReadOnlySession is not a singleton, so it never keeps an old ledger', function () {
    currentTest()->bootWith();

    expect(app(ReadOnlySession::class))->not->toBe(app(ReadOnlySession::class));
});

it('F5 the ledger keeps marks and pending resets per connection, once each', function () {
    currentTest()->bootWith();
    $ledger = app(SessionLedger::class);

    $ledger->markReadOnly('a');
    $ledger->markReadOnly('a');
    $ledger->deferReset('b');
    $ledger->deferReset('b');
    $ledger->deferReset('c');

    expect([$ledger->isReadOnly('a'), $ledger->isReadOnly('b')])->toBe([true, false])
        ->and([$ledger->needsReset('b'), $ledger->needsReset('a')])->toBe([true, false])
        ->and($ledger->pendingResets())->toBe(['b', 'c']);
});

it('F6 forget() removes the read-only mark and keeps a pending reset', function () {
    currentTest()->bootWith();
    $ledger = app(SessionLedger::class);
    $ledger->markReadOnly('a');
    $ledger->markReadOnly('b');
    $ledger->deferReset('a');

    $ledger->forget('a');

    expect([$ledger->isReadOnly('a'), $ledger->isReadOnly('b')])->toBe([false, true])
        ->and($ledger->pendingResets())->toBe(['a']);
});

it('F7 reset() never throws: a connection it cannot reset is discarded, and the reset is no longer pending', function () {
    currentTest()->bootWith();
    $ledger = app(SessionLedger::class);
    $before = app('db')->connection(TestCase::READ_ONLY_CONNECTION);
    $ledger->deferReset(TestCase::READ_ONLY_CONNECTION);
    $ledger->deferReset('other');

    // SQLite has no frame (UnsupportedDriver inside the reset), so the connection is thrown away.
    $ledger->reset(TestCase::READ_ONLY_CONNECTION);

    expect($ledger->pendingResets())->toBe(['other'])
        ->and(app('db')->connection(TestCase::READ_ONLY_CONNECTION))->not->toBe($before);
});

it('F8 reset() of a name with no connection made (unknown, or purged) never throws and is no longer pending', function () {
    currentTest()->bootWith();
    $ledger = app(SessionLedger::class);
    $ledger->deferReset('not_configured');
    $ledger->deferReset('other');

    $ledger->reset('not_configured');

    expect($ledger->pendingResets())->toBe(['other'])
        ->and(app('db')->getConnections())->not->toHaveKey('not_configured');
});

it('F9 reset() finds a connection by getName(), the name Case B defers, also when a config name key differs from its key', function () {
    currentTest()->bootWith();
    config()->set('database.connections.rmcp_key', ['driver' => 'sqlite', 'database' => ':memory:', 'name' => 'rmcp_renamed']);
    $connection = app('db')->connection('rmcp_key');
    $connection->getPdo();
    $ledger = app(SessionLedger::class);
    $ledger->deferReset((string) $connection->getName());

    // SQLite has no frame (UnsupportedDriver inside the reset), so the connection is discarded.
    $ledger->reset('rmcp_renamed');

    expect($connection->getName())->toBe('rmcp_renamed')
        ->and($ledger->pendingResets())->toBe([])
        ->and($connection->getRawPdo())->toBeNull();
});

it('F10 refuses the default connection in a strict environment, also when no plugin requires the read-only connection', function (mixed $configured) {
    currentTest()->bootWith([
        'runtime-mcp.plugins.database' => false,
        'runtime-mcp.plugins.models' => false,
        'runtime-mcp.database.connection' => $configured,
        'database.connections.session_default' => ['driver' => 'sqlsrv', 'host' => 'db.invalid', 'database' => 'app'],
        'database.default' => 'session_default',
    ], 'production');

    [$thrown, $ran] = sessionAttempt();

    // Not UnsupportedDriver: the frame refused before it looked at the default connection.
    expect($thrown)->toBeInstanceOf(QueryFailed::class)
        ->and($thrown?->getMessage())->toBe(ReadOnlySession::NO_READ_ONLY_CONNECTION)
        ->and($ran)->toBeFalse();
})->with(['null' => [null], 'empty' => [''], 'blank' => ['  '], 'the default connection' => ['session_default']]);

it('F11 uses a separate read-only connection in a strict environment', function () {
    currentTest()->bootWith(['runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false], 'production');

    [$thrown, $ran] = sessionAttempt();

    // testing_readonly is SQLite: the frame got past the strict check to the driver check.
    expect($thrown)->toBeInstanceOf(UnsupportedDriver::class)
        ->and($ran)->toBeFalse();
});
