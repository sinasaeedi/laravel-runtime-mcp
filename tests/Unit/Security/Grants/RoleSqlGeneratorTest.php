<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Grants\TableSnapshot;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Grants\FixtureSnapshot;

/** A generator with the spec 4.5 default lists. */
function roleSqlGenerator(string $appName = 'Runtime Test', string $environment = 'production'): RoleSqlGenerator
{
    return new RoleSqlGenerator(
        new ColumnMask(['password', 'remember_token', '*token*', '*secret*', '*api_key*']),
        new DeniedTables([
            'password_reset_tokens', 'personal_access_tokens', 'sessions', 'oauth_access_tokens',
            'jobs', 'job_batches', 'cache', 'cache_locks',
        ]),
        $appName,
        $environment,
    );
}

/** @param list<TableSnapshot> $tables */
function roleSqlSnapshot(string $driver, array $tables, string $prefix = ''): SchemaSnapshot
{
    return new SchemaSnapshot($driver, 'runtime_mcp', $prefix, $tables);
}

/** @return list<string> */
function roleSqlLines(string $sql): array
{
    return explode("\n", $sql);
}

/**
 * The statements, as ReadOnlyRole runs them: comments out, then split on ; at a line end.
 *
 * @return list<string>
 */
function roleSqlStatements(string $sql): array
{
    $lines = array_filter(explode("\n", $sql), fn (string $line): bool => ! str_starts_with($line, '--'));
    $statements = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

    return array_values(array_filter(array_map(trim(...), $statements), fn (string $s): bool => $s !== ''));
}

function roleSqlGolden(string $file): string
{
    $sql = file_get_contents(dirname(__DIR__, 3).'/Fixtures/Grants/'.$file);
    assert(is_string($sql));

    return $sql;
}

it('writes the PostgreSQL golden file byte for byte', function () {
    expect(roleSqlGenerator()->forPostgres(FixtureSnapshot::postgres()))->toBe(roleSqlGolden('postgres.sql'));
});

it('writes the MySQL golden file byte for byte', function () {
    expect(roleSqlGenerator()->forMySql(FixtureSnapshot::mysql()))->toBe(roleSqlGolden('mysql.sql'));
});

it('writes the MySQL SQL for MariaDB', function () {
    $generator = roleSqlGenerator();
    $snapshot = FixtureSnapshot::mysql('mariadb');

    expect($generator->forDriver('mariadb', $snapshot))->toBe($generator->forMySql($snapshot))
        ->and($generator->forDriver('mariadb', $snapshot))->toBe(roleSqlGolden('mysql.sql'))
        ->and($generator->forDriver('mysql', $snapshot, 'other_role', '10.0.%'))->toBe($generator->forMySql($snapshot, 'other_role', '10.0.%'));
});

it('writes the PostgreSQL SQL for pgsql and ignores the host', function () {
    $generator = roleSqlGenerator();
    $snapshot = FixtureSnapshot::postgres();

    expect($generator->forDriver('pgsql', $snapshot, 'other_role', 'not a host!'))->toBe($generator->forPostgres($snapshot, 'other_role'));
});

it('refuses a driver it does not support', function (string $driver) {
    $thrown = null;

    try {
        roleSqlGenerator()->forDriver($driver, FixtureSnapshot::postgres());
    } catch (UnsupportedDriver $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedDriver::class)
        ->and($thrown?->driver)->toBe($driver)
        ->and($thrown?->getMessage())->toBe("Only PostgreSQL, MySQL and MariaDB are supported; this connection uses {$driver}.");
})->with(['sqlite', 'sqlsrv', 'PGSQL', '']);

it('quotes an identifier by doubling its quote character', function () {
    expect(RoleSqlGenerator::quotePostgres('users'))->toBe('"users"')
        ->and(RoleSqlGenerator::quotePostgres('Order "Lines"'))->toBe('"Order ""Lines"""')
        ->and(RoleSqlGenerator::quotePostgres('we`ird'))->toBe('"we`ird"')
        ->and(RoleSqlGenerator::quoteMySql('users'))->toBe('`users`')
        ->and(RoleSqlGenerator::quoteMySql('we`ird'))->toBe('`we``ird`')
        ->and(RoleSqlGenerator::quoteMySql('Order "Lines"'))->toBe('`Order "Lines"`');
});

