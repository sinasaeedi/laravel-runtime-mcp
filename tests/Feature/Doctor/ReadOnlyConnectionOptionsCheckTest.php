<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyConnectionOptions;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyTransactionConnections;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/* Spec 5.2, 5.13, 7.3, 01-14 re-review: the options of the read-only connection (01-12's API). Config only. */

const READ_ONLY_OPTIONS_PERSISTENT = 'The read-only connection is persistent (PDO::ATTR_PERSISTENT), so every package query on it is refused: a disconnect does not end a persistent session.';

const READ_ONLY_OPTIONS_SPLIT = 'The read-only connection has read and write hosts. Every package query runs in a transaction, so it uses the write host.';

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string}> [status, message]
 */
function readOnlyOptionsResults(array $config = [], string $environment = 'testing'): array
{
    currentTest()->bootWith($config, $environment);

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message],
        app(ReadOnlyConnectionOptions::class)->run(),
    );
}

function readOnlyOptionsKey(string $key): string
{
    return 'database.connections.'.TestCase::READ_ONLY_CONNECTION.'.'.$key;
}

it('RC1 passes when no active plugin reads the database', function () {
    expect(readOnlyOptionsResults(['runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false, readOnlyOptionsKey('options') => [PDO::ATTR_PERSISTENT => true]]))
        ->toBe([['pass', 'Not needed: no active plugin reads the database.']]);
});

it('RC2 passes for a connection that is not persistent and has one host', function (mixed $options) {
    expect(readOnlyOptionsResults([readOnlyOptionsKey('options') => $options]))
        ->toBe([['pass', 'The read-only connection is not persistent and has one host.']]);
})->with(['no options' => [null], 'other options' => [[PDO::ATTR_TIMEOUT => 5]], 'persistent off' => [[PDO::ATTR_PERSISTENT => false]]]);

it('RC3 fails for a persistent connection in every environment, and is not a privilege check', function (mixed $persistent, string $environment) {
    currentTest()->bootWith([readOnlyOptionsKey('options') => [PDO::ATTR_PERSISTENT => $persistent]], $environment);
    $results = app(ReadOnlyConnectionOptions::class)->run();

    expect(array_map(fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix, $result->privilege], $results))
        ->toBe([['fail', READ_ONLY_OPTIONS_PERSISTENT, 'Remove PDO::ATTR_PERSISTENT from its options in config/database.php.', false]]);
})->with([
    'true, strict' => [true, 'production'],
    'true, relaxed' => [true, 'testing'],
    'a persistent id (PDO accepts a string)' => ['pool-1', 'production'],
]);

it('RC4 warns for read and write hosts', function (string $key) {
    expect(readOnlyOptionsResults([readOnlyOptionsKey($key) => ['host' => ['replica.invalid']]]))->toBe([['warn', READ_ONLY_OPTIONS_SPLIT]]);
})->with(['read' => ['read'], 'write' => ['write']]);

it('RC5 gives both lines for a persistent connection with read and write hosts', function () {
    expect(readOnlyOptionsResults([readOnlyOptionsKey('options') => [PDO::ATTR_PERSISTENT => true], readOnlyOptionsKey('read') => ['host' => ['replica.invalid']]]))
        ->toBe([['fail', READ_ONLY_OPTIONS_PERSISTENT], ['warn', READ_ONLY_OPTIONS_SPLIT]]);
});

it('RC6 checks the default connection when database.connection is unset, as ReadOnlySession does in a relaxed environment', function () {
    expect(readOnlyOptionsResults(['runtime-mcp.database.connection' => null, 'database.connections.testing.options' => [PDO::ATTR_PERSISTENT => true]]))
        ->toBe([['fail', READ_ONLY_OPTIONS_PERSISTENT]]);
});

it('RC7 does not check a connection that is not in config/database.php, and never prints its name', function () {
    $results = readOnlyOptionsResults(['runtime-mcp.database.connection' => 'rmcp_missing']);

    expect($results)->toBe([['warn', 'Not checked until RUNTIME_MCP_DB_CONNECTION names a connection in config/database.php (see Boot rules and Read-only role).']])
        ->and(str_contains($results[0][1], 'rmcp_missing'))->toBeFalse();
});

it('RC8 comes right after ReadOnlyTransactionConnections, with its title', function () {
    currentTest()->bootWith();

    expect(array_search(ReadOnlyConnectionOptions::class, Doctor::CHECKS, true))->toBe(array_search(ReadOnlyTransactionConnections::class, Doctor::CHECKS, true) + 1)
        ->and(app(ReadOnlyConnectionOptions::class)->title())->toBe('Read-only connection');
});
