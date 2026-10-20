<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyRolePrivileges;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 11.2 "Doctor", 01-13: the three privilege checks against a real MySQL or MariaDB user
 * (testing_mysql is MySQL 8.4, MariaDB 11.4 or MariaDB 10.11 in CI). The user is 01-11's; each test
 * then changes one grant with the privileged connection (root). A role or a PUBLIC grant that a test
 * makes is removed by that test: grants outlive a dropped table.
 */

beforeEach(fn () => Databases::requiresMysql());

/**
 * @param  array<string, mixed>  $config
 * @param  (Closure(string): void)|null  $extend
 */
function mysqlPrivilegesRole(string $environment = 'production', array $config = [], ?Closure $extend = null): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_mysql', ['database.default' => 'testing_mysql', ...$config], $environment, $extend);
}

/** Runs SQL as the privileged user (root in CI). */
function mysqlPrivilegesGrant(string $sql): void
{
    DB::connection('testing_mysql')->getPdo()->exec($sql);
}

function mysqlPrivilegesIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/** The user as fix lines quote it: `name`@`%`. */
function mysqlPrivilegesUser(ReadOnlyRole $role): string
{
    return "`{$role->name()}`@`%`";
}

/** `runtime_mcp`.`orders`: the database is the CI database, by name. */
function mysqlPrivilegesTable(string $table): string
{
    return '`'.DB::connection('testing_mysql')->getDatabaseName()."`.`{$table}`";
}

/** A random role name, dropped when the test ends. */
function mysqlPrivilegesNewRole(string $prefix = 'rmcp_r_'): string
{
    $name = $prefix.bin2hex(random_bytes(4));
    currentTest()->beforeApplicationDestroyed(fn () => mysqlPrivilegesGrant("DROP ROLE IF EXISTS `{$name}`"));
    mysqlPrivilegesGrant("CREATE ROLE `{$name}`");

    return $name;
}

/** @return list<CheckResult> write privileges, superuser rights, masked columns */
function mysqlPrivilegeResults(): array
{
    return app()->make(ReadOnlyRolePrivileges::class)->run();
}

/** @return array{int, string} */
function mysqlPrivilegesDoctor(): array
{
    $code = Artisan::call(DoctorCommand::NAME);

    return [$code, Artisan::output()];
}

const MYSQL_PRIVILEGES_AGAIN = "\n      Run doctor again after the fix: it shows what is left.";

it('passes the three checks for the user that 01-11 writes, and doctor exits 0', function () {
    mysqlPrivilegesRole();

    $results = mysqlPrivilegeResults();
    [$code, $output] = mysqlPrivilegesDoctor();

    expect(array_map(fn (CheckResult $result): array => [$result->status->value, $result->message], $results))->toBe([
        ['pass', 'No write privilege on any table.'],
        ['pass', 'No superuser rights.'],
        ['pass', 'No masked column and no denied table is readable.'],
    ])
        ->and($code)->toBe(0)
        ->and(substr_count($output, '✓ Read-only role'))->toBe(3);
});

it('check 1: fails for each write privilege on a table, with the REVOKE, and exits 1 in production', function (string $privilege) {
    $role = mysqlPrivilegesRole();
    $orders = mysqlPrivilegesTable('orders');
    mysqlPrivilegesGrant("GRANT {$privilege} ON {$orders} TO '{$role->name()}'@'%'");

    $write = mysqlPrivilegeResults()[0];
    [$code, $output] = mysqlPrivilegesDoctor();

    expect($write->status->value)->toBe('fail')
        ->and($write->privilege)->toBeTrue()
        ->and($write->message)->toBe("The read-only role can write or change the schema: {$privilege} on {$orders}.")
        ->and($write->fix)->toBe("REVOKE {$privilege} ON {$orders} FROM ".mysqlPrivilegesUser($role).';'.MYSQL_PRIVILEGES_AGAIN)
        ->and($code)->toBe(1)
        ->and($output)->toContain('✗ Read-only role');
})->with(['INSERT', 'UPDATE', 'DELETE', 'DROP']);

