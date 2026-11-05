<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

/*
 * Spec 5.1, 5.2, 6.4, 11.2 ("Envelope"), 01-20: db_query on MySQL and MariaDB, as 01-11's read-only
 * user, over HTTP through the whole pipeline with a Gate that allows the tool. testing_mysql is the
 * privileged connection; CI runs this file on MySQL 8.4, MariaDB 11.4 and 10.11. The runner never runs
 * a statement bare here (01-15): what cannot be wrapped is QueryNotLimitable. A missing table is 1142
 * and a missing column 1143, as for a denied one. MariaDB names a 1142 table with its database.
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/**
 * The fixture schema of 01-11 (user 1, order 1) with order 1234, plus this story's tables, made with
 * the schema builder (the connection's collation). They exist before the user, so 01-11 grants them
 * (masked columns excepted). The function gets no EXECUTE grant.
 *
 * @param  array<string, mixed>  $config
 */
function mysqlQueryRole(array $config = []): ReadOnlyRole
{
    $role = ReadOnlyRole::setUp(
        currentTest(),
        'testing_mysql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'runtime-mcp.database.statement_timeout_ms' => 500, ...$config],
        extend: function (string $privileged): void {
            $db = DB::connection($privileged);
            $schema = Schema::connection($privileged);
            $db->table('orders')->insert([
                'id' => 1234, 'user_id' => 1, 'number' => 'SO-1234', 'total' => '99.50', 'status' => 'paid',
                'created_at' => '2026-10-01 08:12:44',
            ]);
            $schema->create('moments', function (Blueprint $table): void {
                $table->id();
                $table->dateTime('at_datetime');
                $table->timestamp('at_timestamp')->nullable();
                $table->dateTime('at_fraction', 6);
                $table->date('on_day');
                $table->time('at_time');
                $table->year('in_year');
                $table->dateTime('zero')->nullable();
            });
            // A stored zero date is real data on MariaDB (its default sql_mode allows it) and on old MySQL tables.
            $mode = $db->scalar('SELECT @@SESSION.sql_mode');
            $db->statement("SET SESSION sql_mode = ''");
            $db->statement("INSERT INTO moments VALUES (1, '2026-10-01 09:12:44', '2026-10-01 09:12:44', '2026-10-01 09:12:44.123456', '2026-10-01', '09:12:44', 2026, '0000-00-00 00:00:00')");
            $db->statement('SET SESSION sql_mode = ?', [is_string($mode) ? $mode : '']);
            $schema->create('numbers', function (Blueprint $table): void {
                $table->double('d');
                $table->float('f');
                $table->decimal('m', 5, 2);
            });
            $db->table('numbers')->insert(['d' => 1.5, 'f' => 1.5, 'm' => '1.50']);
            $schema->create('big_set', fn (Blueprint $table) => $table->id());
            foreach (array_chunk(range(1, 600), 200) as $ids) {
                $db->table('big_set')->insert(array_map(fn (int $id): array => ['id' => $id], $ids));
            }
            $schema->create('pfx_items', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('api_key')->nullable();
            });
            $db->statement('DROP FUNCTION IF EXISTS rmcp_answer');
            $db->statement('CREATE FUNCTION rmcp_answer() RETURNS INT DETERMINISTIC NO SQL RETURN 42');
        },
    );

    Gates::allow('db_query');

    return $role;
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function mysqlQuery(string $sql, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_query', ['sql' => $sql, ...$arguments], $era));
}

