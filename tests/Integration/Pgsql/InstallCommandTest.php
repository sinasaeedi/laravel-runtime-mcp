<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Console\InstallCommand;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\InstallOutput;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 7.1, 14 (01-28): the SQL step of runtime-mcp:install on PostgreSQL, with testing_pgsql as the default
 * connection and 01-11's fixture schema. IP6 is the end-to-end check of the printed instructions: run the printed SQL,
 * load the printed connection, and doctor passes in production (in a relaxed environment a failed privilege check
 * is only a warning with exit 0, spec 4.6). PostgreSQL roles are cluster-wide: a role that a test makes has a random
 * name and is dropped. Helpers start with "pgsqlInstall".
 */

beforeEach(function () {
    Databases::requiresPostgres();
    @unlink(config_path('runtime-mcp.php'));
});

afterEach(fn () => @unlink(config_path('runtime-mcp.php')));

/** @param  array<string, mixed>  $config */
function pgsqlInstallBoot(array $config = [], string $environment = 'production', bool $schema = true): void
{
    if ($schema) {
        ReadOnlyRole::schema('testing_pgsql');
    }

    currentTest()->bootWith(['database.default' => 'testing_pgsql', ...$config], $environment);
}

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function pgsqlInstallRun(array $options = []): array
{
    $code = Artisan::call(InstallCommand::NAME, ['--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

/** A random role name that the test drops at the end (DROP OWNED BY revokes its grants). */
function pgsqlInstallRole(): string
{
    $role = 'runtime_mcp_ro_'.bin2hex(random_bytes(4));

    currentTest()->beforeApplicationDestroyed(function () use ($role): void {
        DB::purge(InstallCommand::CONNECTION);
        $db = DB::connection('testing_pgsql');

        if ($db->selectOne('select 1 from pg_roles where rolname = ?', [$role]) !== null) {
            $quoted = RoleSqlGenerator::quotePostgres($role);
            $db->getPdo()->exec("DROP OWNED BY {$quoted}");
            $db->getPdo()->exec("DROP ROLE {$quoted}");
        }
    });

    return $role;
}

it('IP1 prints exactly the generator SQL for the default connection, the pgsql connection and the env lines', function () {
    pgsqlInstallBoot();

    [$code, $output] = pgsqlInstallRun();
    $expected = app(RoleSqlGenerator::class)->forDriver('pgsql', SchemaSnapshot::read(DB::connection('testing_pgsql')));

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toBe($expected)
        ->and(InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END))->toBe(
            InstallCommand::snippet('pgsql', RoleSqlGenerator::DEFAULT_ROLE, (array) config('database.connections.testing_pgsql')),
        )
        ->and(InstallOutput::between($output, InstallCommand::ENV_BEGIN, InstallCommand::ENV_END))->toBe([
            'RUNTIME_MCP_DB_CONNECTION=runtime_mcp',
            'RUNTIME_MCP_DB_USERNAME=runtime_mcp_ro',
            '# The password that replaces CHANGE_ME in the SQL above:',
            'RUNTIME_MCP_DB_PASSWORD=',
        ])
        ->and($output)->toContain("    'search_path' => 'public',")
        ->and($output)->toContain('1. '.InstallCommand::NEXT_SQL)
        ->and($output)->toContain('2. '.InstallCommand::NEXT_CONNECTION)
        ->and(str_contains($output, InstallCommand::PGSQL_HOST))->toBeFalse()
        ->and(str_contains($output, InstallCommand::NO_TABLES))->toBeFalse()
        ->and(str_contains($output, InstallCommand::DB_URL))->toBeFalse()
        ->and(str_contains($output, InstallCommand::OPTIONS))->toBeFalse()
        ->and(str_contains($output, InstallCommand::READ_WRITE))->toBeFalse()
        ->and(str_contains($output, InstallCommand::POOLED))->toBeFalse();
});

it('IP2 refuses a bad --role with the generator message, no SQL and no snippet, runs the later steps and exits 1', function (string $role) {
    pgsqlInstallBoot();

    [$code, $output] = pgsqlInstallRun(['--role' => $role, '--key-name' => 'sina']);

    expect($code)->toBe(1)
        ->and($output)->toContain('The role name must match /^[A-Za-z_][A-Za-z0-9_]{0,31}$/')
        ->and(str_contains($output, InstallCommand::SQL_BEGIN))->toBeFalse()
        ->and(str_contains($output, InstallCommand::CONNECTION_BEGIN))->toBeFalse()
        ->and(str_contains($output, InstallCommand::ENV_BEGIN))->toBeFalse()
        ->and($output)->toContain('Key for "sina"')
        ->and($output)->toContain('1. '.InstallCommand::NEXT_FIX_ROLE);
})->with([
    'a space' => ['bad name'],
    '33 characters' => [str_repeat('r', 33)],
]);

it('IP3 prints the SQL and a note for any --host, which PostgreSQL does not use', function (string $host) {
    pgsqlInstallBoot();

    [$code, $output] = pgsqlInstallRun(['--host' => $host]);

    expect($code)->toBe(0)
        ->and($output)->toContain(InstallCommand::PGSQL_HOST)
        ->and(InstallOutput::sql($output))->toContain('CREATE ROLE "runtime_mcp_ro" LOGIN')
        ->and(str_contains(InstallOutput::sql($output), $host))->toBeFalse();
})->with([
    'a range' => ['10.0.%'],
    'a quote' => ["x'"],
]);

it('IP4 warns before the SQL when the default connection has no tables yet', function () {
    $schema = Schema::connection('testing_pgsql');
    $schema->dropAllViews();
    $schema->dropAllTables();
    pgsqlInstallBoot(schema: false);

    [$code, $output] = pgsqlInstallRun();
    $warning = strpos($output, InstallCommand::NO_TABLES);
    $sql = strpos($output, InstallCommand::SQL_BEGIN);

    expect($code)->toBe(0)
        ->and($warning)->toBeInt()->and($sql)->toBeInt()
        ->and($warning < $sql)->toBeTrue()
        ->and(InstallOutput::sql($output))->toContain('CREATE ROLE "runtime_mcp_ro" LOGIN')
        ->and(InstallOutput::sql($output))->not->toContain('GRANT SELECT');
});

it('IP5 names the --role in the SQL, the connection and the env lines', function () {
    pgsqlInstallBoot();

    [$code, $output] = pgsqlInstallRun(['--role' => 'support_ro']);

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toContain('CREATE ROLE "support_ro" LOGIN')
        ->and(InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END))
        ->toContain("    'username' => env('RUNTIME_MCP_DB_USERNAME', 'support_ro'),")
        ->and(InstallOutput::between($output, InstallCommand::ENV_BEGIN, InstallCommand::ENV_END))
        ->toContain('RUNTIME_MCP_DB_USERNAME=support_ro');
});

it('IP6 gives a working read-only connection: the printed SQL and connection pass doctor in production', function () {
    pgsqlInstallBoot();
    $role = pgsqlInstallRole();
    $password = bin2hex(random_bytes(16));

    [$code, $output] = pgsqlInstallRun(['--role' => $role]);
    expect($code)->toBe(0);

    InstallOutput::runSql(DB::connection('testing_pgsql'), InstallOutput::sql($output), $password);

    $place = InstallOutput::place('testing_pgsql');
    $connection = InstallOutput::connection($output, [
        'DB_HOST' => $place['host'],
        'DB_PORT' => $place['port'],
        'DB_DATABASE' => $place['database'],
        'RUNTIME_MCP_DB_PASSWORD' => $password,
    ]);

    expect($connection['username'])->toBe($role)
        ->and($connection['password'])->toBe($password);

    currentTest()->bootWith([
        'database.default' => 'testing_pgsql',
        'database.connections.'.InstallCommand::CONNECTION => $connection,
        'runtime-mcp.database.connection' => InstallCommand::CONNECTION,
    ], 'production');

    $doctor = Artisan::call(DoctorCommand::NAME);
    $report = Artisan::output();

    expect(DB::connection(InstallCommand::CONNECTION)->scalar('select current_user'))->toBe($role)
        ->and(DB::connection(InstallCommand::CONNECTION)->select('select id, email from users'))->toHaveCount(1)
        ->and($doctor)->toBe(0)
        ->and(substr_count($report, '✓ Read-only role'))->toBe(3);
});

it('IP7 prints a style tag in a table name as text', function () {
    pgsqlInstallBoot();
    Schema::connection('testing_pgsql')->create('<fg=red>paint</>', function (Blueprint $table): void {
        $table->id();
    });

    [$code, $output] = pgsqlInstallRun();

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toContain('GRANT SELECT ON TABLE "public"."<fg=red>paint</>" TO "runtime_mcp_ro";');
});

it('IP8 warns about DB_URL, PDO options and read and write hosts of the default connection', function () {
    $pgsql = InstallOutput::place('testing_pgsql');
    $url = sprintf('pgsql://%s:%s@%s:%s/%s', $pgsql['username'], $pgsql['password'], $pgsql['host'], $pgsql['port'], $pgsql['database']);
    pgsqlInstallBoot([
        'database.connections.testing_pgsql.url' => $url,
        'database.connections.testing_pgsql.options' => [PDO::ATTR_TIMEOUT => 5],
        'database.connections.testing_pgsql.read' => ['host' => [$pgsql['host']]],
        'database.connections.testing_pgsql.write' => ['host' => [$pgsql['host']]],
    ]);

    [$code, $output] = pgsqlInstallRun();
    $connection = implode("\n", InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END));

    expect($code)->toBe(0)
        ->and($output)->toContain(InstallCommand::DB_URL)
        ->and($output)->toContain(InstallCommand::OPTIONS)
        ->and($output)->toContain(InstallCommand::READ_WRITE)
        ->and(str_contains($connection, 'url'))->toBeFalse()
        ->and(str_contains($connection, 'options'))->toBeFalse()
        ->and(str_contains($connection, 'read'))->toBeFalse()
        ->and(str_contains($connection, $pgsql['password']))->toBeFalse();
});