it('check 1 is an allow-list: a DDL privilege, PROCESS and GRANT OPTION fail, SHOW VIEW passes (Sina, Q2)', function (string $grant, string $item) {
    $role = mysqlPrivilegesRole();
    $database = '`'.DB::connection('testing_mysql')->getDatabaseName().'`';
    $grant = str_replace(['{db}', '{user}'], [$database, "'{$role->name()}'@'%'"], $grant);
    mysqlPrivilegesGrant($grant);

    $write = mysqlPrivilegeResults()[0];
    $item = str_replace('{db}', $database, $item);

    expect($item === '' ? $write->status->value : $write->message)
        ->toBe($item === '' ? 'pass' : "The read-only role can write or change the schema: {$item}.");
})->with([
    'ALTER' => ['GRANT ALTER ON {db}.`orders` TO {user}', 'ALTER on {db}.`orders`'],
    'CREATE' => ['GRANT CREATE ON {db}.* TO {user}', 'CREATE on {db}.*'],
    'INDEX' => ['GRANT INDEX ON {db}.`orders` TO {user}', 'INDEX on {db}.`orders`'],
    'PROCESS' => ['GRANT PROCESS ON *.* TO {user}', 'PROCESS on *.*'],
    'GRANT OPTION' => ['GRANT SELECT (`id`) ON {db}.`orders` TO {user} WITH GRANT OPTION', 'GRANT OPTION on {db}.`orders`'],
    'a column INSERT' => ['GRANT INSERT (`status`) ON {db}.`orders` TO {user}', 'INSERT (`status`) on {db}.`orders`'],
    'SHOW VIEW' => ['GRANT SHOW VIEW ON {db}.* TO {user}', ''],
]);

it('proves the allow-list: DDL runs inside a read-only transaction (it commits first)', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant('GRANT ALTER ON '.mysqlPrivilegesTable('orders')." TO '{$role->name()}'@'%'");

    $pdo = DB::connection(ReadOnlyRole::CONNECTION)->getPdo();
    $pdo->exec('START TRANSACTION READ ONLY');
    $pdo->exec('ALTER TABLE orders ADD COLUMN injected int');
    $pdo->exec('ROLLBACK');

    expect(DB::connection('testing_mysql')->getSchemaBuilder()->hasColumn('orders', 'injected'))->toBeTrue();
});

it('check 2: fails for FILE, SUPER and an ADMIN privilege on *.*', function (string $privilege) {
    $role = mysqlPrivilegesRole();
    $privilege = $privilege === 'ADMIN' ? (mysqlPrivilegesIsMaria() ? 'READ_ONLY ADMIN' : 'SYSTEM_VARIABLES_ADMIN') : $privilege;
    mysqlPrivilegesGrant("GRANT {$privilege} ON *.* TO '{$role->name()}'@'%'");

    [$write, $superuser] = mysqlPrivilegeResults();

    expect($superuser->message)->toBe("The read-only role has superuser rights: {$privilege} on *.*.")
        ->and($superuser->fix)->toBe("REVOKE {$privilege} ON *.* FROM ".mysqlPrivilegesUser($role).';'.MYSQL_PRIVILEGES_AGAIN)
        ->and($write->status->value)->toBe('pass');
})->with(['FILE', 'SUPER', 'ADMIN']);

it('check 3, drift: a table-level grant passes, then a migration adds api_token and doctor names it', function () {
    $role = mysqlPrivilegesRole();

    expect(mysqlPrivilegeResults()[2]->status->value)->toBe('pass');

    mysqlPrivilegesGrant('ALTER TABLE '.mysqlPrivilegesTable('orders').' ADD COLUMN api_token varchar(64)');
    $masked = mysqlPrivilegeResults()[2];
    $orders = mysqlPrivilegesTable('orders');
    $user = mysqlPrivilegesUser($role);

    expect($masked->status->value)->toBe('fail')
        ->and($masked->privilege)->toBeTrue()
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: orders.api_token.')
        ->and($masked->fix)->toBe(
            "REVOKE SELECT ON {$orders} FROM {$user}; "
            ."GRANT SELECT (`id`, `user_id`, `number`, `total`, `status`, `created_at`) ON {$orders} TO {$user};".MYSQL_PRIVILEGES_AGAIN
        );
});

it('check 3: fails for a column grant on a masked column, with a column REVOKE', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant('GRANT SELECT (`password`) ON '.mysqlPrivilegesTable('users')." TO '{$role->name()}'@'%'");

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: users.password.')
        ->and($masked->fix)->toStartWith('REVOKE SELECT (`password`) ON '.mysqlPrivilegesTable('users').' FROM '.mysqlPrivilegesUser($role).';');
});

