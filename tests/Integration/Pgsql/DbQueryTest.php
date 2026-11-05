<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
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
 * Spec 5.1, 5.2, 6.4, 11.2 ("Envelope"), 01-20: db_query on PostgreSQL, as 01-11's read-only role,
 * over HTTP through the whole pipeline with a Gate that allows the tool. testing_pgsql is the
 * privileged connection. Every refusal is an unavailable result with a note; empty only when the
 * query ran and found no row.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/**
 * The fixture schema of 01-11 (user 1, order 1) with order 1234, plus this story's objects. They exist before the role,
 * so 01-11 grants the tables (masked columns excepted); the materialized view gets no grant.
 *
 * @param  array<string, mixed>  $config
 */
function pgsqlQueryRole(array $config = []): ReadOnlyRole
{
    $role = ReadOnlyRole::setUp(
        currentTest(),
        'testing_pgsql',
        ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'runtime-mcp.database.statement_timeout_ms' => 500, ...$config],
        extend: function (string $privileged): void {
            $db = DB::connection($privileged);
            $db->table('orders')->insert([
                'id' => 1234, 'user_id' => 1, 'number' => 'SO-1234', 'total' => '99.50', 'status' => 'paid',
                'created_at' => '2026-10-01 08:12:44',
            ]);
            $db->statement('CREATE TABLE moments (id bigint primary key, at_tz timestamptz, at_naive timestamp, at_fraction timestamptz, on_day date, at_time time, at_timetz timetz, span interval, forever timestamptz, ancient timestamp)');
            $db->statement("INSERT INTO moments VALUES (1, '2026-10-01 08:12:44+00', '2026-10-01 09:12:44', '2026-10-01 08:12:44.123456+00', '2026-10-01', '09:12:44', '09:12:44+02', '1 day', 'infinity', '0044-03-15 12:00:00 BC')");
            $db->statement('CREATE TABLE pfx_items (id bigserial primary key, name varchar(255), api_key varchar(255))');
            $db->statement('CREATE MATERIALIZED VIEW order_summary AS SELECT count(*) AS orders FROM orders');
        },
    );

    Gates::allow('db_query');

    return $role;
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function pgsqlQuery(string $sql, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_query', ['sql' => $sql, ...$arguments], $era));
}

function pgsqlQueryOrders(): mixed
{
    return DB::connection('testing_pgsql')->scalar('SELECT count(*) FROM orders');
}

