<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbSchema;
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
 * 01-19: db_schema without a real database. bootWith() makes the read-only connection SQLite
 * (testing_readonly), which db_schema does not support, so these tests cover the contract, the input
 * rules, the Gate, the audit and the config errors. The PostgreSQL, MySQL and MariaDB behaviour is in
 * tests/Integration/{Pgsql,Mysql}/DbSchemaTest.php.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function dbSchemaServe(array $config = [], bool $gate = true): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', ...$config]);

    if ($gate) {
        Gates::allow('db_schema');
    }
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function dbSchemaEnvelope(array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('db_schema', $arguments, $era));
}

it('DS1 keeps the tool contract: name, read tier, ability, schema and rules agree', function () {
    dbSchemaServe();

    $tool = app(DbSchema::class);
    assertToolContract($tool, new DatabasePlugin);

    expect($tool->name())->toBe('db_schema')
        ->and($tool->tier())->toBe(Tier::Read)
        ->and($tool->ability())->toBe('runtime-mcp.db_schema')
        ->and((new DatabasePlugin)->tools())->toBe([DbSchema::class, DbQuery::class]);
});

it('DS2 is unavailable on a SQLite read-only connection, and names the driver', function (ProtocolEra $era, array $arguments) {
    dbSchemaServe();

    $envelope = dbSchemaEnvelope($arguments, $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope)->toMatchArray([
            'status' => 'unavailable',
            'note' => 'db_schema supports PostgreSQL, MySQL and MariaDB; this connection uses sqlite.',
            'data' => null,
        ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern])->with([
    'no table' => [[]],
    'a table' => [['table' => 'users']],
]);

it('DS3 refuses a denied_tables value that is not a list, and lists nothing', function (mixed $denied) {
    dbSchemaServe(['runtime-mcp.database.denied_tables' => $denied]);

    expect(dbSchemaEnvelope())->toMatchArray([
        'status' => 'unavailable',
        'note' => DbSchema::BAD_DENIED_TABLES,
        'data' => null,
    ])->and(dbSchemaEnvelope(['table' => 'users']))->toMatchArray(['note' => DbSchema::BAD_DENIED_TABLES]);
})->with([
    'a string' => ['sessions'],
    'null' => [null],
]);

it('DS4 refuses a table argument that is not a name or schema.name, and audits it', function (mixed $table) {
    dbSchemaServe();

    $envelope = dbSchemaEnvelope(['table' => $table]);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBeString()->toStartWith('Invalid input:')
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_schema', 'outcome' => 'error']);
})->with([
    'stacked SQL' => ['orders; drop table orders'],
    'a quote' => ["it's"],
    'three parts' => ['a.b.c'],
    'a trailing newline' => ["orders\n"],
    'a leading dot' => ['.orders'],
    'empty' => [''],
    'longer than 128 characters' => [str_repeat('a', 129)],
    'a number' => [5],
    'null' => [null],
]);

it('DS5 accepts a 128 character name, and refuses an argument it does not know', function () {
    dbSchemaServe();

    expect(dbSchemaEnvelope(['table' => str_repeat('a', 128)])['note'])->toBe(sprintf(DbSchema::UNSUPPORTED, 'sqlite'))
        ->and(dbSchemaEnvelope(['table' => 'users', 'schema' => 'public'])['note'])->toBe('Invalid input: The schema argument is not allowed.');
});

it('DS6 is hidden and denied while the app defines no Gate', function () {
    dbSchemaServe(gate: false);

    $names = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name');

    expect($names)->toBe([])
        ->and(dbSchemaEnvelope())->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Access denied: the app defines no Gate `runtime-mcp.db_schema`, so this tool is off (default deny).',
            'data' => null,
        ])
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'db_schema', 'decision' => 'denied']);
});

it('DS7 is listed with its input schema once the Gate allows it', function () {
    dbSchemaServe();

    $tool = collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))
        ->firstWhere('name', 'db_schema');

    expect($tool)->toBeArray()
        ->and(data_get($tool, 'inputSchema.properties.table'))->toMatchArray([
            'type' => 'string',
            'maxLength' => 128,
            'pattern' => DbSchema::TABLE_PATTERN,
        ])
        ->and(data_get($tool, 'annotations.readOnlyHint'))->toBeTrue();
});

it('DS8 shows the strict-environment refusal as its fixed note, not as a SQLSTATE', function () {
    // 01-10's boot rule stops the server in this state while the database plugin is on, so the tool is
    // called directly: this pins NO_READ_ONLY_CONNECTION in FIXED_NOTES.
    currentTest()->bootWith([
        'runtime-mcp.plugins.database' => false,
        'runtime-mcp.plugins.models' => false,
        'runtime-mcp.database.connection' => null,
    ], 'production');

    $result = app(DbSchema::class)->execute(new ToolInput([]));

    expect($result->status)->toBe(Status::Unavailable)
        ->and($result->note)->toBe(ReadOnlySession::NO_READ_ONLY_CONNECTION);
});