it('check 3: fails for a readable denied table', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant('GRANT SELECT ON '.mysqlPrivilegesTable('sessions')." TO '{$role->name()}'@'%'");

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: sessions (denied table).')
        ->and($masked->fix)->toBe('REVOKE SELECT ON '.mysqlPrivilegesTable('sessions').' FROM '.mysqlPrivilegesUser($role).';'.MYSQL_PRIVILEGES_AGAIN);
});

it('check 3: a SELECT on the whole database makes every masked column and denied table readable', function () {
    $role = mysqlPrivilegesRole();
    $database = '`'.DB::connection('testing_mysql')->getDatabaseName().'`';
    mysqlPrivilegesGrant("GRANT SELECT ON {$database}.* TO '{$role->name()}'@'%'");
    mysqlPrivilegesGrant('GRANT SELECT (`password`) ON '.mysqlPrivilegesTable('users')." TO '{$role->name()}'@'%'");

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->message)->toContain('users.password, users.remember_token, users.api_token')
        ->and(substr_count($masked->message, 'users.password'))->toBe(1)
        ->and($masked->message)->toContain('sessions (denied table)')
        ->and($masked->fix)->toStartWith("REVOKE SELECT ON {$database}.* FROM ".mysqlPrivilegesUser($role).'; Then grant SELECT per table')
        ->and($masked->fix)->toContain('REVOKE SELECT (`password`) ON '.mysqlPrivilegesTable('users').' FROM '.mysqlPrivilegesUser($role).';');
});

it('check 3: reads a database grant as a LIKE pattern, as MySQL does', function (string $pattern) {
    $role = mysqlPrivilegesRole();
    $database = DB::connection('testing_mysql')->getDatabaseName();
    $name = $pattern === 'wildcard' ? substr($database, 0, 3).'%' : str_replace('_', '\\_', $database);
    mysqlPrivilegesGrant("GRANT SELECT ON `{$name}`.* TO '{$role->name()}'@'%'");

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->message)->toContain('users.password')
        ->and($masked->fix)->toStartWith("REVOKE SELECT ON `{$name}`.* FROM ".mysqlPrivilegesUser($role).';');
})->with([
    'a % wildcard' => ['wildcard'],
    'an escaped _' => ['escaped'],
]);

it('check 3: ALL PRIVILEGES on a table also reads every column', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant('GRANT ALL PRIVILEGES ON '.mysqlPrivilegesTable('users')." TO '{$role->name()}'@'%'");

    [$write, , $masked] = mysqlPrivilegeResults();

    expect($write->message)->toBe('The read-only role can write or change the schema: ALL PRIVILEGES on '.mysqlPrivilegesTable('users').'.')
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: users.password, users.remember_token, users.api_token.');
});

it('check 3: fails for a granted view that shows a masked column, and grants nothing back (Sina, Q3)', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant('GRANT SELECT ON '.mysqlPrivilegesTable('user_secrets')." TO '{$role->name()}'@'%'");

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: user_secrets.password.')
        ->and($masked->fix)->toBe('REVOKE SELECT ON '.mysqlPrivilegesTable('user_secrets').' FROM '.mysqlPrivilegesUser($role).';'.MYSQL_PRIVILEGES_AGAIN);
});