it('quotes odd table and column names in both dialects', function () {
    $tables = [
        new TableSnapshot('public', 'Order "Lines"', ['id', 'we`ird', 'a"b']),
        new TableSnapshot('public', 'odd`table', ['id', 'secret"x']),
    ];

    $postgres = roleSqlLines(roleSqlGenerator()->forPostgres(roleSqlSnapshot('pgsql', $tables)));
    $mysql = roleSqlLines(roleSqlGenerator()->forMySql(roleSqlSnapshot('mysql', [
        new TableSnapshot('runtime_mcp', 'Order "Lines"', ['id', 'we`ird']),
        new TableSnapshot('runtime_mcp', 'odd`table', ['id', 'secret`x']),
    ])));

    expect($postgres)->toContain('GRANT SELECT ON TABLE "public"."Order ""Lines""" TO "runtime_mcp_ro";')
        ->and($postgres)->toContain('GRANT SELECT ("id") ON TABLE "public"."odd`table" TO "runtime_mcp_ro";')
        ->and($postgres)->toContain('-- Masked columns (not granted): "public"."odd`table"."secret""x"')
        ->and($mysql)->toContain("GRANT SELECT ON `runtime_mcp`.`Order \"Lines\"` TO 'runtime_mcp_ro'@'%';")
        ->and($mysql)->toContain("GRANT SELECT (`id`) ON `runtime_mcp`.`odd``table` TO 'runtime_mcp_ro'@'%';")
        ->and($mysql)->toContain('-- Masked columns (not granted): `runtime_mcp`.`odd``table`.`secret``x`');
});

it('grants the readable columns in table order', function () {
    $sql = roleSqlGenerator()->forPostgres(roleSqlSnapshot('pgsql', [
        new TableSnapshot('public', 'accounts', ['z_last', 'api_token', 'a_first', 'PASSWORD', 'password_changed_at']),
    ]));

    expect(roleSqlLines($sql))
        ->toContain('GRANT SELECT ("z_last", "a_first", "password_changed_at") ON TABLE "public"."accounts" TO "runtime_mcp_ro";')
        ->toContain('-- Masked columns (not granted): "public"."accounts"."api_token", "public"."accounts"."PASSWORD"');
});

it('gives no grant to a table whose every column is masked, or that has no column', function () {
    $snapshot = roleSqlSnapshot('pgsql', [
        new TableSnapshot('public', 'jobs', ['id']),
        new TableSnapshot('public', 'orders', ['id']),
        new TableSnapshot('public', 'secrets', ['api_token', 'client_secret']),
        new TableSnapshot('public', 't', []),
    ]);

    expect(roleSqlLines(roleSqlGenerator()->forPostgres($snapshot)))->toBe([
        '-- Runtime MCP read-only role for Runtime Test (production).',
        '-- Generated by sinasaeedi/laravel-runtime-mcp. Review it, set a strong password, and run it as the',
        '-- database owner or a superuser. Re-run `php artisan runtime-mcp:doctor` afterwards.',
        'CREATE ROLE "runtime_mcp_ro" LOGIN PASSWORD \'CHANGE_ME\'',
        '    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;',
        'GRANT CONNECT ON DATABASE "runtime_mcp" TO "runtime_mcp_ro";',
        'GRANT USAGE ON SCHEMA "public" TO "runtime_mcp_ro";',
        'GRANT SELECT ON TABLE "public"."orders" TO "runtime_mcp_ro";',
        '-- No grant (denied_tables): "public"."jobs"',
        '-- No grant (every column masked): "public"."secrets", "public"."t"',
        '-- New tables get NO grant automatically. Add a GRANT when you add a table.',
        '',
    ]);

    $mysql = roleSqlLines(roleSqlGenerator()->forMySql(roleSqlSnapshot('mysql', [
        new TableSnapshot('runtime_mcp', 'secrets', ['api_token']),
    ])));

    expect($mysql)->toContain('-- No grant (every column masked): `runtime_mcp`.`secrets`')
        ->and(implode("\n", $mysql))->not->toContain('GRANT SELECT');
});

