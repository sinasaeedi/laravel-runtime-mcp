<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ReadableSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbSchema;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;

/*
 * Spec 6.3, 11.2 ("Envelope"), 01-19: db_schema and ReadableSchema on PostgreSQL, as 01-11's read-only
 * role, over HTTP with a Gate that allows the tool. testing_pgsql is the privileged connection.
 * PostgreSQL lists every column of a readable table, with readable from the grants.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/**
 * The fixture schema of 01-11, plus this story's objects. They exist before the role, so 01-11 grants
 * them (masked columns and denied tables excepted); the partition and the view get no grant.
 *
 * @param  array<string, mixed>  $config
 * @param  (Closure(Illuminate\Database\Connection): void)|null  $more  more objects, before the role
 */
function pgsqlSchemaRole(array $config = [], ?Closure $more = null): ReadOnlyRole
{
    $role = ReadOnlyRole::setUp(
        currentTest(),
        'testing_pgsql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'runtime-mcp.database.statement_timeout_ms' => 500, ...$config],
        extend: function (string $privileged) use ($more): void {
            $db = DB::connection($privileged);
            $db->statement('ALTER TABLE users ADD CONSTRAINT users_api_token_unique UNIQUE (api_token)');
            $db->statement('CREATE INDEX users_name_password_index ON users (name, password)');
            $db->statement('CREATE TABLE logins (id bigserial primary key, session_id varchar(255) references sessions (id), user_id bigint references users (id))');
            $db->statement('CREATE TABLE key_links (id bigserial primary key, owner_key varchar(255) references users (api_token))');
            $db->statement('CREATE TABLE "it\'s" (id bigint primary key)');
            $db->statement('CREATE TABLE events (id bigint, happened_on date) PARTITION BY RANGE (happened_on)');
            $db->statement("CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')");
            $db->statement('CREATE TABLE pfx_widgets (id bigserial primary key, name varchar(255), user_id bigint references users (id))');
            $db->statement('CREATE TABLE pfx_sessions (id bigserial primary key)');
            $db->statement('CREATE VIEW order_totals AS SELECT id, total FROM orders');

            if ($more !== null) {
                $more($db);
            }
        },
    );

    Gates::allow('db_schema');

    return $role;
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function pgsqlSchemaCall(array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_schema', $arguments, $era));
}

/** @return list<string> "schema.name" of each listed table */
function pgsqlSchemaNames(): array
{
    $names = [];

    foreach ((array) data_get(pgsqlSchemaCall(), 'data.tables') as $table) {
        $names[] = is_array($table) && is_string($table['schema'] ?? null) && is_string($table['name'] ?? null)
            ? $table['schema'].'.'.$table['name']
            : '[not a table]';
    }

    return $names;
}

const PGSQL_SCHEMA_READABLE = [
    'public.events', 'public.failed_jobs', "public.it's", 'public.key_links', 'public.logins', 'public.order_items',
    'public.orders', 'public.pfx_sessions', 'public.pfx_widgets', 'public.users',
];

it('DSP1 lists exactly the tables the role can read: no denied table, no partition, no ungranted view', function (ProtocolEra $era) {
    pgsqlSchemaRole();

    $envelope = pgsqlSchemaCall(era: $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope['status'])->toBe('ok')
        ->and(pgsqlSchemaNames())->toBe(PGSQL_SCHEMA_READABLE)
        ->and(data_get($envelope, 'data.tables.0'))->toBe(['schema' => 'public', 'name' => 'events', 'type' => 'table']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('DSP2 describes users: every column, the masked ones readable=false, with types, nullability and defaults', function () {
    pgsqlSchemaRole();

    $envelope = pgsqlSchemaCall(['table' => 'users']);

    expect($envelope['status'])->toBe('ok')
        ->and(data_get($envelope, 'data.table'))->toBe('users')
        ->and(data_get($envelope, 'data.schema'))->toBe('public')
        ->and(data_get($envelope, 'data.type'))->toBe('table')
        ->and(array_map(fn (mixed $c): array => [data_get($c, 'name'), data_get($c, 'readable')], (array) data_get($envelope, 'data.columns')))->toBe([
            ['id', true], ['name', true], ['email', true], ['password', false], ['remember_token', false],
            ['api_token', false], ['created_at', true], ['updated_at', true],
        ])
        ->and(data_get($envelope, 'data.columns.0'))->toBe([
            'name' => 'id', 'type' => 'bigint', 'nullable' => false,
            'default' => "nextval('users_id_seq'::regclass)", 'readable' => true,
        ])
        ->and(data_get($envelope, 'data.columns.6'))->toMatchArray(['name' => 'created_at', 'type' => 'timestamp(0) without time zone', 'nullable' => true, 'default' => null]);
});

it('DSP3 shows every index of users on PostgreSQL, also on a masked column: the columns are listed anyway', function () {
    pgsqlSchemaRole();

    expect(data_get(pgsqlSchemaCall(['table' => 'users']), 'data.indexes'))->toBe([
        ['name' => 'users_api_token_unique', 'columns' => ['api_token'], 'unique' => true, 'primary' => false],
        ['name' => 'users_email_unique', 'columns' => ['email'], 'unique' => true, 'primary' => false],
        ['name' => 'users_name_password_index', 'columns' => ['name', 'password'], 'unique' => false, 'primary' => false],
        ['name' => 'users_pkey', 'columns' => ['id'], 'unique' => true, 'primary' => true],
    ]);
});

it('DSP4 describes orders with its foreign key to users', function () {
    pgsqlSchemaRole();

    $envelope = pgsqlSchemaCall(['table' => 'orders']);

    expect(array_column((array) data_get($envelope, 'data.columns'), 'readable'))->each->toBeTrue()
        ->and(data_get($envelope, 'data.foreign_keys'))->toBe([[
            'name' => 'orders_user_id_foreign', 'columns' => ['user_id'], 'foreign_schema' => 'public',
            'foreign_table' => 'users', 'foreign_columns' => ['id'], 'on_update' => 'no action', 'on_delete' => 'no action',
        ]]);
});

it('DSP5 leaves out a foreign key to a denied table: no denied table name in the result', function () {
    pgsqlSchemaRole();

    $envelope = pgsqlSchemaCall(['table' => 'logins']);

    expect(array_column((array) data_get($envelope, 'data.foreign_keys'), 'foreign_table'))->toBe(['users'])
        ->and(str_contains((string) json_encode($envelope), 'sessions'))->toBeFalse();
});

it('DSP6 keeps a foreign key to a masked column on PostgreSQL: the column is listed with readable=false', function () {
    pgsqlSchemaRole();

    expect(data_get(pgsqlSchemaCall(['table' => 'key_links']), 'data.foreign_keys.0'))
        ->toMatchArray(['columns' => ['owner_key'], 'foreign_table' => 'users', 'foreign_columns' => ['api_token']]);
});

it('DSP7 gives one sentence for a missing, denied or unreadable table', function (string $table) {
    pgsqlSchemaRole();

    expect(pgsqlSchemaCall(['table' => $table]))->toMatchArray([
        'status' => 'unavailable',
        'note' => "Table `{$table}` does not exist, or the read-only role cannot read it.",
        'data' => null,
    ]);
})->with([
    'denied' => ['sessions'],
    'denied, qualified' => ['public.personal_access_tokens'],
    'missing' => ['nope'],
    'a partition' => ['events_2026'],
    'a view with no grant' => ['user_secrets'],
    'another case' => ['Users'],
    'a view with no grant, of a readable table' => ['order_totals'],
    'a system table every role can read' => ['pg_catalog.pg_class'],
    'a system view every role can read' => ['information_schema.tables'],
    'a missing schema' => ['audit.orders'],
]);

it('DSP8 accepts schema.name in an app schema', function () {
    pgsqlSchemaRole();

    expect(data_get(pgsqlSchemaCall(['table' => 'public.orders']), 'data.table'))->toBe('orders');
});

it('DSP9 readableColumns() gives the granted columns only, and [] for an unknown or denied table', function () {
    pgsqlSchemaRole();
    $schema = app(ReadableSchema::class);

    expect($schema->readableColumns('users'))->toBe(['id', 'name', 'email', 'created_at', 'updated_at'])
        ->and($schema->readableColumns('public.users'))->toBe(['id', 'name', 'email', 'created_at', 'updated_at'])
        ->and($schema->readableColumns('nope'))->toBe([])
        ->and($schema->readableColumns('sessions'))->toBe([])
        ->and($schema->readableColumns('events_2026'))->toBe([]);
});

it('DSP10 reads a table whose name holds a quote: the grammar gets an escaped name', function () {
    pgsqlSchemaRole();
    $schema = app(ReadableSchema::class);

    expect($schema->readableColumns("it's"))->toBe(['id'])
        ->and($schema->describe("it's")?->indexes)->toBe([['name' => "it's_pkey", 'columns' => ['id'], 'unique' => true, 'primary' => true]]);
});

it('DSP11 with a table prefix: lists and describes the real names, and denies a prefixed denied table', function () {
    pgsqlSchemaRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_']);

    $envelope = pgsqlSchemaCall(['table' => 'pfx_widgets']);

    expect(pgsqlSchemaNames())->toBe(array_values(array_diff(PGSQL_SCHEMA_READABLE, ['public.pfx_sessions'])))
        ->and(array_column((array) data_get($envelope, 'data.columns'), 'name'))->toBe(['id', 'name', 'user_id'])
        ->and(array_column((array) data_get($envelope, 'data.indexes'), 'name'))->toBe(['pfx_widgets_pkey'])
        ->and(array_column((array) data_get($envelope, 'data.foreign_keys'), 'foreign_table'))->toBe(['users'])
        ->and(pgsqlSchemaCall(['table' => 'pfx_sessions'])['status'])->toBe('unavailable');
});

it('DSP12 says what it searched when the role can read no table, also with a hand grant on a denied one', function () {
    $role = pgsqlSchemaRole();
    $db = DB::connection('testing_pgsql');
    $db->statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM '.$role->name());
    $db->statement('GRANT SELECT ON sessions TO '.$role->name());

    expect(pgsqlSchemaCall())->toMatchArray([
        'status' => 'empty',
        'note' => 'The read-only role can read no table in schema public.',
    ])->and(pgsqlSchemaCall(['table' => 'sessions'])['status'])->toBe('unavailable');
});

it('DSP18 lists and describes a view that someone granted by hand, as type view', function () {
    $role = pgsqlSchemaRole();
    DB::connection('testing_pgsql')->statement('GRANT SELECT ON order_totals TO '.$role->name());

    $view = pgsqlSchemaCall(['table' => 'order_totals']);

    expect(pgsqlSchemaNames())->toContain('public.order_totals')
        ->and(collect((array) data_get(pgsqlSchemaCall(), 'data.tables'))->firstWhere('name', 'order_totals'))
        ->toBe(['schema' => 'public', 'name' => 'order_totals', 'type' => 'view'])
        ->and(data_get($view, 'data.type'))->toBe('view')
        ->and(array_column((array) data_get($view, 'data.columns'), 'readable', 'name'))->toBe(['id' => true, 'total' => true]);
});

it('DSP19 looks a bare name up in the search_path order, and lists every app schema', function () {
    $role = pgsqlSchemaRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.search_path' => 'shop,public']);
    $db = DB::connection('testing_pgsql');

    try {
        $db->statement('CREATE SCHEMA shop');
        $db->statement('CREATE TABLE shop.orders (id bigint primary key, placed_on date)');
        $db->statement('GRANT USAGE ON SCHEMA shop TO '.$role->name());
        $db->statement('GRANT SELECT ON shop.orders TO '.$role->name());

        $first = pgsqlSchemaCall(['table' => 'orders']);
        $names = pgsqlSchemaNames();

        config()->set('database.connections.'.ReadOnlyRole::CONNECTION.'.search_path', 'public,shop');
        DB::purge(ReadOnlyRole::CONNECTION);
        $second = pgsqlSchemaCall(['table' => 'orders']);
    } finally {
        $db->statement('DROP SCHEMA IF EXISTS shop CASCADE');
    }

    expect(data_get($first, 'data.schema'))->toBe('shop')
        ->and(array_column((array) data_get($first, 'data.columns'), 'name'))->toBe(['id', 'placed_on'])
        ->and(array_values(array_filter($names, fn (string $name): bool => str_ends_with($name, '.orders'))))->toBe(['public.orders', 'shop.orders'])
        ->and(data_get($second, 'data.schema'))->toBe('public');
});

it('DSP20 says so when the search_path names only system schemas', function () {
    pgsqlSchemaRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.search_path' => 'pg_catalog']);

    expect(pgsqlSchemaCall())->toMatchArray(['status' => 'empty', 'note' => DbSchema::NO_SCHEMA])
        ->and(pgsqlSchemaCall(['table' => 'orders'])['note'])->toBe('Table `orders` does not exist, or the read-only role cannot read it.');
});

it('DSP21 lets the output cap drop whole tables from the end of the list, never cut a name', function () {
    pgsqlSchemaRole(['runtime-mcp.output.max_bytes' => 1_000], function (Illuminate\Database\Connection $db): void {
        foreach (range(1, 30) as $n) {
            $db->statement(sprintf('CREATE TABLE wide_list_%02d (id bigint)', $n));
        }
    });

    $envelope = pgsqlSchemaCall();
    $tables = (array) data_get($envelope, 'data.tables');

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBeString()->toContain('tables were omitted')
        ->and($envelope['truncated'])->toBe(40 - count($tables))
        ->and(array_column($tables, 'name'))->toBe(array_slice(['events', 'failed_jobs', "it's", 'key_links', 'logins', 'order_items', 'orders', 'pfx_sessions', 'pfx_widgets', 'users', ...array_map(fn (int $n): string => sprintf('wide_list_%02d', $n), range(1, 30))], 0, count($tables)));
});

it('DSP22 lets the output cap drop whole columns from the end, and keeps the indexes and foreign keys', function () {
    pgsqlSchemaRole(['runtime-mcp.output.max_bytes' => 1_000], function (Illuminate\Database\Connection $db): void {
        $columns = implode(', ', array_map(fn (int $n): string => sprintf('column_with_a_long_name_%02d text', $n), range(1, 40)));
        $db->statement("CREATE TABLE wide (id bigserial primary key, {$columns})");
    });

    $envelope = pgsqlSchemaCall(['table' => 'wide']);
    $columns = (array) data_get($envelope, 'data.columns');

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBeString()->toContain('columns were omitted')
        ->and($envelope['truncated'])->toBe(41 - count($columns))
        ->and(data_get($envelope, 'data.columns.0.name'))->toBe('id')
        ->and(data_get($envelope, 'data.indexes.0.name'))->toBe('wide_pkey');
});

it('DSP13 runs every catalog query on the read-only connection', function () {
    pgsqlSchemaRole();
    $direct = [];
    $all = [];

    DB::listen(function (QueryExecuted $query) use (&$direct): void {
        $direct[] = $query->connectionName;
    });

    $schema = app(ReadableSchema::class);
    $schema->tables();
    $schema->describe('orders');
    $schema->readableColumns('users');

    expect($direct)->not->toBeEmpty()
        ->and(array_unique($direct))->toBe([ReadOnlyRole::CONNECTION]);

    // Through the pipeline, 01-16 runs its own statements on the default connection.
    DB::listen(function (QueryExecuted $query) use (&$all): void {
        $all[] = [$query->connectionName, $query->sql];
    });
    pgsqlSchemaCall(['table' => 'orders']);
    $catalog = array_filter($all, fn (array $q): bool => str_contains($q[1], 'pg_') || str_contains($q[1], 'information_schema'));

    expect($catalog)->not->toBeEmpty()
        ->and(array_unique(array_column($catalog, 0)))->toBe([ReadOnlyRole::CONNECTION]);
});

it('DSP14 says the statement timeout stopped it, when a lock holds the table', function () {
    pgsqlSchemaRole();
    $privileged = DB::connection('testing_pgsql');
    $privileged->beginTransaction();

    try {
        // pg_get_expr() opens the table to read a column default, so it waits for the lock.
        $privileged->statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
        $envelope = pgsqlSchemaCall(['table' => 'users']);
    } finally {
        $privileged->rollBack();
    }

    expect($envelope)->toMatchArray([
        'status' => 'unavailable',
        'note' => 'Reading the schema was stopped by the 500 ms statement timeout.',
    ]);
});

it('DSP15 shows a fixed package note when the connection cannot be opened, or is persistent', function (string $case) {
    $key = 'database.connections.'.ReadOnlyRole::CONNECTION;
    pgsqlSchemaRole($case === 'persistent' ? [$key.'.options' => [PDO::ATTR_PERSISTENT => true]] : [$key.'.password' => 'wrong']);

    expect(pgsqlSchemaCall())->toMatchArray([
        'status' => 'unavailable',
        'note' => $case === 'persistent' ? ReadOnlySession::PERSISTENT : ReadOnlySession::CONNECTION_FAILED,
    ]);
})->with(['a wrong password', 'persistent']);

it('DSP16 reports any other database error and shows only its SQLSTATE', function () {
    pgsqlSchemaRole();
    Exceptions::fake();

    // execute() directly: the input rules refuse this name before the database sees it.
    $result = app(DbSchema::class)->execute(new ToolInput(['table' => "bad\xff"]));

    expect($result->status->value)->toBe('unavailable')
        ->and($result->note)->toBe('The database refused the schema read (SQLSTATE 22021).');

    Exceptions::assertReported(QueryFailed::class);
    Exceptions::assertReportedCount(1);
});

it('DSP17 writes one audit entry with the table and no result data', function () {
    pgsqlSchemaRole();

    pgsqlSchemaCall(['table' => 'users']);
    $line = implode("\n", AuditLog::lines());

    expect(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray([
            'tool' => 'db_schema', 'params' => ['table' => 'users'], 'decision' => 'allowed', 'outcome' => 'success',
        ])
        ->and(str_contains($line, 'remember_token'))->toBeFalse();
});
