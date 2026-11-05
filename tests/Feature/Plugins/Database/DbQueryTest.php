<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;

/*
 * 01-20: db_query without a real database. bootWith() makes the read-only connection SQLite
 * (testing_readonly), which db_query does not support, so these tests cover the contract, the
 * description, the input rules, the Gate, the rate limit and the audit. The PostgreSQL, MySQL and
 * MariaDB behaviour is in tests/Integration/{Pgsql,Mysql}/DbQueryTest.php.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function dbQueryServe(array $config = [], bool $gate = true): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', ...$config]);

    if ($gate) {
        Gates::allow('db_query');
    }
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function dbQueryEnvelope(array $arguments, ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_query', $arguments, $era));
}

/** @return array<array-key, mixed> the db_query entry of tools/list */
function dbQueryListed(): array
{
    $tool = collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))->firstWhere('name', 'db_query');

    return is_array($tool) ? $tool : [];
}

it('DQ1 keeps the tool contract: name, read_sensitive tier, ability, schema and rules agree', function () {
    dbQueryServe();

    $tool = app(DbQuery::class);
    assertToolContract($tool, new DatabasePlugin);

    expect($tool->name())->toBe('db_query')
        ->and($tool->tier())->toBe(Tier::ReadSensitive)
        ->and($tool->ability())->toBe('runtime-mcp.db_query')
        ->and($tool->opensWorld())->toBeFalse()
        ->and(data_get(dbQueryListed(), 'annotations.readOnlyHint'))->toBeTrue();
});

it('DQ2 states the configured limits in its description, and the MySQL and MariaDB lines by the driver', function (array $connection, bool $mysql, bool $maria) {
    dbQueryServe([
        'runtime-mcp.database.max_rows' => 200,
        'runtime-mcp.database.statement_timeout_ms' => 2500,
        'database.connections.described' => $connection,
        'runtime-mcp.database.connection' => 'described',
    ]);

    $description = app(DbQuery::class)->description();

    expect($description)->toContain('the query is stopped after 2500 ms (the statement timeout); at most 200 rows are returned.')
        ->and($description)->toContain('Input: sql (at most 10,000 characters), max_rows (optional, at most 200).')
        ->and(str_contains($description, DbQuery::MYSQL_LINE))->toBe($mysql)
        ->and(str_contains($description, DbQuery::MARIADB_LINE))->toBe($maria)
        ->and(array_key_exists('described', DB::getConnections()))->toBeFalse();
})->with([
    'pgsql' => [['driver' => 'pgsql', 'host' => '/nonexistent/rmcp'], false, false],
    'mysql' => [['driver' => 'mysql', 'unix_socket' => '/nonexistent/rmcp.sock'], true, false],
    'mariadb' => [['driver' => 'mariadb', 'unix_socket' => '/nonexistent/rmcp.sock'], true, true],
    'a url that names mariadb' => [['driver' => 'mysql', 'url' => 'mariadb://u:p@127.0.0.1/app'], true, true],
    'sqlite' => [['driver' => 'sqlite', 'database' => ':memory:'], false, false],
    'no driver' => [['host' => 'db'], false, false],
]);

it('DQ3 reads the driver without a connection and never throws: unknown, unset, default and dotted names', function () {
    dbQueryServe(['runtime-mcp.database.connection' => 'nope']);
    $unknown = app(DbQuery::class)->description();
    $listed = data_get(dbQueryListed(), 'description');

    config()->set('runtime-mcp.database.connection', '');
    $unset = app(DbQuery::class)->description();

    config()->set('database.connections.group', ['mine' => ['driver' => 'mariadb']]);
    config()->set('runtime-mcp.database.connection', 'group.mine');
    $dotted = app(DbQuery::class)->description();

    // Unset, as ReadOnlySession: the default connection (a relaxed environment allows it, 01-14).
    config()->set('database.connections.maria_default', ['driver' => 'mariadb']);
    config()->set('database.default', 'maria_default');
    config()->set('runtime-mcp.database.connection', null);
    $fallback = app(DbQuery::class)->description();

    expect(str_contains($unknown, DbQuery::MYSQL_LINE))->toBeFalse()
        ->and($listed)->toBeString()->toStartWith('Runs one read-only SQL query')
        ->and(str_contains($unset, DbQuery::MYSQL_LINE))->toBeFalse()
        ->and($dotted)->toEndWith(DbQuery::MYSQL_LINE.' '.DbQuery::MARIADB_LINE)
        ->and($fallback)->toEndWith(DbQuery::MYSQL_LINE.' '.DbQuery::MARIADB_LINE);
});

