<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Console\InstallCommand;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\InstallOutput;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 7.1, 14 (01-28): the SQL step of runtime-mcp:install on MySQL and MariaDB (testing_mysql is MySQL 8.4,
 * MariaDB 11.4 or MariaDB 10.11 in CI; its driver is mysql or mariadb), with testing_mysql as the default connection
 * and 01-11's fixture schema. IM3 is the end-to-end check, in production (spec 4.6). A user that a test makes has a
 * random name and is dropped. Helpers start with "mysqlInstall".
 */

beforeEach(function () {
    Databases::requiresMysql();
    @unlink(config_path('runtime-mcp.php'));
});

afterEach(fn () => @unlink(config_path('runtime-mcp.php')));

function mysqlInstallBoot(): void
{
    ReadOnlyRole::schema('testing_mysql');
    currentTest()->bootWith(['database.default' => 'testing_mysql'], 'production');
}

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function mysqlInstallRun(array $options = []): array
{
    $code = Artisan::call(InstallCommand::NAME, ['--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

function mysqlInstallDriver(): string
{
    $driver = config('database.connections.testing_mysql.driver');

    return is_string($driver) ? $driver : '';
}

it('IM1 prints exactly the generator SQL and a connection for the mysql or mariadb driver', function () {
    mysqlInstallBoot();

    [$code, $output] = mysqlInstallRun();
    $expected = app(RoleSqlGenerator::class)->forDriver(mysqlInstallDriver(), SchemaSnapshot::read(DB::connection('testing_mysql')));
    $connection = InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END);

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toBe($expected)
        ->and($connection)->toBe(InstallCommand::snippet(mysqlInstallDriver(), RoleSqlGenerator::DEFAULT_ROLE, (array) config('database.connections.testing_mysql')))
        ->and($connection)->toContain("    'driver' => '".mysqlInstallDriver()."',")
        ->and($connection)->toContain("    'port' => env('DB_PORT', '3306'),")
        ->and($connection)->toContain("    'collation' => 'utf8mb4_unicode_ci',")
        ->and(str_contains(implode("\n", $connection), 'search_path'))->toBeFalse()
        ->and(str_contains($output, InstallCommand::PGSQL_HOST))->toBeFalse();
});

it('IM2 uses --host in the user name, and refuses a host with a quote', function () {
    mysqlInstallBoot();

    [$code, $output] = mysqlInstallRun(['--host' => '10.0.%']);
    [$badCode, $bad] = mysqlInstallRun(['--host' => "x'"]);

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toContain("CREATE USER 'runtime_mcp_ro'@'10.0.%' IDENTIFIED BY 'CHANGE_ME';")
        ->and(InstallOutput::sql($output))->not->toContain("Host '%'")
        ->and(str_contains($output, InstallCommand::PGSQL_HOST))->toBeFalse()
        ->and($badCode)->toBe(1)
        ->and($bad)->toContain('The MySQL host must match /^[A-Za-z0-9.%_:-]{1,255}$/, for example % or 10.0.%.')
        ->and(str_contains($bad, InstallCommand::SQL_BEGIN))->toBeFalse()
        ->and(str_contains($bad, InstallCommand::CONNECTION_BEGIN))->toBeFalse()
        ->and($bad)->toContain('1. '.InstallCommand::NEXT_FIX_ROLE);
});

it('IM3 gives a working read-only connection: the printed SQL and connection pass doctor in production', function () {
    mysqlInstallBoot();
    $role = 'runtime_mcp_ro_'.bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(16));

    currentTest()->beforeApplicationDestroyed(function () use ($role): void {
        DB::purge(InstallCommand::CONNECTION);
        DB::connection('testing_mysql')->getPdo()->exec("DROP USER IF EXISTS '{$role}'@'%'");
    });

    [$code, $output] = mysqlInstallRun(['--role' => $role]);
    expect($code)->toBe(0);

    InstallOutput::runSql(DB::connection('testing_mysql'), InstallOutput::sql($output), $password);

    $place = InstallOutput::place('testing_mysql');
    $connection = InstallOutput::connection($output, [
        'DB_HOST' => $place['host'],
        'DB_PORT' => $place['port'],
        'DB_DATABASE' => $place['database'],
        'RUNTIME_MCP_DB_PASSWORD' => $password,
    ]);

    expect($connection['username'])->toBe($role)
        ->and($connection['driver'])->toBe(mysqlInstallDriver());

    currentTest()->bootWith([
        'database.default' => 'testing_mysql',
        'database.connections.'.InstallCommand::CONNECTION => $connection,
        'runtime-mcp.database.connection' => InstallCommand::CONNECTION,
    ], 'production');

    $doctor = Artisan::call(DoctorCommand::NAME);
    $report = Artisan::output();

    expect(DB::connection(InstallCommand::CONNECTION)->select('select id, email from users'))->toHaveCount(1)
        ->and($doctor)->toBe(0)
        ->and(substr_count($report, '✓ Read-only role'))->toBe(3);
});