it('checks a role that the user was granted, set as default or not', function (bool $default) {
    $role = mysqlPrivilegesRole();
    $extra = mysqlPrivilegesNewRole();
    mysqlPrivilegesGrant('GRANT DELETE ON '.mysqlPrivilegesTable('orders')." TO '{$role->name()}'@'%'");
    mysqlPrivilegesGrant('GRANT INSERT ON '.mysqlPrivilegesTable('orders')." TO `{$extra}`");
    mysqlPrivilegesGrant('GRANT SELECT (`password`) ON '.mysqlPrivilegesTable('users')." TO `{$extra}`");
    mysqlPrivilegesGrant("GRANT `{$extra}` TO '{$role->name()}'@'%'");

    if ($default) {
        mysqlPrivilegesGrant(mysqlPrivilegesIsMaria()
            ? "SET DEFAULT ROLE `{$extra}` FOR '{$role->name()}'@'%'"
            : "SET DEFAULT ROLE `{$extra}` TO '{$role->name()}'@'%'");
    }

    [$write, , $masked] = mysqlPrivilegeResults();
    $written = mysqlPrivilegesIsMaria() ? "`{$extra}`" : "`{$extra}`@`%`";
    $orders = mysqlPrivilegesTable('orders');
    $user = mysqlPrivilegesUser($role);

    expect($write->message)->toBe("The read-only role can write or change the schema: DELETE on {$orders}, INSERT on {$orders} (through {$written}).")
        ->and($write->fix)->toBe("REVOKE DELETE ON {$orders} FROM {$user};\n      REVOKE {$written} FROM {$user};".MYSQL_PRIVILEGES_AGAIN)
        ->and($masked->message)->toBe("The read-only role can read masked columns or denied tables: users.password (through {$written}).")
        ->and($masked->fix)->toBe("REVOKE {$written} FROM {$user};".MYSQL_PRIVILEGES_AGAIN);
})->with(['the default role' => [true], 'a role that is not active' => [false]]);

it('on MariaDB, checks a role nested in a granted role', function () {
    if (! mysqlPrivilegesIsMaria()) {
        Assert::markTestSkipped('MySQL: a role in a role is covered by USING; this case is the MariaDB SET ROLE path.');
    }

    $role = mysqlPrivilegesRole();
    $outer = mysqlPrivilegesNewRole('rmcp_outer_');
    $inner = mysqlPrivilegesNewRole('rmcp_inner_');
    mysqlPrivilegesGrant('GRANT INSERT ON '.mysqlPrivilegesTable('orders')." TO `{$inner}`");
    mysqlPrivilegesGrant("GRANT `{$inner}` TO `{$outer}`");
    mysqlPrivilegesGrant("GRANT `{$outer}` TO '{$role->name()}'@'%'");

    $write = mysqlPrivilegeResults()[0];

    expect($write->message)->toBe('The read-only role can write or change the schema: INSERT on '.mysqlPrivilegesTable('orders')." (through `{$outer}`).")
        ->and($write->fix)->toStartWith("REVOKE `{$outer}` FROM ".mysqlPrivilegesUser($role).';');
});

it('on MariaDB, names a privilege that PUBLIC holds, with a REVOKE … FROM PUBLIC', function () {
    if (! mysqlPrivilegesIsMaria()) {
        Assert::markTestSkipped('MySQL 8.4 has no PUBLIC role.');
    }

    mysqlPrivilegesRole();
    $orders = mysqlPrivilegesTable('orders');
    currentTest()->beforeApplicationDestroyed(fn () => mysqlPrivilegesGrant("REVOKE INSERT ON {$orders} FROM PUBLIC"));
    mysqlPrivilegesGrant("GRANT INSERT ON {$orders} TO PUBLIC");

    $write = mysqlPrivilegeResults()[0];

    expect($write->message)->toBe("The read-only role can write or change the schema: INSERT on {$orders} (through PUBLIC).")
        ->and($write->fix)->toStartWith("REVOKE INSERT ON {$orders} FROM PUBLIC;");
});

it('never prints the password hash that MariaDB SHOW GRANTS holds', function () {
    $role = mysqlPrivilegesRole();
    mysqlPrivilegesGrant("GRANT FILE ON *.* TO '{$role->name()}'@'%'");

    [, $output] = mysqlPrivilegesDoctor();

    expect(str_contains($output, 'IDENTIFIED'))->toBeFalse()
        ->and(preg_match('/\*[0-9A-F]{40}/', $output))->toBe(0)
        ->and($output)->toContain('FILE on *.*');
});

it('only warns, exit 0, for a failed check in a relaxed environment (spec 4.6)', function () {
    $role = mysqlPrivilegesRole('local');
    mysqlPrivilegesGrant('GRANT INSERT ON '.mysqlPrivilegesTable('orders')." TO '{$role->name()}'@'%'");

    [$code, $output] = mysqlPrivilegesDoctor();

    expect($code)->toBe(0)
        ->and($output)->toContain('! Read-only role');
});