it('grants USAGE once per schema that has a grant, in sorted order, before the table grants', function () {
    $snapshot = roleSqlSnapshot('pgsql', [
        new TableSnapshot('audit', 'sessions', ['id']),
        new TableSnapshot('reports', 'daily', ['id']),
        new TableSnapshot('reports', 'weekly', ['id']),
        new TableSnapshot('archive', 'orders', ['id']),
        new TableSnapshot('empty', 'secrets', ['client_secret']),
    ]);

    $usage = array_values(array_filter(
        roleSqlLines(roleSqlGenerator()->forPostgres($snapshot)),
        fn (string $line): bool => str_starts_with($line, 'GRANT USAGE') || str_starts_with($line, 'GRANT SELECT'),
    ));

    expect($usage)->toBe([
        'GRANT USAGE ON SCHEMA "archive" TO "runtime_mcp_ro";',
        'GRANT USAGE ON SCHEMA "reports" TO "runtime_mcp_ro";',
        'GRANT SELECT ON TABLE "reports"."daily" TO "runtime_mcp_ro";',
        'GRANT SELECT ON TABLE "reports"."weekly" TO "runtime_mcp_ro";',
        'GRANT SELECT ON TABLE "archive"."orders" TO "runtime_mcp_ro";',
    ]);
});

it('denies a prefixed table by its name without the prefix', function () {
    $sql = roleSqlGenerator()->forMySql(roleSqlSnapshot('mysql', [
        new TableSnapshot('runtime_mcp', 'pfx_sessions', ['id']),
        new TableSnapshot('runtime_mcp', 'pfx_widgets', ['id', 'name']),
    ], 'pfx_'));

    expect(roleSqlLines($sql))
        ->toContain("GRANT SELECT ON `runtime_mcp`.`pfx_widgets` TO 'runtime_mcp_ro'@'%';")
        ->toContain('-- No grant (denied_tables): `runtime_mcp`.`pfx_sessions`');
});

it('writes valid SQL for a snapshot with no table', function () {
    expect(roleSqlGenerator()->forPostgres(roleSqlSnapshot('pgsql', [])))->toBe(implode("\n", [
        '-- Runtime MCP read-only role for Runtime Test (production).',
        '-- Generated by sinasaeedi/laravel-runtime-mcp. Review it, set a strong password, and run it as the',
        '-- database owner or a superuser. Re-run `php artisan runtime-mcp:doctor` afterwards.',
        'CREATE ROLE "runtime_mcp_ro" LOGIN PASSWORD \'CHANGE_ME\'',
        '    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;',
        'GRANT CONNECT ON DATABASE "runtime_mcp" TO "runtime_mcp_ro";',
        '-- New tables get NO grant automatically. Add a GRANT when you add a table.',
    ])."\n");

    expect(roleSqlStatements(roleSqlGenerator()->forMySql(roleSqlSnapshot('mysql', []))))
        ->toBe(["CREATE USER 'runtime_mcp_ro'@'%' IDENTIFIED BY 'CHANGE_ME'"]);
});

it('writes the Host % comment only for the host %', function () {
    $snapshot = FixtureSnapshot::mysql();
    $comment = "-- Host '%' accepts a connection from any host. Narrow it if you can (runtime-mcp:install --host).";

    $any = roleSqlLines(roleSqlGenerator()->forMySql($snapshot));
    $narrow = roleSqlLines(roleSqlGenerator()->forMySql($snapshot, host: '10.0.%'));

    expect($any)->toContain($comment)
        ->and($narrow)->not->toContain($comment)
        ->and($narrow)->toContain("CREATE USER 'runtime_mcp_ro'@'10.0.%' IDENTIFIED BY 'CHANGE_ME';")
        ->and($narrow)->toContain("GRANT SELECT ON `runtime_mcp`.`orders` TO 'runtime_mcp_ro'@'10.0.%';");
});