it('DQP1 answers a SELECT with columns, rows in column order, row_count and truncated', function (ProtocolEra $era) {
    pgsqlQueryRole();

    $envelope = pgsqlQuery('SELECT id, number, total, created_at FROM orders WHERE id = 1234', era: $era);

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

it('DQP2 is empty with the columns when the query ran and matched no row', function () {
    pgsqlQueryRole();

    expect(pgsqlQuery('SELECT * FROM orders WHERE id = -1'))->toMatchArray([
        'status' => 'empty',
        'note' => DbQuery::NO_ROWS,
        'truncated' => false,
        'data' => ['columns' => ['id', 'user_id', 'number', 'total', 'status', 'created_at'], 'rows' => [], 'row_count' => 0, 'truncated' => false],
    ]);
});

it('DQP3 is partial at max_rows: data.truncated true, the envelope truncated false, a note with the limit', function () {
    pgsqlQueryRole();

    $all = pgsqlQuery('SELECT n FROM generate_series(1, 2000) AS g(n)');
    $twenty = pgsqlQuery('SELECT n FROM generate_series(1, 2000) AS g(n)', ['max_rows' => 20]);

    expect($all)->toMatchArray(['status' => 'partial', 'note' => sprintf(DbQuery::MORE_ROWS, 500), 'truncated' => false])
        ->and(data_get($all, 'data.rows'))->toHaveCount(500)
        ->and(data_get($all, 'data.rows.499'))->toBe([500])
        ->and(data_get($all, 'data.row_count'))->toBe(500)
        ->and(data_get($all, 'data.truncated'))->toBeTrue()
        ->and($twenty)->toMatchArray(['status' => 'partial', 'note' => 'More than 20 rows match; showing the first 20. Add a WHERE clause or a LIMIT to narrow the query.'])
        ->and(data_get($twenty, 'data.row_count'))->toBe(20);
});

it('DQP4 is ok, not partial, when exactly max_rows rows match', function () {
    pgsqlQueryRole();

    $exact = pgsqlQuery('SELECT n FROM generate_series(1, 500) AS g(n)');
    $few = pgsqlQuery('SELECT n FROM generate_series(1, 5) AS g(n)', ['max_rows' => 20]);

    expect($exact)->toMatchArray(['status' => 'ok', 'note' => null])
        ->and(data_get($exact, 'data.row_count'))->toBe(500)
        ->and(data_get($exact, 'data.truncated'))->toBeFalse()
        ->and(data_get($few, 'data.rows'))->toBe([[1], [2], [3], [4], [5]]);
});

it('DQP5 names the statement timeout when it stops the query', function () {
    pgsqlQueryRole();
    $start = microtime(true);

    $envelope = pgsqlQuery('SELECT pg_sleep(10)');

    expect($envelope)->toMatchArray([
        'status' => 'unavailable',
        'note' => 'The query was stopped by the 500 ms statement timeout. Narrow it (a WHERE on an indexed column, a LIMIT) and try again.',
        'data' => null,
    ])->and(microtime(true) - $start)->toBeLessThan(3.0);
});

it('DQP6 turns a permission error into the readable columns, or a sentence that names no secret', function (string $sql, string $note) {
    pgsqlQueryRole();

    expect(pgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with([
    'SELECT * on a table with masked columns' => ['SELECT * FROM users', 'Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.'],
    'a masked column by name' => ['SELECT id, password FROM users', 'Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.'],
    'a masked column in a function' => ['SELECT row_to_json(u) FROM users u', 'Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.'],
    'a table of this story' => ['SELECT * FROM pfx_items', 'Table `pfx_items` has columns that you cannot read. Select columns by name. Readable columns: id, name.'],
    'a denied table' => ['SELECT * FROM sessions', 'Table `sessions` does not exist, or the read-only role cannot read it.'],
    'a denied table in a join' => ['SELECT o.id FROM orders o JOIN sessions s ON s.user_id = o.user_id', 'Table `sessions` does not exist, or the read-only role cannot read it.'],
    'a view with no grant' => ['SELECT * FROM user_secrets', 'Table `user_secrets` does not exist, or the read-only role cannot read it.'],
    'a materialized view with no grant' => ['SELECT * FROM order_summary', 'Table `order_summary` does not exist, or the read-only role cannot read it.'],
    'a system table' => ['SELECT * FROM pg_authid', 'Table `pg_authid` does not exist, or the read-only role cannot read it.'],
    'a function the role may not run' => ["SELECT pg_read_file('/etc/hostname')", DbQuery::PERMISSION_DENIED],
]);

it('DQP7 reads the real table name with a table prefix: no second prefix', function () {
    pgsqlQueryRole(['database.connections.'.ReadOnlyRole::CONNECTION.'.prefix' => 'pfx_']);

    expect(pgsqlQuery('SELECT * FROM pfx_items')['note'])
        ->toBe('Table `pfx_items` has columns that you cannot read. Select columns by name. Readable columns: id, name.');
});

it('DQP8 says the query tried to write, and nothing was changed', function (string $sql) {
    pgsqlQueryRole();

    expect(pgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::WROTE])
        ->and(pgsqlQueryOrders())->toBe(2)
        ->and(DB::connection('testing_pgsql')->scalar('SELECT status FROM orders WHERE id = 1234'))->toBe('paid');
})->with([
    'DELETE' => ['DELETE FROM orders'],
    'UPDATE' => ["UPDATE orders SET status = 'refunded'"],
    'INSERT' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'SO-1', 1, 'x')"],
]);

it('DQP9 quotes the first line of any other database error, with its SQLSTATE', function (string $sql, string $note) {
    pgsqlQueryRole();

    expect(pgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null]);
})->with([
    'a typo' => ['SELEC 1', 'The database rejected the query (SQLSTATE 42601): ERROR:  syntax error at or near "SELEC".'],
    'a missing table (spec 11.2 Envelope)' => ['SELECT * FROM nope', 'The database rejected the query (SQLSTATE 42P01): ERROR:  relation "nope" does not exist.'],
    'a missing column' => ['SELECT nme FROM orders', 'The database rejected the query (SQLSTATE 42703): ERROR:  column "nme" does not exist.'],
    'a division by zero' => ['SELECT 1 / 0', 'The database rejected the query (SQLSTATE 22012): ERROR:  division by zero.'],
]);

it('DQP10 cuts a long database message to 300 characters', function () {
    pgsqlQueryRole();

    $note = pgsqlQuery("SELECT CAST(repeat('x', 400) AS int)")['note'] ?? null;
    $note = is_string($note) ? $note : '';
    $quoted = mb_substr($note, mb_strlen('The database rejected the query (SQLSTATE 22P02): '));

    expect($note)->toStartWith('The database rejected the query (SQLSTATE 22P02): ERROR:  invalid input syntax for type integer: "xxx')
        ->and(mb_strlen($quoted))->toBe(DbQuery::MESSAGE_CHARACTERS)
        ->and($quoted)->toEndWith('x…');
});

it('DQP11 says the query is empty for a lone semicolon, before the database', function (string $sql) {
    pgsqlQueryRole();

    expect(pgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::EMPTY_QUERY]);
})->with([';', " ;\n ;; "]);

it('DQP12 says that a statement with no result set ran, never empty, and the reset undoes it', function (string $sql) {
    pgsqlQueryRole();
    $before = data_get(pgsqlQuery('SHOW work_mem'), 'data.rows.0.0');

    expect(pgsqlQuery($sql))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::NO_RESULT_SET, 'data' => null])
        ->and(data_get(pgsqlQuery('SHOW work_mem'), 'data.rows.0.0'))->toBe($before)
        ->and($before)->not->toBe('1GB');
})->with([
    'SET' => ["SET work_mem = '1GB'"],
    'LOCK' => ['LOCK TABLE orders IN ACCESS SHARE MODE'],
]);

