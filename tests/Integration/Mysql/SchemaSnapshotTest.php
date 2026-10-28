<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Grants\TableSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Grants\FixtureSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

beforeEach(function () {
    Databases::requiresMysql();
    ReadOnlyRole::schema('testing_mysql');
});

/** @return list<string> */
function mysqlSnapshotNames(SchemaSnapshot $snapshot): array
{
    return array_map(fn (TableSnapshot $table): string => $table->schema.'.'.$table->name, $snapshot->tables);
}

it('reads the fixture schema exactly as the hand-built snapshot', function () {
    $privileged = DB::connection('testing_mysql');

    // This keeps the golden files honest. The driver is mysql or mariadb, by the CI cell.
    expect(SchemaSnapshot::read($privileged))->toEqual(FixtureSnapshot::mysql($privileged->getDriverName()));
});

it('leaves the view out and sorts by name', function () {
    $names = mysqlSnapshotNames(SchemaSnapshot::read(DB::connection('testing_mysql')));
    $sorted = $names;
    usort($sorted, strcmp(...));

    expect($names)->not->toContain('runtime_mcp.user_secrets')
        ->and($names)->toBe($sorted);
});

it('sorts by byte order, not by the database collation', function () {
    $privileged = DB::connection('testing_mysql');

    try {
        // A case-insensitive collation puts alpha before Zeta; byte order puts Zeta first.
        $privileged->statement('CREATE TABLE alpha (id integer)');
        $privileged->statement('CREATE TABLE Zeta (id integer)');

        expect(array_slice(mysqlSnapshotNames(SchemaSnapshot::read($privileged)), 0, 3))
            ->toBe(['runtime_mcp.Zeta', 'runtime_mcp.alpha', 'runtime_mcp.cache']);
    } finally {
        $privileged->statement('DROP TABLE IF EXISTS alpha');
        $privileged->statement('DROP TABLE IF EXISTS Zeta');
    }
});

it('reads only the database of the connection', function () {
    $privileged = DB::connection('testing_mysql');

    try {
        $privileged->statement('CREATE DATABASE runtime_mcp_other');
        $privileged->statement('CREATE TABLE runtime_mcp_other.hidden (id integer, client_secret text)');

        $snapshot = SchemaSnapshot::read($privileged);

        expect(SchemaSnapshot::appSchemas($privileged))->toBe(['runtime_mcp'])
            ->and(mysqlSnapshotNames($snapshot))->not->toContain('runtime_mcp_other.hidden')
            ->and(array_unique(array_map(fn (TableSnapshot $t): string => $t->schema, $snapshot->tables)))->toBe(['runtime_mcp']);
    } finally {
        $privileged->statement('DROP DATABASE IF EXISTS runtime_mcp_other');
    }
});

it('reads the columns of a prefixed table without adding the prefix again', function () {
    $privileged = DB::connection('testing_mysql');
    $config = config('database.connections.testing_mysql');
    assert(is_array($config));

    try {
        $privileged->statement('CREATE TABLE pfx_widgets (id integer, name varchar(20), api_token varchar(20))');
        config()->set('database.connections.testing_mysql_pfx', [...$config, 'prefix' => 'pfx_']);

        $snapshot = SchemaSnapshot::read(DB::connection('testing_mysql_pfx'));

        expect($snapshot->prefix)->toBe('pfx_')
            ->and($snapshot->tables)->toContainEqual(new TableSnapshot('runtime_mcp', 'pfx_widgets', ['id', 'name', 'api_token']));
    } finally {
        DB::purge('testing_mysql_pfx');
        $privileged->statement('DROP TABLE IF EXISTS pfx_widgets');
    }
});

it('keeps a database whose name starts with pg_', function () {
    // The pg_ filter is for PostgreSQL system schemas only.
    $privileged = DB::connection('testing_mysql');
    $config = config('database.connections.testing_mysql');
    assert(is_array($config));

    try {
        $privileged->statement('CREATE DATABASE pg_runtime_mcp');
        config()->set('database.connections.testing_mysql_pg', [...$config, 'database' => 'pg_runtime_mcp']);

        expect(SchemaSnapshot::appSchemas(DB::connection('testing_mysql_pg')))->toBe(['pg_runtime_mcp']);
    } finally {
        DB::purge('testing_mysql_pg');
        $privileged->statement('DROP DATABASE IF EXISTS pg_runtime_mcp');
    }
});

it('reads the columns of a table whose name holds a quote or a backslash', function () {
    $privileged = DB::connection('testing_mysql');

    try {
        $privileged->statement("CREATE TABLE `o'brien\\notes` (id integer, password varchar(20))");

        expect(SchemaSnapshot::read($privileged)->tables)
            ->toContainEqual(new TableSnapshot('runtime_mcp', "o'brien\\notes", ['id', 'password']));
    } finally {
        $privileged->statement("DROP TABLE IF EXISTS `o'brien\\notes`");
    }
});