it('IP9 warns when the default connection goes through a transaction pooler, and copies neither marker key', function (string $marker) {
    $pgsql = InstallOutput::place('testing_pgsql');
    $direct = ['host' => $pgsql['host'], 'port' => $pgsql['port'], 'password' => 'direct-secret'];
    // Laravel 13 reads a direct endpoint, or 'pooled' => true, as "the host is a transaction pooler". Here the direct
    // endpoint is the same server. 'pooled' alone makes Laravel 13 warn at connect (E_USER_WARNING): an app that
    // connects so has that warning off.
    pgsqlInstallBoot(match ($marker) {
        'direct' => ['database.connections.testing_pgsql.direct' => $direct],
        'pooled and direct' => ['database.connections.testing_pgsql.pooled' => true, 'database.connections.testing_pgsql.direct' => $direct],
        default => ['database.connections.testing_pgsql.pooled' => true],
    });
    $reporting = error_reporting();

    if ($marker === 'pooled only') {
        error_reporting($reporting & ~E_USER_WARNING);
    }

    try {
        [$code, $output] = pgsqlInstallRun();
    } finally {
        error_reporting($reporting);
    }

    $connection = implode("\n", InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END));

    expect($code)->toBe(0)
        ->and($output)->toContain(InstallCommand::POOLED)
        ->and(str_contains($connection, 'pooled'))->toBeFalse()
        ->and(str_contains($connection, 'direct'))->toBeFalse()
        ->and(str_contains($output, 'direct-secret'))->toBeFalse();
})->with(['direct' => ['direct'], 'pooled and direct' => ['pooled and direct'], 'pooled only' => ['pooled only']]);