it('uses the role name it is given', function () {
    $name = 'r'.str_repeat('x', 31);

    expect(roleSqlLines(roleSqlGenerator()->forPostgres(FixtureSnapshot::postgres(), $name)))
        ->toContain("CREATE ROLE \"{$name}\" LOGIN PASSWORD 'CHANGE_ME'")
        ->toContain("GRANT SELECT ON TABLE \"public\".\"orders\" TO \"{$name}\";")
        ->and(roleSqlLines(roleSqlGenerator()->forMySql(FixtureSnapshot::mysql(), '_ro')))
        ->toContain("CREATE USER '_ro'@'%' IDENTIFIED BY 'CHANGE_ME';");
});

it('refuses a role name that could break the SQL or MySQL', function (string $role) {
    $message = 'The role name must match /^[A-Za-z_][A-Za-z0-9_]{0,31}$/ (letters, digits and _, at most 32 characters).';

    expect(fn () => roleSqlGenerator()->forPostgres(FixtureSnapshot::postgres(), $role))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => roleSqlGenerator()->forMySql(FixtureSnapshot::mysql(), $role))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => roleSqlGenerator()->forDriver('pgsql', FixtureSnapshot::postgres(), $role))->toThrow(InvalidArgumentException::class, $message);
})->with([
    '33 characters' => ['r'.str_repeat('x', 32)],
    'a quote' => ["ro'le"],
    'a double quote' => ['ro"le'],
    'a space' => ['ro le'],
    'a leading digit' => ['1role'],
    'a dash' => ['ro-le'],
    'empty' => [''],
    'a line break at the end' => ["role\n"],
    'a non-ASCII letter' => ['rôle'],
]);

it('refuses a MySQL host that could break the SQL', function (string $host) {
    expect(fn () => roleSqlGenerator()->forMySql(FixtureSnapshot::mysql(), host: $host))
        ->toThrow(InvalidArgumentException::class, 'The MySQL host must match /^[A-Za-z0-9.%_:-]{1,255}$/, for example % or 10.0.%.');
})->with([
    'an injection' => ["x'; DROP USER root; --"],
    'empty' => [''],
    'a space' => ['10.0. %'],
    'a backtick' => ['a`b'],
    'a line break at the end' => ["localhost\n"],
    '256 characters' => [str_repeat('a', 256)],
]);

it('accepts the hosts that 01-28 passes with --host', function (string $host) {
    expect(roleSqlLines(roleSqlGenerator()->forMySql(FixtureSnapshot::mysql(), host: $host)))
        ->toContain("CREATE USER 'runtime_mcp_ro'@'{$host}' IDENTIFIED BY 'CHANGE_ME';");
})->with(['%', '10.0.%', 'localhost', 'db-1.internal', '::1', '192.168.1.10', 'a_b', str_repeat('a', 255)]);

it('keeps the header on one comment line when the app name or the environment holds a control character', function () {
    $sql = roleSqlGenerator("Evil\nGRANT ALL ON *.* TO x;\r\x00\x7F", "prod\nDROP")->forPostgres(FixtureSnapshot::postgres());
    $lines = roleSqlLines($sql);

    expect($lines[0])->toBe('-- Runtime MCP read-only role for Evil GRANT ALL ON *.* TO x;    (prod DROP).')
        ->and($lines[1])->toStartWith('-- Generated by');

    $mysql = roleSqlLines(roleSqlGenerator("a\tb", "x\ny")->forMySql(FixtureSnapshot::mysql()));

    expect($mysql[0])->toBe('-- Runtime MCP read-only user for a b (x y).');
});