it('DQ4 is unavailable on a SQLite read-only connection, and names the driver', function (ProtocolEra $era) {
    dbQueryServe();

    $envelope = dbQueryEnvelope(['sql' => 'SELECT 1'], $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope)->toMatchArray([
            'status' => 'unavailable',
            'note' => 'db_query supports PostgreSQL, MySQL and MariaDB; this connection uses sqlite.',
            'truncated' => false,
            'data' => null,
        ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('DQ5 refuses bad input before any SQL runs, and audits it', function (array $arguments) {
    dbQueryServe();

    $envelope = dbQueryEnvelope($arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBeString()->toStartWith('Invalid input:')
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_query', 'outcome' => 'error']);
})->with([
    'no sql' => [[]],
    'an empty sql' => [['sql' => '']],
    'blanks only' => [['sql' => "  \n\t "]],
    'null' => [['sql' => null]],
    'a number' => [['sql' => 5]],
    'a list' => [['sql' => ['SELECT 1']]],
    '10,001 characters' => [['sql' => 'SELECT '.str_repeat('1', 9_994)]],
    'max_rows 0' => [['sql' => 'SELECT 1', 'max_rows' => 0]],
    'max_rows above the maximum' => [['sql' => 'SELECT 1', 'max_rows' => 501]],
    'max_rows 10000' => [['sql' => 'SELECT 1', 'max_rows' => 10_000]],
    'max_rows not a number' => [['sql' => 'SELECT 1', 'max_rows' => 'x']],
    'max_rows a fraction' => [['sql' => 'SELECT 1', 'max_rows' => 1.5]],
    'max_rows null (the schema says integer)' => [['sql' => 'SELECT 1', 'max_rows' => null]],
    'max_rows true (integer alone takes it as 1)' => [['sql' => 'SELECT 1', 'max_rows' => true]],
    'an unknown argument' => [['sql' => 'SELECT 1', 'connection' => 'mysql']],
]);

it('DQ6 accepts 10,000 characters (also multi-byte) and max_rows up to the configured maximum', function () {
    dbQueryServe(['runtime-mcp.database.max_rows' => 50]);
    $unsupported = sprintf(DbQuery::UNSUPPORTED, 'sqlite');

    expect(dbQueryEnvelope(['sql' => 'SELECT '.str_repeat('1', 9_993)])['note'])->toBe($unsupported)
        ->and(dbQueryEnvelope(['sql' => "SELECT '".str_repeat('é', 9_990)."'"])['note'])->toBe($unsupported)
        ->and(dbQueryEnvelope(['sql' => 'SELECT 1', 'max_rows' => 50])['note'])->toBe($unsupported)
        ->and(dbQueryEnvelope(['sql' => 'SELECT 1', 'max_rows' => 51])['note'])->toStartWith('Invalid input:')
        ->and(data_get(dbQueryListed(), 'inputSchema'))->toMatchArray([
            'type' => 'object',
            'required' => ['sql'],
        ])
        ->and(data_get(dbQueryListed(), 'inputSchema.properties.sql'))->toMatchArray(['type' => 'string', 'maxLength' => 10_000])
        ->and(data_get(dbQueryListed(), 'inputSchema.properties.max_rows'))->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 50]);
});

it('DQ7 is hidden and denied while the app defines no Gate', function () {
    dbQueryServe(gate: false);

    $names = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name');

    expect($names)->toBe([])
        ->and(dbQueryEnvelope(['sql' => 'SELECT 1']))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Access denied: the app defines no Gate `runtime-mcp.db_query`, so this tool is off (default deny).',
            'data' => null,
        ])
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_query', 'decision' => 'denied']);
});

it('DQ8 is limited to 60 calls per minute by default, and the next call over a limit is denied', function () {
    expect(config('runtime-mcp.rate_limits.per_tool.db_query'))->toBe('60,1');

    dbQueryServe(['runtime-mcp.rate_limits.per_tool' => ['db_query' => '2,1']]);
    dbQueryEnvelope(['sql' => 'SELECT 1']);
    dbQueryEnvelope(['sql' => 'SELECT 1']);
    $third = dbQueryEnvelope(['sql' => 'SELECT 1']);

    expect($third['status'])->toBe('unavailable')
        ->and($third['note'])->toStartWith('Rate limit reached: 2 calls per minute for db_query.')
        ->and(AuditLog::entries()[2])->toMatchArray(['tool' => 'db_query', 'decision' => 'denied', 'reason' => 'rate_limited']);
});

it('DQ9 shows the strict-environment refusal as its fixed note', function () {
    // 01-10's boot rule stops the server in this state while the database plugin is on, so the tool is
    // called directly.
    currentTest()->bootWith([
        'runtime-mcp.plugins.database' => false,
        'runtime-mcp.plugins.models' => false,
        'runtime-mcp.database.connection' => null,
    ], 'production');

    $result = app(DbQuery::class)->execute(new ToolInput(['sql' => 'SELECT 1']));

    expect($result->status)->toBe(Status::Unavailable)
        ->and($result->note)->toBe(ReadOnlySession::NO_READ_ONLY_CONNECTION);
});

it('DQ10 quotes only the first line of a database message, cut to 300 characters, also inside the 01-15 note', function () {
    $fixed = 'MySQL could not run this query inside the row limit, so it was not run. '.DbQuery::DATABASE_SAID;
    $long = str_repeat('é', 400);

    expect(DbQuery::quote("ERROR:  syntax error\nLINE 1: SELEC 1\n        ^"))->toBe('ERROR:  syntax error')
        ->and(DbQuery::quote(str_repeat('é', 300)))->toBe(str_repeat('é', 300))
        ->and(DbQuery::quote($long))->toBe(str_repeat('é', 299).'…')
        ->and(DbQuery::notLimitable($fixed."You have an error\nsecond line"))->toBe($fixed.'You have an error')
        ->and(DbQuery::notLimitable($fixed.$long))->toBe($fixed.str_repeat('é', 299).'…')
        ->and(DbQuery::notLimitable('no marker'))->toBe('no marker');
});

it('DQ11 reads a malformed url as no driver: tools/list still answers, and the call fails in the pipeline, audited', function () {
    dbQueryServe([
        'database.connections.malformed' => ['driver' => 'mysql', 'url' => 'mysql://ro:ab/cd@db/app'],
        'runtime-mcp.database.connection' => 'malformed',
    ]);
    Exceptions::fake();

    $description = app(DbQuery::class)->description();
    $listed = data_get(dbQueryListed(), 'description');

    expect(str_contains($description, DbQuery::MYSQL_LINE))->toBeFalse()
        ->and(is_string($listed) && str_starts_with($listed, $description))->toBeTrue()
        ->and(dbQueryEnvelope(['sql' => 'SELECT 1']))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'The tool failed: InvalidArgumentException: The database configuration URL is malformed.',
        ])
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_query', 'outcome' => 'error', 'error_class' => 'InvalidArgumentException']);

    Exceptions::assertReported(InvalidArgumentException::class);
    Exceptions::assertReportedCount(1);
});