function mysqlQueryIsMaria(): bool
{
    $connection = DB::connection('testing_mysql');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

/** A 1142 table as the note shows it: MariaDB gives the database too. */
function mysqlQueryTable(string $table): string
{
    return mysqlQueryIsMaria() ? DB::connection('testing_mysql')->getDatabaseName().'.'.$table : $table;
}

function mysqlQueryOrders(): mixed
{
    return DB::connection('testing_mysql')->scalar('SELECT count(*) FROM orders');
}

/** The note of a call, '' when it has none. */
function mysqlQueryNote(string $sql): string
{
    $note = mysqlQuery($sql)['note'] ?? null;

    return is_string($note) ? $note : '';
}

it('DQM1 answers a SELECT with columns, rows in column order, row_count and truncated', function (ProtocolEra $era) {
    mysqlQueryRole();

    $envelope = mysqlQuery('SELECT id, number, total, created_at FROM orders WHERE id = 1234', era: $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope)->toBe([
            'environment' => 'testing',
            'status' => 'ok',
            'note' => null,
            'truncated' => false,
            'data' => [
                'columns' => ['id', 'number', 'total', 'created_at'],
                'rows' => [[1234, 'SO-1234', '99.50', '2026-10-01T08:12:44+00:00']],
                'row_count' => 1,
                'truncated' => false,
            ],
        ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('DQM2 is empty with the columns when the query ran and matched no row', function () {
    mysqlQueryRole();

    expect(mysqlQuery('SELECT * FROM orders WHERE id = -1'))->toMatchArray([
        'status' => 'empty',
        'note' => DbQuery::NO_ROWS,
        'data' => ['columns' => ['id', 'user_id', 'number', 'total', 'status', 'created_at'], 'rows' => [], 'row_count' => 0, 'truncated' => false],
    ]);
});

it('DQM3 is partial at max_rows, and ok when exactly max_rows rows match', function () {
    mysqlQueryRole();

    $all = mysqlQuery('SELECT id FROM big_set');
    $twenty = mysqlQuery('SELECT id FROM big_set', ['max_rows' => 20]);
    $exact = mysqlQuery('SELECT id FROM big_set WHERE id <= 500');

    // orderMayBeLost is true on every MariaDB result (01-15), so every MariaDB partial note warns.
    expect($all)->toMatchArray(['status' => 'partial', 'note' => sprintf(DbQuery::MORE_ROWS, 500).(mysqlQueryIsMaria() ? ' '.DbQuery::MARIADB_ORDER : ''), 'truncated' => false])
        ->and(data_get($all, 'data.rows'))->toHaveCount(500)
        ->and(data_get($all, 'data.truncated'))->toBeTrue()
        ->and(data_get($twenty, 'data.row_count'))->toBe(20)
        ->and($twenty['note'])->toStartWith('More than 20 rows match')
        ->and($exact)->toMatchArray(['status' => 'ok', 'note' => null])
        ->and(data_get($exact, 'data.row_count'))->toBe(500);
});

it('DQM4 warns about the lost order on MariaDB in a partial note only: an ok result has no note', function () {
    mysqlQueryRole();

    $partial = mysqlQuery('SELECT id FROM big_set ORDER BY id DESC');
    $ok = mysqlQuery('SELECT id FROM big_set WHERE id <= 3 ORDER BY id DESC');
    $limited = mysqlQuery('SELECT id FROM big_set WHERE id <= 3 ORDER BY id DESC LIMIT 3');

    expect($partial['note'])->toBe(sprintf(DbQuery::MORE_ROWS, 500).(mysqlQueryIsMaria() ? ' '.DbQuery::MARIADB_ORDER : ''))
        ->and($ok)->toMatchArray(['status' => 'ok', 'note' => null])
        ->and(data_get($limited, 'data.rows'))->toBe([[3], [2], [1]]);

    if (! mysqlQueryIsMaria()) {
        expect(data_get($ok, 'data.rows'))->toBe([[3], [2], [1]]);
    }
});

it('DQM5 names the statement timeout when it stops the query', function () {
    mysqlQueryRole();
    $start = microtime(true);

    expect(mysqlQuery('SELECT SLEEP(10)'))->toMatchArray([
        'status' => 'unavailable',
        'note' => 'The query was stopped by the 500 ms statement timeout. Narrow it (a WHERE on an indexed column, a LIMIT) and try again.',
    ])->and(microtime(true) - $start)->toBeLessThan(3.0);
});

it('DQM6 turns a permission error into the readable columns, or a sentence that names no secret', function (string $sql, string $case, string $table, string $columns) {
    mysqlQueryRole();

    $name = $case === '1142' ? mysqlQueryTable($table) : $table;
    $note = match (true) {
        $case === 'denied' => DbQuery::PERMISSION_DENIED,
        $columns === '' => "Table `{$name}` does not exist, or the read-only role cannot read it.",
        default => "Table `{$name}` has columns that you cannot read. Select columns by name. Readable columns: {$columns}.",
    };

    expect(mysqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with([
    'SELECT * on a table with masked columns (1142)' => ['SELECT * FROM users', '1142', 'users', 'id, name, email, created_at, updated_at'],
    'a masked column by name (1143)' => ['SELECT id, password FROM users', '1143', 'users', 'id, name, email, created_at, updated_at'],
    'a table of this story (1142)' => ['SELECT * FROM pfx_items', '1142', 'pfx_items', 'id, name'],
    'a denied table' => ['SELECT * FROM sessions', '1142', 'sessions', ''],
    'a missing table (spec 11.2 Envelope)' => ['SELECT * FROM nope', '1142', 'nope', ''],
    'a view with no grant' => ['SELECT * FROM user_secrets', '1142', 'user_secrets', ''],
    'a function the user may not run (1370)' => ['SELECT rmcp_answer()', 'denied', '', ''],
]);

it('DQM7 names a table of another database as the server gives it', function () {
    mysqlQueryRole();

    expect(mysqlQuery('SELECT * FROM mysql.user')['note'])
        ->toBe('Table `'.(mysqlQueryIsMaria() ? 'mysql.user' : 'user').'` does not exist, or the read-only role cannot read it.');
});

it('DQM8 reads the real table name with a table prefix: no second prefix', function () {
    mysqlQueryRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_']);

    expect(mysqlQuery('SELECT * FROM pfx_items')['note'])
        ->toBe('Table `'.mysqlQueryTable('pfx_items').'` has columns that you cannot read. Select columns by name. Readable columns: id, name.');
});

it('DQM9 refuses a write as not limitable, with the database message, and nothing is changed', function (string $sql) {
    mysqlQueryRole();
    $database = mysqlQueryIsMaria() ? 'MariaDB' : 'MySQL';

    $note = mysqlQueryNote($sql);

    expect($note)->toStartWith("{$database} could not run this query inside the row limit, so it was not run. On {$database}, db_query runs SELECT-style queries only (no EXPLAIN or SHOW).")
        ->and($note)->toContain('The database said: You have an error in your SQL syntax;')
        ->and(mysqlQueryOrders())->toBe(2)
        ->and(DB::connection('testing_mysql')->scalar('SELECT status FROM orders WHERE id = 1234'))->toBe('paid');
})->with([
    'DELETE' => ['DELETE FROM orders'],
    'UPDATE' => ["UPDATE orders SET status = 'refunded'"],
    'INSERT' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'SO-1', 1, 'x')"],
]);

it('DQM10 shows a typo and a duplicate column name through the 01-15 note, with the database message', function () {
    mysqlQueryRole();

    $typo = mysqlQueryNote('SELEC 1');
    $duplicate = mysqlQueryNote('SELECT o.id, u.id FROM orders o JOIN users u ON u.id = o.user_id');
    $said = mb_substr($typo, (int) mb_strpos($typo, DbQuery::DATABASE_SAID) + mb_strlen(DbQuery::DATABASE_SAID));

    expect($said)->toStartWith('You have an error in your SQL syntax;')
        ->and(mb_strlen($said))->toBeLessThanOrEqual(DbQuery::MESSAGE_CHARACTERS)
        ->and($duplicate)->toContain('give each a unique alias, for example `orders.id AS order_id`.')
        ->and($duplicate)->toEndWith("The database said: Duplicate column name 'id'");
});

it('DQM11 says the query is empty for a lone semicolon, before the database', function () {
    mysqlQueryRole();

    expect(mysqlQuery(';'))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::EMPTY_QUERY]);
});

it('DQM12 keeps numbers as the database gives them: DOUBLE and FLOAT as numbers, DECIMAL as a string', function () {
    mysqlQueryRole();

    expect(data_get(mysqlQuery('SELECT d, f, m FROM numbers'), 'data.rows'))->toBe([[1.5, 1.5, '1.50']]);
});

it('DQM13 gives DATETIME and TIMESTAMP in ISO 8601 with the app offset and the fraction, and keeps a zero date', function () {
    mysqlQueryRole();
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/London');

    try {
        $envelope = mysqlQuery('SELECT * FROM moments');
    } finally {
        date_default_timezone_set($previous);
    }

    expect(data_get($envelope, 'data.rows.0'))->toBe([
        1, '2026-10-01T09:12:44+01:00', '2026-10-01T09:12:44+01:00', '2026-10-01T09:12:44.123456+01:00',
        '2026-10-01', '09:12:44', '2026', '0000-00-00 00:00:00',
    ]);
});

it('DQM14 runs as the read-only user on the read-only connection', function () {
    $role = mysqlQueryRole();

    expect(data_get(mysqlQuery("SELECT SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AS who"), 'data.rows'))->toBe([[$role->name()]]);
});

it('DQM15 writes one audit entry with the redacted SQL, the row count and the size, and no result data', function () {
    mysqlQueryRole();
    $jwt = SecretTool::JWT;

    $envelope = mysqlQuery("SELECT id, number, '{$jwt}' AS token FROM orders WHERE id = 1234");
    $lines = implode("\n", AuditLog::lines());

    expect(data_get($envelope, 'data.rows.0'))->toBe([1234, 'SO-1234', '[redacted:jwt]'])
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray([
            'tool' => 'db_query',
            'params' => ['sql' => "SELECT id, number, '[redacted:jwt]' AS token FROM orders WHERE id = 1234"],
            'outcome' => 'success',
            'row_count' => 1,
        ])
        ->and(str_contains($lines, 'SO-1234'))->toBeFalse();
});

it('DQM16 shows a fixed package note when the connection cannot be opened', function () {
    mysqlQueryRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.password' => 'wrong']);

    expect(mysqlQuery('SELECT 1'))->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::CONNECTION_FAILED]);
});

it('DQM17 still answers when the readable columns cannot be read: the note names no table', function () {
    mysqlQueryRole(['runtime-mcp.database.denied_tables' => 'sessions']);

    expect(mysqlQuery('SELECT * FROM users'))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::PERMISSION_DENIED]);
});

it('DQM18 states the MySQL line in tools/list, and the MariaDB line for the mariadb driver', function () {
    mysqlQueryRole();

    $description = data_get(
        collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))->firstWhere('name', 'db_query'),
        'description',
    );

    expect($description)->toBeString()->toContain(DbQuery::MYSQL_LINE)
        ->and(is_string($description) && str_contains($description, DbQuery::MARIADB_LINE))->toBe(config('database.connections.testing_mysql.driver') === 'mariadb');
});