it('keeps every name on its comment line when a schema, table or column name holds a control character', function () {
    // A comment ends at a line break; without this the rest of the name ran as SQL (triage 2026-10-19,
    // proved on PostgreSQL 17 and MySQL 8.4). Inside a GRANT the name stays exact: a line break
    // inside "…" or `…` is part of the identifier, so that GRANT is still one statement.
    $evil = "\nDROP TABLE users;\n--";
    $sql = roleSqlGenerator()->forPostgres(roleSqlSnapshot('pgsql', [
        new TableSnapshot('audit'.$evil, 'sessions', ['id']),
        new TableSnapshot('public', 's'.$evil, ['api_token']),
        new TableSnapshot('public', 'x'.$evil, ['id', 'password', "api_token\r\nDELETE FROM users;\x00"]),
    ]));

    expect(roleSqlLines($sql))->toBe([
        '-- Runtime MCP read-only role for Runtime Test (production).',
        '-- Generated by sinasaeedi/laravel-runtime-mcp. Review it, set a strong password, and run it as the',
        '-- database owner or a superuser. Re-run `php artisan runtime-mcp:doctor` afterwards.',
        'CREATE ROLE "runtime_mcp_ro" LOGIN PASSWORD \'CHANGE_ME\'',
        '    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;',
        'GRANT CONNECT ON DATABASE "runtime_mcp" TO "runtime_mcp_ro";',
        'GRANT USAGE ON SCHEMA "public" TO "runtime_mcp_ro";',
        'GRANT SELECT ("id") ON TABLE "public"."x',
        'DROP TABLE users;',
        '--" TO "runtime_mcp_ro";',
        '-- No grant (denied_tables): "audit DROP TABLE users; --"."sessions"',
        '-- No grant (every column masked): "public"."s DROP TABLE users; --"',
        '-- Masked columns (not granted): "public"."x DROP TABLE users; --"."password", "public"."x DROP TABLE users; --"."api_token  DELETE FROM users; "',
        '-- New tables get NO grant automatically. Add a GRANT when you add a table.',
        '',
    ]);

    $mysql = roleSqlLines(roleSqlGenerator()->forMySql(roleSqlSnapshot('mysql', [
        new TableSnapshot('runtime_mcp', 's'.$evil, ['client_secret']),
        new TableSnapshot('runtime_mcp', 'x'.$evil, ['id', 'password']),
    ])));

    expect($mysql)->toContain('-- No grant (every column masked): `runtime_mcp`.`s DROP TABLE users; --`')
        ->toContain('-- Masked columns (not granted): `runtime_mcp`.`x DROP TABLE users; --`.`password`');
});

it('names an app with no name "this app"', function () {
    expect(roleSqlLines(roleSqlGenerator('', 'staging')->forPostgres(FixtureSnapshot::postgres()))[0])
        ->toBe('-- Runtime MCP read-only role for this app (staging).')
        ->and(roleSqlLines(roleSqlGenerator('', 'staging')->forMySql(FixtureSnapshot::mysql()))[0])
        ->toBe('-- Runtime MCP read-only user for this app (staging).');
});

it('never writes a default-privilege grant or any privilege but CONNECT, USAGE and SELECT (rule 7)', function (string $driver) {
    $generator = roleSqlGenerator();
    $snapshot = $driver === 'pgsql'
        ? roleSqlSnapshot('pgsql', [...FixtureSnapshot::postgres()->tables, new TableSnapshot('audit', 'log', ['id', 'secret'])])
        : FixtureSnapshot::mysql($driver);

    $statements = roleSqlStatements($generator->forDriver($driver, $snapshot));
    $others = [];

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $statement) {
        foreach (['ALTER DEFAULT PRIVILEGES', 'ALL TABLES', 'GRANT ALL', 'WITH GRANT OPTION', '*.*', '.*'] as $forbidden) {
            expect(str_contains(strtoupper($statement), $forbidden))->toBeFalse("[{$forbidden}] in: {$statement}");
        }

        if (str_starts_with($statement, 'GRANT')) {
            expect($statement)->toMatch('/^GRANT (SELECT( \(.+\))?|CONNECT|USAGE) ON /');
        } else {
            $others[] = $statement;
        }
    }

    expect($others)->toHaveCount(1)
        ->and($others[0])->toStartWith($driver === 'pgsql' ? 'CREATE ROLE' : 'CREATE USER');
})->with(['pgsql', 'mysql', 'mariadb']);
