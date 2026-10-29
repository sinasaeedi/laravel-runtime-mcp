<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyTransactionConnections;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\StatementTimeout;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;

/* Spec 4.5, 5.9, 7.3, 01-16 Part D: the doctor check for read_only_transactions.connections (01-12's API). */

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}> [status, message, fix]
 */
function readOnlyConnectionsResults(array $config = []): array
{
    currentTest()->bootWith($config);

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        app(ReadOnlyTransactionConnections::class)->run(),
    );
}

it('RD1 passes with the defaults and counts the connections in use', function (mixed $listed, string $message) {
    expect(readOnlyConnectionsResults(['runtime-mcp.read_only_transactions.connections' => $listed]))->toBe([['pass', $message, null]]);
})->with([
    'null' => [null, 'Read tools run in a read-only transaction on 1 connection.'],
    'an empty list' => [[], 'Read tools run in a read-only transaction on 1 connection.'],
    'the default again' => [['testing'], 'Read tools run in a read-only transaction on 1 connection.'],
    'one more' => [['testing_readonly'], 'Read tools run in a read-only transaction on 2 connections.'],
    'PostgreSQL and MySQL' => [['testing_pgsql', 'testing_mysql'], 'Read tools run in a read-only transaction on 3 connections.'],
]);

it('RD2 fails for a value that is not a list of names', function (mixed $listed) {
    expect(readOnlyConnectionsResults(['runtime-mcp.read_only_transactions.connections' => $listed]))->toBe([[
        'fail',
        'read_only_transactions.connections is not a list of connection names, so every tool call fails.',
        ReadOnlyTransactionConnections::FIX,
    ]]);
})->with(['a string' => ['testing_readonly'], 'a map' => [['x' => 'testing_readonly']], 'a number' => [5]]);

it('RD3 fails once per bad entry, by its 1-based position', function () {
    expect(readOnlyConnectionsResults(['runtime-mcp.read_only_transactions.connections' => ['testing_readonly', '', 'nope', 7]]))->toBe([
        ['fail', 'Entry 2 of read_only_transactions.connections is not a connection name, so every tool call fails.', ReadOnlyTransactionConnections::FIX],
        ['fail', 'Entry 3 of read_only_transactions.connections (connection `nope`) is not in config/database.php, so every tool call fails.', ReadOnlyTransactionConnections::FIX],
        ['fail', 'Entry 4 of read_only_transactions.connections is not a connection name, so every tool call fails.', ReadOnlyTransactionConnections::FIX],
    ]);
});

it('RD4 never prints a name that does not look like one', function () {
    $key = 'rmcp_'.str_repeat('k', 43).'!';
    $results = readOnlyConnectionsResults(['runtime-mcp.read_only_transactions.connections' => [$key]]);

    expect($results[0][1])->toBe('Entry 1 of read_only_transactions.connections (a listed connection) is not in config/database.php, so every tool call fails.')
        ->and(str_contains($results[0][1], 'rmcp_'))->toBeFalse();
});

it('RD5 fails for a connection whose driver has no read-only transaction, also one set by a url', function (array $settings, string $shown) {
    expect(readOnlyConnectionsResults([
        'database.connections.ro_other' => $settings,
        'runtime-mcp.read_only_transactions.connections' => ['ro_other'],
    ]))->toBe([[
        'fail',
        "Connection `ro_other` uses {$shown}, which has no read-only transaction, so every tool call fails.",
        ReadOnlyTransactionConnections::FIX,
    ]]);
})->with([
    'sqlsrv' => [['driver' => 'sqlsrv', 'host' => 'db'], 'the `sqlsrv` driver'],
    'a url' => [['url' => 'sqlsrv://user:secret@db/app'], 'the `sqlsrv` driver'],
    'no driver' => [['host' => 'db'], 'a driver'],
]);

it('RD6 fails when database.default names no connection, and checks the default driver too', function () {
    expect(readOnlyConnectionsResults(['database.default' => 'missing']))->toBe([[
        'fail', 'database.default does not name a connection in config/database.php.', ReadOnlyTransactionConnections::FIX,
    ]])
        ->and(readOnlyConnectionsResults([
            'database.default' => 'ro_other',
            'database.connections.ro_other' => ['driver' => 'sqlsrv'],
        ])[0][1])->toBe('Connection `ro_other` uses the `sqlsrv` driver, which has no read-only transaction, so every tool call fails.');
});

it('RD7 is a plain Fail, not a privilege check, and comes right after StatementTimeout', function () {
    $result = (function (): CheckResult {
        currentTest()->bootWith(['runtime-mcp.read_only_transactions.connections' => 'x'], 'local');

        return app(ReadOnlyTransactionConnections::class)->run()[0];
    })();

    expect($result->privilege)->toBeFalse()
        ->and(array_search(ReadOnlyTransactionConnections::class, Doctor::CHECKS, true))->toBe(array_search(StatementTimeout::class, Doctor::CHECKS, true) + 1)
        ->and(app(ReadOnlyTransactionConnections::class)->title())->toBe('Read-only transactions');
});