it('DQM19 answers a typo in a column name: MariaDB says 1143 (the readable columns), MySQL 1054 through the wrap', function () {
    mysqlQueryRole();

    expect(mysqlQuery('SELECT nme FROM users')['note'])->toBe(mysqlQueryIsMaria()
        ? 'Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.'
        : "The database rejected the query (SQLSTATE 42S22): Unknown column 'nme' in 'field list'.");
});

it('DQM20 names no columns when a readable app table only shares the name: another database, or FOR UPDATE', function () {
    // The 01-11 fixture plus a readable table `user`, so MySQL's bare `user` of mysql.user finds it.
    ReadOnlyRole::setUp(
        currentTest(),
        'testing_mysql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array'],
        extend: function (string $privileged): void {
            Schema::connection($privileged)->create('user', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        },
    );
    Gates::allow('db_query');
    $maria = mysqlQueryIsMaria();

    expect(mysqlQueryNote('SELECT * FROM mysql.user'))
        ->toBe($maria ? 'Table `mysql.user` does not exist, or the read-only role cannot read it.' : DbQuery::PERMISSION_DENIED)
        ->and(mysqlQueryNote('SELECT id FROM orders FOR UPDATE'))->toBe($maria ? DbQuery::WROTE : DbQuery::PERMISSION_DENIED)
        ->and(mysqlQueryNote('SELECT * FROM users'))->toBe('Table `'.mysqlQueryTable('users').'` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.');
});
