<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Grants\TableSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Grants\FixtureSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

beforeEach(function () {
    Databases::requiresPostgres();
    ReadOnlyRole::schema('testing_pgsql');
});

/**
 * A copy of testing_pgsql under another name, with some settings changed.
 *
 * @param  array<string, mixed>  $changes
 */
function pgsqlSnapshotConnection(string $name, array $changes): string
{
    $config = config('database.connections.testing_pgsql');
    assert(is_array($config));

    config()->set("database.connections.{$name}", [...$config, ...$changes]);
    DB::purge($name);

    return $name;
}

/** @return list<string> */
function pgsqlSnapshotNames(SchemaSnapshot $snapshot): array
{
    return array_map(fn (TableSnapshot $table): string => $table->schema.'.'.$table->name, $snapshot->tables);
}

it('reads the fixture schema exactly as the hand-built snapshot', function () {
    // This keeps the golden files honest.
    expect(SchemaSnapshot::read(DB::connection('testing_pgsql')))->toEqual(FixtureSnapshot::postgres());
});

it('leaves the view out and sorts by schema, then name', function () {
    $names = pgsqlSnapshotNames(SchemaSnapshot::read(DB::connection('testing_pgsql')));
    $sorted = $names;
    usort($sorted, strcmp(...));

    expect($names)->not->toContain('public.user_secrets')
        ->and($names)->toBe($sorted);
});

it('reads only the schemas of the search path', function () {
    $privileged = DB::connection('testing_pgsql');

    try {
        $privileged->statement('CREATE SCHEMA runtime_mcp_other');
        $privileged->statement('CREATE TABLE runtime_mcp_other.hidden (id integer, client_secret text)');

        expect(pgsqlSnapshotNames(SchemaSnapshot::read($privileged)))->not->toContain('runtime_mcp_other.hidden')
            ->and(SchemaSnapshot::appSchemas($privileged))->toBe(['public']);

        $both = DB::connection(pgsqlSnapshotConnection('testing_pgsql_two', ['search_path' => 'public,runtime_mcp_other']));
        $snapshot = SchemaSnapshot::read($both);

        expect(SchemaSnapshot::appSchemas($both))->toBe(['public', 'runtime_mcp_other'])
            ->and(pgsqlSnapshotNames($snapshot))->toContain('runtime_mcp_other.hidden')
            ->and($snapshot->tables)->toContainEqual(new TableSnapshot('runtime_mcp_other', 'hidden', ['id', 'client_secret']));
    } finally {
        DB::purge('testing_pgsql_two');
        $privileged->statement('DROP SCHEMA IF EXISTS runtime_mcp_other CASCADE');
    }
});

it('leaves a partition out and keeps its parent', function () {
    $privileged = DB::connection('testing_pgsql');

    try {
        $privileged->statement('CREATE TABLE events (id integer, happened_on date) PARTITION BY RANGE (happened_on)');
        $privileged->statement("CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')");
        $privileged->statement('CREATE TABLE events_2027 PARTITION OF events FOR VALUES FROM (\'2027-01-01\') TO (\'2028-01-01\') PARTITION BY RANGE (happened_on)');

        $snapshot = SchemaSnapshot::read($privileged);
        $names = pgsqlSnapshotNames($snapshot);

        expect(in_array('public.events_2026', $names, true))->toBeFalse()
            ->and(in_array('public.events_2027', $names, true))->toBeFalse()
            ->and($snapshot->tables)->toContainEqual(new TableSnapshot('public', 'events', ['id', 'happened_on']));
    } finally {
        $privileged->statement('DROP TABLE IF EXISTS events CASCADE');
    }
});

it('reads the columns of a prefixed table without adding the prefix again', function () {
    $privileged = DB::connection('testing_pgsql');

    try {
        $privileged->statement('CREATE TABLE pfx_widgets (id integer, name text, api_token text)');

        $snapshot = SchemaSnapshot::read(DB::connection(pgsqlSnapshotConnection('testing_pgsql_pfx', ['prefix' => 'pfx_'])));

        expect($snapshot->prefix)->toBe('pfx_')
            ->and($snapshot->tables)->toContainEqual(new TableSnapshot('public', 'pfx_widgets', ['id', 'name', 'api_token']));
    } finally {
        DB::purge('testing_pgsql_pfx');
        $privileged->statement('DROP TABLE IF EXISTS pfx_widgets');
    }
});

it('leaves out a dropped column', function () {
    $privileged = DB::connection('testing_pgsql');
    $privileged->statement('ALTER TABLE orders DROP COLUMN status');

    expect(SchemaSnapshot::read($privileged)->tables)
        ->toContainEqual(new TableSnapshot('public', 'orders', ['id', 'user_id', 'number', 'total', 'created_at']));
});

it('leaves out the system schemas that the search path names', function () {
    // With an explicit list, getTables() no longer leaves out pg_* and information_schema: pg_catalog in
    // the search path would give the role pg_authid (password hashes) and pg_statistic (column values).
    $system = DB::connection(pgsqlSnapshotConnection('testing_pgsql_system', ['search_path' => 'public,pg_catalog,information_schema']));

    try {
        $names = pgsqlSnapshotNames(SchemaSnapshot::read($system));

        expect(SchemaSnapshot::appSchemas($system))->toBe(['public'])
            ->and(array_values(array_filter($names, fn (string $name): bool => ! str_starts_with($name, 'public.'))))->toBe([]);
    } finally {
        DB::purge('testing_pgsql_system');
    }
});

it('reads the columns of a table whose name holds a quote or a backslash', function () {
    $privileged = DB::connection('testing_pgsql');

    try {
        $privileged->statement('CREATE TABLE "o\'brien\\notes" (id integer, password text)');

        expect(SchemaSnapshot::read($privileged)->tables)
            ->toContainEqual(new TableSnapshot('public', "o'brien\\notes", ['id', 'password']));
    } finally {
        $privileged->statement('DROP TABLE IF EXISTS "o\'brien\\notes"');
    }
});