it('DQP13 keeps JSON-safe values and gives float4 and float8 as JSON numbers on every PHP version', function () {
    pgsqlQueryRole();

    expect(data_get(pgsqlQuery("SELECT 'NaN'::float8 AS a, '\\xdeadbeef'::bytea AS b"), 'data.rows'))->toBe([['NaN', '[binary value]']])
        ->and(data_get(pgsqlQuery("SELECT 1.5::float8 AS a, 1.5::float4 AS b, 1.50::numeric AS c, '-Infinity'::float8 AS d, 1e300::float8 AS e, 42::int8 AS f"), 'data.rows'))
        ->toBe([[1.5, 1.5, '1.50', '-Infinity', 1.0E+300, 42]]);
});

it('DQP14 gives every timestamp in ISO 8601 with the app offset and its fraction, and leaves other types alone', function () {
    pgsqlQueryRole();
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/London');

    try {
        $moments = pgsqlQuery('SELECT * FROM moments');
        $now = pgsqlQuery('SELECT now() AS n, ARRAY[now()] AS a');
    } finally {
        date_default_timezone_set($previous);
    }

    expect(data_get($moments, 'data.rows.0'))->toBe([
        1, '2026-10-01T09:12:44+01:00', '2026-10-01T09:12:44+01:00', '2026-10-01T09:12:44.123456+01:00',
        '2026-10-01', '09:12:44', '09:12:44+02', '1 day', 'infinity', '0044-03-15 12:00:00 BC',
    ])
        ->and(data_get($now, 'data.rows.0.0'))->toBeString()->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{6})?[+-]\d\d:\d\d$/')
        ->and(data_get($now, 'data.rows.0.1'))->toBeString()->toStartWith('{"');
});

it('DQP15 runs as the read-only role on the read-only connection', function () {
    $role = pgsqlQueryRole();

    expect(data_get(pgsqlQuery('SELECT current_user AS who, current_setting(\'transaction_read_only\') AS ro'), 'data.rows'))
        ->toBe([[$role->name(), 'on']]);
});

it('DQP16 writes one audit entry with the redacted SQL, the row count and the size, and no result data', function () {
    pgsqlQueryRole();
    $jwt = SecretTool::JWT;

    $envelope = pgsqlQuery("SELECT id, number, '{$jwt}' AS token FROM orders WHERE id = 1234");
    $lines = implode("\n", AuditLog::lines());
    $entry = AuditLog::entries()[0];

    expect(data_get($envelope, 'data.rows.0'))->toBe([1234, 'SO-1234', '[redacted:jwt]'])
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and($entry)->toMatchArray([
            'tool' => 'db_query',
            'params' => ['sql' => "SELECT id, number, '[redacted:jwt]' AS token FROM orders WHERE id = 1234"],
            'decision' => 'allowed',
            'outcome' => 'success',
            'row_count' => 1,
        ])
        ->and($entry['output_bytes'])->toBeInt()->toBeGreaterThan(100)
        ->and(str_contains($lines, 'SO-1234'))->toBeFalse()
        ->and(str_contains($lines, $jwt))->toBeFalse();
});

it('DQP17 shows a fixed package note when the connection cannot be opened, or is persistent', function (string $case) {
    $key = 'database.connections.'.ReadOnlyRole::CONNECTION;
    pgsqlQueryRole($case === 'persistent' ? [$key.'.options' => [PDO::ATTR_PERSISTENT => true]] : [$key.'.password' => 'wrong']);

    expect(pgsqlQuery('SELECT 1'))->toMatchArray([
        'status' => 'unavailable',
        'note' => $case === 'persistent' ? ReadOnlySession::PERSISTENT : ReadOnlySession::CONNECTION_FAILED,
    ]);
})->with(['a wrong password', 'persistent']);

