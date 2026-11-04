<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ReadableSchema;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 6.3, 11.2 ("Envelope"), 01-19: db_schema and ReadableSchema on MySQL and MariaDB, as 01-11's
 * read-only user, over HTTP with a Gate that allows the tool. testing_mysql is the privileged connection;
 * CI runs this file on MySQL 8.4, MariaDB 11.4 and 10.11. The server lists only the columns the user can
 * read; MySQL still lists an index on a hidden column (the tool drops it), and MariaDB shows no
 * REFERENTIAL_CONSTRAINTS row (the tool reads KEY_COLUMN_USAGE).
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function mysqlSchemaRole(array $config = []): ReadOnlyRole
{
    $role = ReadOnlyRole::setUp(
        currentTest(),
        'testing_mysql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'runtime-mcp.database.statement_timeout_ms' => 500, ...$config],
        extend: function (string $privileged): void {
            // The schema builder, so each table gets the connection's collation: a foreign key between two
            // collations fails with 3780.
            $db = DB::connection($privileged);
            $schema = Schema::connection($privileged);
            $db->statement('ALTER TABLE users ADD CONSTRAINT users_api_token_unique UNIQUE (api_token)');
            $db->statement('CREATE INDEX users_name_password_index ON users (name, password)');
            $schema->create('logins', function (Blueprint $table): void {
                $table->id();
                $table->string('session_id')->nullable();
                $table->foreign('session_id')->references('id')->on('sessions');
                $table->foreignId('user_id')->nullable()->constrained('users');
            });
            $schema->create('key_links', function (Blueprint $table): void {
                $table->id();
                $table->string('owner_key')->nullable();
                $table->foreign('owner_key')->references('api_token')->on('users');
            });
            $schema->create("it's", fn (Blueprint $table) => $table->id());
            $schema->create('pfx_widgets', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->foreignId('user_id')->constrained('users');
            });
            $schema->create('pfx_sessions', fn (Blueprint $table) => $table->id());
            $db->statement('CREATE VIEW order_totals AS SELECT id, total FROM orders');
        },
    );

    Gates::allow('db_schema');

    return $role;
}

function mysqlSchemaIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

