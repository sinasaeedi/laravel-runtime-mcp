<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyRolePrivileges;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 11.2 "Doctor", 01-13: the three privilege checks against a real PostgreSQL role. The role
 * is 01-11's (NOINHERIT, column grants); each test then changes one grant with the privileged
 * connection, as a careless operator or a later migration would. The default connection is
 * testing_pgsql, the read-only one runtime_mcp_ro (01-10's boot rule passes).
 */

beforeEach(fn () => Databases::requiresPostgres());

/**
 * @param  array<string, mixed>  $config
 * @param  (Closure(string): void)|null  $extend
 */
function pgsqlPrivilegesRole(string $environment = 'production', array $config = [], ?Closure $extend = null): ReadOnlyRole
{
    return ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', ['database.default' => 'testing_pgsql', ...$config], $environment, $extend);
}

/** Runs SQL as the privileged user (postgres, a superuser in CI). */
function pgsqlPrivilegesGrant(string $sql): void
{
    DB::connection('testing_pgsql')->getPdo()->exec($sql);
}

/** @return list<CheckResult> write privileges, superuser rights, masked columns */
function pgsqlPrivilegeResults(): array
{
    return app()->make(ReadOnlyRolePrivileges::class)->run();
}

/** @return array{int, string} */
function pgsqlPrivilegesDoctor(): array
{
    $code = Artisan::call(DoctorCommand::NAME);

    return [$code, Artisan::output()];
}

/**
 * The one value of a one-row select, for example "select has_table_privilege(...)".
 *
 * @param  list<string>  $bindings
 */
function pgsqlPrivilegesValue(string $connection, string $sql, array $bindings = []): mixed
{
    $row = DB::connection($connection)->selectOne($sql, $bindings);

    return is_object($row) ? (array_values((array) $row)[0] ?? null) : null;
}

function pgsqlQuoted(ReadOnlyRole $role): string
{
    return RoleSqlGenerator::quotePostgres($role->name());
}

it('passes the three checks for the role that 01-11 writes, and doctor exits 0', function () {
    pgsqlPrivilegesRole();

    $results = pgsqlPrivilegeResults();
    [$code, $output] = pgsqlPrivilegesDoctor();

    expect(array_map(fn (CheckResult $result): array => [$result->status->value, $result->message], $results))->toBe([
        ['pass', 'No write privilege on any table.'],
        ['pass', 'No superuser rights.'],
        ['pass', 'No masked column and no denied table is readable.'],
    ])
        ->and($code)->toBe(0)
        ->and(substr_count($output, '✓ Read-only role'))->toBe(3);
});

it('check 1: fails for each write privilege on a table, with the REVOKE, and exits 1 in production', function (string $privilege) {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant("GRANT {$privilege} ON orders TO ".pgsqlQuoted($role));

    $write = pgsqlPrivilegeResults()[0];
    [$code, $output] = pgsqlPrivilegesDoctor();

    expect($write->status->value)->toBe('fail')
        ->and($write->privilege)->toBeTrue()
        ->and($write->message)->toBe('The read-only role can write or change the schema: public.orders.')
        ->and($write->fix)->toBe('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE "public"."orders" FROM '.pgsqlQuoted($role).";\n      Run doctor again after the fix: it shows what is left.")
        ->and($code)->toBe(1)
        ->and($output)->toContain('✗ Read-only role');
})->with(['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE']);

it('check 2: fails for SUPERUSER and for BYPASSRLS, with the ALTER ROLE', function (string $right) {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('ALTER ROLE '.pgsqlQuoted($role)." {$right}");

    [$write, $superuser] = pgsqlPrivilegeResults();

    expect($superuser->status->value)->toBe('fail')
        ->and($superuser->message)->toBe("The read-only role has superuser rights: {$role->name()}: {$right}.")
        ->and(str_contains($write->message, '(through'))->toBeFalse()
        ->and($superuser->fix)->toStartWith('ALTER ROLE '.pgsqlQuoted($role)." NO{$right};");
})->with(['SUPERUSER', 'BYPASSRLS']);

it('check 3, drift: a table-level grant passes, then a migration adds api_token and doctor names it', function () {
    $role = pgsqlPrivilegesRole();

    expect(pgsqlPrivilegeResults()[2]->status->value)->toBe('pass');

    pgsqlPrivilegesGrant('ALTER TABLE orders ADD COLUMN api_token varchar(64)');
    $masked = pgsqlPrivilegeResults()[2];
    $grantee = pgsqlQuoted($role);

    expect($masked->status->value)->toBe('fail')
        ->and($masked->privilege)->toBeTrue()
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.orders.api_token.')
        ->and($masked->fix)->toStartWith(
            "REVOKE SELECT ON TABLE \"public\".\"orders\" FROM {$grantee}; "
            ."GRANT SELECT (\"id\", \"user_id\", \"number\", \"total\", \"status\", \"created_at\") ON TABLE \"public\".\"orders\" TO {$grantee};"
        );
});

it('check 3: fails for a column grant on a masked column, with a column REVOKE', function () {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT SELECT (password) ON users TO '.pgsqlQuoted($role));

    $masked = pgsqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.users.password.')
        ->and($masked->fix)->toStartWith('REVOKE SELECT ("password") ON TABLE "public"."users" FROM '.pgsqlQuoted($role).';');
});

it('check 3: fails for a readable denied table', function () {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT SELECT ON sessions TO '.pgsqlQuoted($role));

    $masked = pgsqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.sessions (denied table).')
        ->and($masked->fix)->toStartWith('REVOKE ALL ON TABLE "public"."sessions" FROM '.pgsqlQuoted($role).';');
});

it('check 3: fails for a granted view that shows a masked column (Sina, Q3)', function () {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT SELECT ON user_secrets TO '.pgsqlQuoted($role));

    $masked = pgsqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.user_secrets.password.')
        ->and($masked->fix)->toStartWith('REVOKE SELECT ON TABLE "public"."user_secrets" FROM '.pgsqlQuoted($role).';');
});

it('check 3: fails for a hand-made grant on a partition that holds a masked column', function () {
    $role = pgsqlPrivilegesRole(extend: function (string $privileged): void {
        $db = DB::connection($privileged);
        $db->statement('CREATE TABLE events (id integer, happened_on date, client_secret text) PARTITION BY RANGE (happened_on)');
        $db->statement("CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')");
    });

    expect(pgsqlPrivilegeResults()[2]->status->value)->toBe('pass');

    pgsqlPrivilegesGrant('GRANT SELECT ON events_2026 TO '.pgsqlQuoted($role));
    $masked = pgsqlPrivilegeResults()[2];

    expect($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.events_2026.client_secret.')
        // A partition gets no grant from 01-11, so the fix does not grant its columns back.
        ->and($masked->fix)->toStartWith('REVOKE SELECT ON TABLE "public"."events_2026" FROM '.pgsqlQuoted($role).";\n");
});

it('check 3: matches a denied table through the prefix of the default connection', function () {
    $role = pgsqlPrivilegesRole(
        config: ['database.connections.testing_pgsql.prefix' => 'app_'],
        extend: function (string $privileged): void {
            DB::connection($privileged)->statement('CREATE TABLE app_sessions (id text, payload text)');
        },
    );
    pgsqlPrivilegesGrant('GRANT SELECT ON app_sessions TO '.pgsqlQuoted($role));

    expect(pgsqlPrivilegeResults()[2]->message)->toContain('public.app_sessions (denied table)');
});

it('checks every schema of the database, not only the search_path', function () {
    $role = pgsqlPrivilegesRole(extend: function (string $privileged): void {
        DB::connection($privileged)->statement('DROP SCHEMA IF EXISTS rmcp_audit CASCADE');
        DB::connection($privileged)->statement('CREATE SCHEMA rmcp_audit');
        DB::connection($privileged)->statement('CREATE TABLE rmcp_audit.logs (id integer, client_secret text)');
    });
    currentTest()->beforeApplicationDestroyed(fn () => pgsqlPrivilegesGrant('DROP SCHEMA IF EXISTS rmcp_audit CASCADE'));
    pgsqlPrivilegesGrant('GRANT USAGE ON SCHEMA rmcp_audit TO '.pgsqlQuoted($role));
    pgsqlPrivilegesGrant('GRANT INSERT, SELECT ON rmcp_audit.logs TO '.pgsqlQuoted($role));

    [$write, , $masked] = pgsqlPrivilegeResults();

    expect($write->message)->toBe('The read-only role can write or change the schema: rmcp_audit.logs.')
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: rmcp_audit.logs.client_secret.');
});

it('checks every role the read-only role is a member of, although it is NOINHERIT (Sina, Q1)', function () {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT pg_write_all_data TO '.pgsqlQuoted($role));
    pgsqlPrivilegesGrant('GRANT pg_read_all_data TO '.pgsqlQuoted($role));

    // The premise: has_table_privilege() for the role itself does not see the membership.
    $own = pgsqlPrivilegesValue(ReadOnlyRole::CONNECTION, "select has_table_privilege('orders', 'INSERT')");
    [$write, , $masked] = pgsqlPrivilegeResults();

    expect($own)->toBeFalse()
        ->and($write->message)->toContain('public.orders (through pg_write_all_data)')
        ->and($write->fix)->toBe('REVOKE "pg_write_all_data" FROM '.pgsqlQuoted($role).";\n      Run doctor again after the fix: it shows what is left.")
        ->and($masked->message)->toContain('public.users.password (through pg_read_all_data)')
        ->and($masked->fix)->toStartWith('REVOKE "pg_read_all_data" FROM '.pgsqlQuoted($role).';');
});

it('check 2: fails for membership in a superuser role', function () {
    $role = pgsqlPrivilegesRole();
    $super = 'rmcp_su_'.bin2hex(random_bytes(4));
    currentTest()->beforeApplicationDestroyed(fn () => pgsqlPrivilegesGrant("DROP ROLE IF EXISTS {$super}"));
    pgsqlPrivilegesGrant("CREATE ROLE {$super} SUPERUSER NOLOGIN");
    pgsqlPrivilegesGrant("GRANT {$super} TO ".pgsqlQuoted($role));

    $superuser = pgsqlPrivilegeResults()[1];

    expect($superuser->message)->toBe("The read-only role has superuser rights: member of {$super} (SUPERUSER).")
        ->and($superuser->fix)->toStartWith("REVOKE \"{$super}\" FROM ".pgsqlQuoted($role).';');
});

it('names a privilege that PUBLIC holds, with a REVOKE … FROM PUBLIC', function () {
    pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT INSERT ON orders TO PUBLIC');
    pgsqlPrivilegesGrant('GRANT SELECT (password) ON users TO PUBLIC');

    [$write, , $masked] = pgsqlPrivilegeResults();

    expect($write->message)->toBe('The read-only role can write or change the schema: public.orders (through PUBLIC).')
        ->and($write->fix)->toStartWith('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE "public"."orders" FROM PUBLIC;')
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.users.password (through PUBLIC).')
        ->and($masked->fix)->toStartWith('REVOKE SELECT ("password") ON TABLE "public"."users" FROM PUBLIC;');
});

it('says so when the role owns a table: no REVOKE takes the rights of an owner away', function () {
    $role = pgsqlPrivilegesRole(extend: function (string $privileged): void {
        DB::connection($privileged)->statement('CREATE TABLE owned (id integer, api_token text)');
    });
    pgsqlPrivilegesGrant('ALTER TABLE owned OWNER TO '.pgsqlQuoted($role));

    [$write, , $masked] = pgsqlPrivilegeResults();

    expect($write->message)->toBe('The read-only role can write or change the schema: public.owned (the role owns it).')
        ->and($write->fix)->toStartWith('ALTER TABLE "public"."owned" OWNER TO <the app\'s own role>;')
        ->and($masked->message)->toBe('The read-only role can read masked columns or denied tables: public.owned.api_token.')
        ->and($masked->fix)->toStartWith('ALTER TABLE "public"."owned" OWNER TO <the app\'s own role>;');
});

it('lists at most 20 items and 20 fix lines, then says how many more', function () {
    $role = pgsqlPrivilegesRole(extend: function (string $privileged): void {
        foreach (range(1, 25) as $n) {
            DB::connection($privileged)->statement(sprintf('CREATE TABLE extra_%02d (id integer)', $n));
        }
    });
    pgsqlPrivilegesGrant('GRANT INSERT ON ALL TABLES IN SCHEMA public TO '.pgsqlQuoted($role));

    $write = pgsqlPrivilegeResults()[0];
    $fixes = explode("\n      ", (string) $write->fix);

    // 25 extra tables and the 9 fixture tables: 34 tables, so 14 more.
    expect($write->message)->toEndWith(', and 14 more.')
        ->and(substr_count($write->message, 'public.'))->toBe(20)
        ->and($fixes)->toHaveCount(22)
        ->and($fixes[20])->toBe('and 14 more')
        ->and($fixes[21])->toBe('Run doctor again after the fix: it shows what is left.');
});

it('only warns, exit 0, for a failed check in a relaxed environment (spec 4.6)', function () {
    $role = pgsqlPrivilegesRole('local');
    pgsqlPrivilegesGrant('GRANT INSERT ON orders TO '.pgsqlQuoted($role));

    [$code, $output] = pgsqlPrivilegesDoctor();

    expect($code)->toBe(0)
        ->and($output)->toContain('! Read-only role')
        ->and($output)->toContain('public.orders');
});

it('skips check 3 with a warning when a mask list is not an array, and still runs checks 1 and 2', function () {
    pgsqlPrivilegesRole(config: ['runtime-mcp.database.masked_columns' => 'password']);

    expect(array_map(fn (CheckResult $result): string => $result->status->value, pgsqlPrivilegeResults()))->toBe(['pass', 'pass', 'warn'])
        ->and(pgsqlPrivilegeResults()[2]->message)->toBe('Masked columns not checked: a mask list is not an array (see Mask lists).');
});

it('reports a wrong password once, with the class and SQLSTATE only', function () {
    $role = pgsqlPrivilegesRole();
    config()->set('database.connections.'.ReadOnlyRole::CONNECTION.'.password', 'wrong-password');
    DB::purge(ReadOnlyRole::CONNECTION);

    $results = pgsqlPrivilegeResults();
    [, $output] = pgsqlPrivilegesDoctor();

    expect($results)->toHaveCount(1)
        ->and($results[0]->message)->toBe('Cannot connect with the read-only connection: PDOException, SQLSTATE 08006.')
        ->and($results[0]->privilege)->toBeTrue()
        ->and(str_contains($output, $role->name()))->toBeFalse()
        ->and(str_contains($output, 'wrong-password'))->toBeFalse()
        ->and(str_contains($output, '127.0.0.1'))->toBeFalse();
});

it('catches a read-only connection that logs in with the app user (01-10 hand-off)', function () {
    pgsqlPrivilegesRole();
    config()->set('database.connections.'.ReadOnlyRole::CONNECTION, config('database.connections.testing_pgsql'));
    DB::purge(ReadOnlyRole::CONNECTION);

    [$write, $superuser] = pgsqlPrivilegeResults();

    expect($write->status->value)->toBe('fail')
        ->and($superuser->message)->toContain('SUPERUSER');
});

it('changes no grant: the privilege is still there after doctor ran', function () {
    $role = pgsqlPrivilegesRole();
    pgsqlPrivilegesGrant('GRANT INSERT ON orders TO '.pgsqlQuoted($role));

    pgsqlPrivilegesDoctor();
    expect(pgsqlPrivilegesValue('testing_pgsql', "select has_table_privilege(?, 'orders', 'INSERT')", [$role->name()]))->toBeTrue();
});