it('skips check 3 with a warning when a mask list is not an array, and still runs checks 1 and 2', function () {
    mysqlPrivilegesRole(config: ['runtime-mcp.database.denied_tables' => 'sessions']);

    expect(array_map(fn (CheckResult $result): string => $result->status->value, mysqlPrivilegeResults()))->toBe(['pass', 'pass', 'warn']);
});

it('fails check 3 when the default connection is not the same database', function (array $config) {
    /** @var array<string, mixed> $config */
    mysqlPrivilegesRole(config: $config);

    $masked = mysqlPrivilegeResults()[2];

    expect($masked->status->value)->toBe('fail')
        ->and($masked->privilege)->toBeTrue()
        ->and($masked->message)->toBe('Masked columns not checked: the read-only connection and the default connection name different databases, so the masked columns cannot be matched.');
})->with([
    'another database name' => [['database.connections.testing_mysql.database' => 'mysql']],
    'a SQLite default connection' => [['database.default' => 'testing']],
    'a PostgreSQL default connection' => [['database.default' => 'testing_pgsql']],
]);

it('turns a failure inside one check into its own privilege Fail with the class only, and runs the others', function () {
    $broken = config('database.connections.testing_mysql');
    assert(is_array($broken));
    mysqlPrivilegesRole(config: ['database.default' => 'broken', 'database.connections.broken' => [...$broken, 'unix_socket' => '/nonexistent/rmcp.sock']]);

    $results = mysqlPrivilegeResults();

    expect(array_map(fn (CheckResult $result): string => $result->status->value, $results))->toBe(['pass', 'pass', 'fail'])
        ->and($results[2]->message)->toBe('Masked columns could not be checked: Illuminate\Database\QueryException.')
        ->and($results[2]->privilege)->toBeTrue();
});

it('reports a wrong password once, with the class and SQLSTATE only', function () {
    $role = mysqlPrivilegesRole();
    config()->set('database.connections.'.ReadOnlyRole::CONNECTION.'.password', 'wrong-password');
    DB::purge(ReadOnlyRole::CONNECTION);

    $results = mysqlPrivilegeResults();
    [, $output] = mysqlPrivilegesDoctor();

    expect($results)->toHaveCount(1)
        ->and($results[0]->message)->toMatch('/^Cannot connect with the read-only connection: PDOException, SQLSTATE [0-9A-Z]{5}\.$/')
        ->and(str_contains($output, $role->name()))->toBeFalse()
        ->and(str_contains($output, 'wrong-password'))->toBeFalse()
        ->and(str_contains($output, '127.0.0.1'))->toBeFalse();
});

it('catches a read-only connection that logs in with the app user (01-10 hand-off)', function () {
    mysqlPrivilegesRole();
    config()->set('database.connections.'.ReadOnlyRole::CONNECTION, config('database.connections.testing_mysql'));
    DB::purge(ReadOnlyRole::CONNECTION);

    [$write, $superuser, $masked] = mysqlPrivilegeResults();

    expect($write->status->value)->toBe('fail')
        ->and($superuser->status->value)->toBe('fail')
        ->and($masked->status->value)->toBe('fail');
});

it('changes no grant and no default role: a new login has both after doctor ran', function () {
    $role = mysqlPrivilegesRole();
    $extra = mysqlPrivilegesNewRole();
    mysqlPrivilegesGrant('GRANT INSERT ON '.mysqlPrivilegesTable('orders')." TO '{$role->name()}'@'%'");
    mysqlPrivilegesGrant("GRANT `{$extra}` TO '{$role->name()}'@'%'");
    mysqlPrivilegesGrant(mysqlPrivilegesIsMaria()
        ? "SET DEFAULT ROLE `{$extra}` FOR '{$role->name()}'@'%'"
        : "SET DEFAULT ROLE `{$extra}` TO '{$role->name()}'@'%'");

    mysqlPrivilegesDoctor();
    $current = DB::connection(ReadOnlyRole::CONNECTION)->getPdo()->query('select current_role()');
    $grants = DB::connection('testing_mysql')->getPdo()->query("SHOW GRANTS FOR '{$role->name()}'@'%'");

    expect($current === false ? null : $current->fetchColumn())->toContain($extra)
        ->and($grants === false ? '' : json_encode($grants->fetchAll(PDO::FETCH_COLUMN), JSON_THROW_ON_ERROR))->toContain('INSERT');
});