function mysqlSchemaDatabase(): string
{
    return DB::connection('testing_mysql')->getDatabaseName();
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function mysqlSchemaCall(array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_schema', $arguments, $era));
}

/** @return list<string> the name of each listed table */
function mysqlSchemaNames(): array
{
    $names = [];

    foreach ((array) data_get(mysqlSchemaCall(), 'data.tables') as $table) {
        $names[] = is_array($table) && is_string($table['name'] ?? null) ? $table['name'] : '[not a table]';
    }

    return $names;
}

const MYSQL_SCHEMA_READABLE = [
    'failed_jobs', "it's", 'key_links', 'logins', 'order_items', 'orders', 'pfx_sessions', 'pfx_widgets', 'users',
];

it('DSM1 lists exactly the tables the user can read: no denied table, no ungranted view', function (ProtocolEra $era) {
    mysqlSchemaRole();

    $envelope = mysqlSchemaCall(era: $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope['status'])->toBe('ok')
        ->and(mysqlSchemaNames())->toBe(MYSQL_SCHEMA_READABLE)
        ->and(data_get($envelope, 'data.tables.0'))->toBe(['schema' => mysqlSchemaDatabase(), 'name' => 'failed_jobs', 'type' => 'table']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('DSM2 describes users with only the readable columns, all readable=true', function () {
    mysqlSchemaRole();

    $envelope = mysqlSchemaCall(['table' => 'users']);

    expect($envelope['status'])->toBe('ok')
        ->and(data_get($envelope, 'data.schema'))->toBe(mysqlSchemaDatabase())
        ->and(array_map(fn (mixed $c): array => [data_get($c, 'name'), data_get($c, 'readable')], (array) data_get($envelope, 'data.columns')))->toBe([
            ['id', true], ['name', true], ['email', true], ['created_at', true], ['updated_at', true],
        ])
        ->and(data_get($envelope, 'data.columns.0'))->toMatchArray([
            // The server's own column type: MariaDB still prints the display width.
            'name' => 'id', 'type' => mysqlSchemaIsMaria() ? 'bigint(20) unsigned' : 'bigint unsigned', 'nullable' => false, 'default' => null,
        ]);
});

it('DSM3 leaves out every index on a hidden column: no masked column name anywhere in the result', function () {
    mysqlSchemaRole();

    $envelope = mysqlSchemaCall(['table' => 'users']);
    $json = (string) json_encode($envelope);

    expect(data_get($envelope, 'data.indexes'))->toBe([
        ['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true],
        ['name' => 'users_email_unique', 'columns' => ['email'], 'unique' => true, 'primary' => false],
    ]);

    foreach (['password', 'remember_token', 'api_token'] as $hidden) {
        expect(str_contains($json, $hidden))->toBeFalse("[{$hidden}] is in the result");
    }
});

it('DSM4 describes orders with its foreign key to users (MariaDB: no ON UPDATE / ON DELETE rule)', function () {
    mysqlSchemaRole();
    $rule = mysqlSchemaIsMaria() ? null : 'no action';

    expect(data_get(mysqlSchemaCall(['table' => 'orders']), 'data.foreign_keys'))->toBe([[
        'name' => 'orders_user_id_foreign', 'columns' => ['user_id'], 'foreign_schema' => mysqlSchemaDatabase(),
        'foreign_table' => 'users', 'foreign_columns' => ['id'], 'on_update' => $rule, 'on_delete' => $rule,
    ]]);
});

it('DSM5 leaves out a foreign key to a denied table, and one to a hidden column', function () {
    mysqlSchemaRole();

    $logins = mysqlSchemaCall(['table' => 'logins']);
    $links = mysqlSchemaCall(['table' => 'key_links']);

    expect(array_column((array) data_get($logins, 'data.foreign_keys'), 'foreign_table'))->toBe(['users'])
        ->and(str_contains((string) json_encode($logins), 'sessions'))->toBeFalse()
        ->and(data_get($links, 'data.foreign_keys'))->toBe([])
        ->and(str_contains((string) json_encode($links), 'api_token'))->toBeFalse();
});

it('DSM6 gives one sentence for a missing, denied or unreadable table', function (string $table) {
    mysqlSchemaRole();

    expect(mysqlSchemaCall(['table' => $table]))->toMatchArray([
        'status' => 'unavailable',
        'note' => "Table `{$table}` does not exist, or the read-only role cannot read it.",
        'data' => null,
    ]);
})->with([
    'denied' => ['sessions'],
    'missing' => ['nope'],
    'a view with no grant' => ['user_secrets'],
    'a view with no grant, of a readable table' => ['order_totals'],
    'a system view every user can read' => ['information_schema.tables'],
    'no schema public here' => ['public.orders'],
    'another database' => ['mysql.user'],
]);

it('DSM7 accepts database.name, and readableColumns() gives the granted columns only', function () {
    mysqlSchemaRole();
    $schema = app(ReadableSchema::class);

    expect(data_get(mysqlSchemaCall(['table' => mysqlSchemaDatabase().'.orders']), 'data.table'))->toBe('orders')
        ->and($schema->readableColumns('users'))->toBe(['id', 'name', 'email', 'created_at', 'updated_at'])
        ->and($schema->readableColumns(mysqlSchemaDatabase().'.users'))->toBe(['id', 'name', 'email', 'created_at', 'updated_at'])
        ->and($schema->readableColumns('nope'))->toBe([])
        ->and($schema->readableColumns('sessions'))->toBe([])
        ->and($schema->readableColumns("it's"))->toBe(['id']);
});

it('DSM8 with a table prefix: lists and describes the real names, and denies a prefixed denied table', function () {
    mysqlSchemaRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_']);

    $envelope = mysqlSchemaCall(['table' => 'pfx_widgets']);

    expect(mysqlSchemaNames())->toBe(array_values(array_diff(MYSQL_SCHEMA_READABLE, ['pfx_sessions'])))
        ->and(array_column((array) data_get($envelope, 'data.columns'), 'name'))->toBe(['id', 'name', 'user_id'])
        ->and(array_column((array) data_get($envelope, 'data.foreign_keys'), 'foreign_table'))->toBe(['users'])
        ->and(mysqlSchemaCall(['table' => 'pfx_sessions'])['status'])->toBe('unavailable');
});

it('DSM9 says what it searched when the user can read no table, also with a hand grant on a denied one', function () {
    $role = mysqlSchemaRole();
    $db = DB::connection('testing_mysql');
    $db->statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$role->name()}'@'%'");
    // With no privilege in the database, MySQL refuses the connection (1044), so keep one grant.
    $db->statement("GRANT SELECT ON `{$db->getDatabaseName()}`.`sessions` TO '{$role->name()}'@'%'");

    expect(mysqlSchemaCall())->toMatchArray([
        'status' => 'empty',
        'note' => 'The read-only role can read no table in schema '.mysqlSchemaDatabase().'.',
    ])->and(mysqlSchemaCall(['table' => 'sessions'])['status'])->toBe('unavailable');
});

it('DSM12 lists and describes a view that someone granted by hand, as type view', function () {
    $role = mysqlSchemaRole();
    $db = DB::connection('testing_mysql');
    $db->statement("GRANT SELECT ON `{$db->getDatabaseName()}`.`order_totals` TO '{$role->name()}'@'%'");

    $view = mysqlSchemaCall(['table' => 'order_totals']);

    expect(collect((array) data_get(mysqlSchemaCall(), 'data.tables'))->firstWhere('name', 'order_totals'))
        ->toBe(['schema' => mysqlSchemaDatabase(), 'name' => 'order_totals', 'type' => 'view'])
        ->and(data_get($view, 'data.type'))->toBe('view')
        ->and(array_column((array) data_get($view, 'data.columns'), 'name'))->toBe(['id', 'total']);
});

it('DSM10 runs every catalog query on the read-only connection', function () {
    mysqlSchemaRole();
    $connections = [];

    DB::listen(function (QueryExecuted $query) use (&$connections): void {
        $connections[] = $query->connectionName;
    });

    $schema = app(ReadableSchema::class);
    $schema->tables();
    $schema->describe('logins');
    $schema->readableColumns('users');

    expect($connections)->not->toBeEmpty()
        ->and(array_unique($connections))->toBe([ReadOnlyRole::CONNECTION]);
});

it('DSM11 shows a fixed package note when the connection cannot be opened, or is persistent', function (string $case) {
    $key = 'database.connections.'.ReadOnlyRole::CONNECTION;
    mysqlSchemaRole($case === 'persistent' ? [$key.'.options' => [PDO::ATTR_PERSISTENT => true]] : [$key.'.password' => 'wrong']);

    expect(mysqlSchemaCall())->toMatchArray([
        'status' => 'unavailable',
        'note' => $case === 'persistent' ? ReadOnlySession::PERSISTENT : ReadOnlySession::CONNECTION_FAILED,
    ]);
})->with(['a wrong password', 'persistent']);

it('DSM13 leaves out an index with an expression key part: no masked column name anywhere (MySQL)', function () {
    if (mysqlSchemaIsMaria()) {
        Assert::markTestSkipped('MariaDB has no expression key part.');
    }

    ReadOnlyRole::setUp(
        currentTest(),
        'testing_mysql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array'],
        extend: function (string $privileged): void {
            // STATISTICS gives a NULL column_name for an expression key part, so Laravel's group_concat
            // leaves it out: [] for the first index, ['name'] for the second.
            $db = DB::connection($privileged);
            $db->statement('CREATE INDEX users_api_token_lower_index ON users ((lower(api_token)))');
            $db->statement('CREATE INDEX users_name_lower_password_index ON users (name, (lower(password)))');
        },
    );
    Gates::allow('db_schema');

    $envelope = mysqlSchemaCall(['table' => 'users']);
    $json = (string) json_encode($envelope);

    expect(data_get($envelope, 'data.indexes'))->toBe([
        ['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true],
        ['name' => 'users_email_unique', 'columns' => ['email'], 'unique' => true, 'primary' => false],
    ]);

    foreach (['password', 'api_token'] as $hidden) {
        expect(str_contains($json, $hidden))->toBeFalse("[{$hidden}] is in the result");
    }
});