it('DQP18 still answers when the readable columns cannot be read: the note names no table', function () {
    pgsqlQueryRole(['runtime-mcp.database.denied_tables' => 'sessions']);

    expect(pgsqlQuery('SELECT * FROM users'))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::PERMISSION_DENIED])
        ->and(pgsqlQuery('SELECT id FROM orders')['status'])->toBe('ok');
});

it('DQP19 lets the output cap drop whole rows from the end, and leaves data.row_count as the database gave it', function () {
    pgsqlQueryRole(['runtime-mcp.output.max_bytes' => 1_000]);

    $envelope = pgsqlQuery("SELECT n, repeat('x', 40) AS pad FROM generate_series(1, 100) AS g(n)");
    $rows = (array) data_get($envelope, 'data.rows');

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBeString()->toContain('rows were omitted')
        ->and($envelope['truncated'])->toBe(100 - count($rows))
        ->and(count($rows))->toBeGreaterThan(0)->toBeLessThan(100)
        ->and(data_get($envelope, 'data.rows.0'))->toBe([1, str_repeat('x', 40)])
        ->and(data_get($envelope, 'data.row_count'))->toBe(100)
        ->and(data_get($envelope, 'data.truncated'))->toBeFalse();
});

it('DQP20 describes PostgreSQL without the MySQL lines in tools/list', function () {
    pgsqlQueryRole();

    $tool = collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))->firstWhere('name', 'db_query');

    expect(data_get($tool, 'description'))->toBeString()->toContain('stopped after 500 ms')
        ->and(str_contains(is_string(data_get($tool, 'description')) ? data_get($tool, 'description') : '', DbQuery::MYSQL_LINE))->toBeFalse();
});

it('DQP21 redacts a database message before the cut: a password that straddles character 300 is never shown in part', function () {
    pgsqlQueryRole();
    // The password starts 10 characters before the cut, and its "@" is after it.
    $prefix = 'ERROR:  invalid input syntax for type integer: "';
    $filler = str_repeat('x', DbQuery::MESSAGE_CHARACTERS - 10 - mb_strlen($prefix) - mb_strlen('postgres://report:'));

    $note = pgsqlQuery("SELECT CAST('{$filler}postgres://report:Hunter2pass@db.internal/app' AS int)")['note'] ?? null;

    expect($note)->toBeString()->toEndWith('…')
        ->and($note)->toContain('postgres://report:[redact')
        ->and($note)->not->toContain('Hunter2');
});

it('DQP22 lets an unexpected exception through to RunTool: a failed, reported call, never a result', function () {
    pgsqlQueryRole();
    Exceptions::fake();
    // Case B (01-14) with a read-write outer transaction: the frame refuses to run the agent's SQL.
    $connection = DB::connection(ReadOnlyRole::CONNECTION);
    $connection->beginTransaction();

    try {
        $envelope = pgsqlQuery('SELECT 1');
    } finally {
        $connection->rollBack();
    }

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBeString()->toStartWith('The tool failed: '.NotInReadOnlyTransaction::class.': ')
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_query', 'outcome' => 'error', 'error_class' => NotInReadOnlyTransaction::class]);

    Exceptions::assertReported(NotInReadOnlyTransaction::class);
    Exceptions::assertReportedCount(1);
});

it('DQP23 names no columns when a readable app table only shares the name with a table of another schema', function () {
    $privileged = DB::connection('testing_pgsql');

    try {
        // A schema outside the app schemas, with USAGE: PostgreSQL then names the table without its schema.
        ReadOnlyRole::setUp(
            currentTest(),
            'testing_pgsql',
            ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array'],
            extend: function (string $privileged): void {
                $db = DB::connection($privileged);
                $db->statement('DROP SCHEMA IF EXISTS rmcp_other CASCADE');
                $db->statement('CREATE SCHEMA rmcp_other');
                $db->statement('CREATE TABLE rmcp_other.orders (id int)');
                $db->statement('GRANT USAGE ON SCHEMA rmcp_other TO PUBLIC');
            },
        );
        Gates::allow('db_query');

        expect(pgsqlQuery('SELECT * FROM rmcp_other.orders'))->toMatchArray(['status' => 'unavailable', 'note' => DbQuery::PERMISSION_DENIED])
            ->and(pgsqlQuery('SELECT * FROM users')['note'])
            ->toBe('Table `users` has columns that you cannot read. Select columns by name. Readable columns: id, name, email, created_at, updated_at.');
    } finally {
        $privileged->statement('DROP SCHEMA IF EXISTS rmcp_other CASCADE');
    }
});