it('IP10 copies the search path and sslmode that the connection uses: from a DB_URL query, or the old schema key', function (string $source) {
    $pgsql = InstallOutput::place('testing_pgsql');
    $url = sprintf('pgsql://%s:%s@%s:%s/%s?sslmode=disable&search_path=rmcp_install_ip10', $pgsql['username'], $pgsql['password'], $pgsql['host'], $pgsql['port'], $pgsql['database']);
    pgsqlInstallBoot($source === 'url'
        ? ['database.connections.testing_pgsql.url' => $url]
        : ['database.connections.testing_pgsql.search_path' => null, 'database.connections.testing_pgsql.schema' => 'rmcp_install_ip10']);
    $db = DB::connection('testing_pgsql');
    $db->statement('create schema if not exists rmcp_install_ip10');
    $db->statement('create table if not exists rmcp_install_ip10.notes (id int)');

    try {
        [$code, $output] = pgsqlInstallRun();
    } finally {
        $db->statement('drop schema if exists rmcp_install_ip10 cascade');
    }

    $connection = InstallOutput::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END);

    expect($code)->toBe(0)
        ->and(InstallOutput::sql($output))->toContain('GRANT SELECT ON TABLE "rmcp_install_ip10"."notes" TO "runtime_mcp_ro";')
        ->and($connection)->toContain("    'search_path' => 'rmcp_install_ip10',")
        ->and($connection)->toContain($source === 'url' ? "    'sslmode' => 'disable'," : "    'sslmode' => 'prefer',")
        ->and(str_contains(implode("\n", $connection), 'url'))->toBeFalse()
        ->and(str_contains(implode("\n", $connection), $pgsql['password']))->toBeFalse();
})->with(['a DB_URL query' => ['url'], 'the old schema key' => ['schema']]);
