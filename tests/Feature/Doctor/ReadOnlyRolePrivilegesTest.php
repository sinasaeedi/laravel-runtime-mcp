<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\MaskLists;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyRolePrivileges;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;

/*
 * 01-13 Part A: the cases of the Read-only role check that need no database server. The test
 * read-only connection (testing_readonly) is SQLite, so with the default plugins the check fails on
 * the driver. The PostgreSQL, MySQL and MariaDB cases are in tests/Integration.
 */

/** @return list<array{string, string, string|null, bool}> [status, message, fix, privilege] */
function readOnlyRoleResults(): array
{
    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix, $result->privilege],
        app()->make(ReadOnlyRolePrivileges::class)->run(),
    );
}

it('is the Read-only role check, right after Mask lists', function () {
    currentTest()->bootWith();

    expect(app()->make(ReadOnlyRolePrivileges::class)->title())->toBe('Read-only role')
        ->and(array_search(ReadOnlyRolePrivileges::class, Doctor::CHECKS, true))->toBe(array_search(MaskLists::class, Doctor::CHECKS, true) + 1);
});

it('passes with Not needed when no active plugin reads the database', function () {
    currentTest()->bootWith(['runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false]);

    expect(readOnlyRoleResults())->toBe([['pass', 'Not needed: no active plugin reads the database.', null, false]]);
});

it('runs when only one of the database and models plugins is on', function (string $off) {
    currentTest()->bootWith(["runtime-mcp.plugins.{$off}" => false]);

    expect(readOnlyRoleResults()[0][0])->toBe('fail');
})->with(['database', 'models']);

it('warns once and defers to the boot rule when database.connection is unset or the default', function (mixed $connection) {
    currentTest()->bootWith(['runtime-mcp.database.connection' => $connection]);

    expect(readOnlyRoleResults())->toBe([['warn', 'Not checked until RUNTIME_MCP_DB_CONNECTION names a separate connection (see Boot rules).', null, false]]);
})->with([
    'null' => [null],
    'blank' => ['  '],
    'not a string' => [['x']],
    'the default connection' => ['testing'],
]);

it('fails as a privilege check when the name is not in config/database.php, and never prints it', function () {
    currentTest()->bootWith(['runtime-mcp.database.connection' => 'reporting_ro_missing']);

    $results = readOnlyRoleResults();

    expect($results)->toBe([[
        'fail',
        'RUNTIME_MCP_DB_CONNECTION names a connection that is not in config/database.php.',
        'Add the read-only connection to config/database.php, or fix RUNTIME_MCP_DB_CONNECTION.',
        true,
    ]])
        ->and(str_contains(json_encode($results, JSON_THROW_ON_ERROR), 'reporting_ro_missing'))->toBeFalse();
});

it('finds the connection config as Laravel does: a dotted name is a nested key, and a url sets the driver', function () {
    currentTest()->bootWith([
        'database.connections.group' => ['inner' => ['driver' => 'sqlite', 'database' => ':memory:']],
        // The host is a socket directory that does not exist: the connect fails at once (a closed TCP port can time out).
        'database.connections.by_url' => ['url' => 'pgsql://url_user:url-password@%2Fnonexistent%2Frmcp:1/url_db'],
        'runtime-mcp.database.connection' => 'group.inner',
    ]);
    $dotted = readOnlyRoleResults()[0][1];
    config()->set('runtime-mcp.database.connection', 'by_url');
    $url = readOnlyRoleResults()[0][1];

    expect($dotted)->toContain('uses the `sqlite` driver')
        ->and($url)->toBe('Cannot connect with the read-only connection: PDOException, SQLSTATE 08006.');
});

it('fails as a privilege check for a driver without grants, such as SQLite', function () {
    currentTest()->bootWith();

    expect(readOnlyRoleResults())->toBe([[
        'fail',
        'Cannot check the read-only role: the read-only connection uses the `sqlite` driver. Only PostgreSQL, MySQL and MariaDB have the grants that hide a column.',
        'Point RUNTIME_MCP_DB_CONNECTION at a PostgreSQL, MySQL or MariaDB connection.',
        true,
    ]]);
});

it('prints the driver only when it has the shape of a name', function () {
    currentTest()->bootWith([
        'database.connections.odd' => ['driver' => 'Bad Driver!'],
        'runtime-mcp.database.connection' => 'odd',
    ]);

    expect(readOnlyRoleResults()[0][1])->toBe('Cannot check the read-only role: the read-only connection uses a driver. Only PostgreSQL, MySQL and MariaDB have the grants that hide a column.');
});

it('reports a connection failure with the class and SQLSTATE only, never the host, user or password', function (string $driver, string $state) {
    currentTest()->bootWith([
        'database.connections.unreachable' => [
            // A socket that does not exist: the connect fails at once (a closed TCP port can time out).
            'driver' => $driver, 'host' => '/nonexistent/rmcp', 'unix_socket' => '/nonexistent/rmcp.sock', 'port' => '1', 'database' => 'secret_db_name',
            'username' => 'secret_user_name', 'password' => 'secret-password-value', 'prefix' => '',
        ],
        'runtime-mcp.database.connection' => 'unreachable',
    ]);

    $results = readOnlyRoleResults();
    $text = json_encode($results, JSON_THROW_ON_ERROR);

    expect($results)->toHaveCount(1)
        ->and($results[0][0])->toBe('fail')
        ->and($results[0][1])->toBe("Cannot connect with the read-only connection: PDOException, SQLSTATE {$state}.")
        ->and($results[0][3])->toBeTrue();

    foreach (['/nonexistent', 'secret_db_name', 'secret_user_name', 'secret-password-value'] as $secret) {
        expect(str_contains($text, $secret))->toBeFalse("leaked {$secret}");
    }
})->with([
    'pgsql' => ['pgsql', '08006'],
    'mysql' => ['mysql', 'HY000'],
]);

it('prints the driver failure as a warning and exits 0 in a relaxed environment, and exits 1 in a strict one', function (string $environment, int $code, string $glyph) {
    currentTest()->bootWith([], $environment);

    $exit = Artisan::call(DoctorCommand::NAME);

    expect($exit)->toBe($code)
        ->and(Artisan::output())->toContain("{$glyph} Read-only role");
})->with([
    'relaxed' => ['local', 0, '!'],
    'strict' => ['production', 1, '✗'],
]);
