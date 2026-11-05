<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\Tools\ModelShow;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Note;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\User;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;

/*
 * 01-21: model_show without a real database. bootWith() makes the read-only connection SQLite
 * (testing_readonly), which model_show does not support, so a valid call ends at "supports PostgreSQL,
 * MySQL and MariaDB". That is enough for the contract, the description, the input rules, the Gate and the
 * empty config. The PostgreSQL, MySQL and MariaDB behaviour is in tests/Integration/{Pgsql,Mysql}/ModelShowTest.php.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

const MODEL_SHOW_MODELS = [
    'order' => ['class' => Order::class, 'relations' => ['items', 'customer', 'tags', 'notes', 'latestNote', 'labels'], 'appends' => ['display_total']],
    'customer' => ['class' => User::class, 'relations' => ['orders', 'items']],
    'note' => ['class' => Note::class, 'relations' => ['notable']],
];

/** @param  array<string, mixed>  $config */
function modelShowServe(mixed $models = MODEL_SHOW_MODELS, array $config = [], bool $gate = true): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', 'runtime-mcp.models' => $models, ...$config]);

    if ($gate) {
        Gates::allow('model_show');
    }
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function modelShowEnvelope(array $arguments, ProtocolEra $era = ProtocolEra::Legacy): array
{
    return FixtureTools::envelope(FixtureTools::call('model_show', $arguments, $era));
}

const MODEL_SHOW_SQLITE = 'model_show supports PostgreSQL, MySQL and MariaDB; this connection uses sqlite.';

it('MS1 keeps the tool contract with models and without: name, tier, ability, schema and rules agree', function (mixed $models) {
    modelShowServe($models);

    $tool = app(ModelShow::class);
    assertToolContract($tool, app(ModelsPlugin::class));

    expect($tool->name())->toBe('model_show')
        ->and($tool->tier())->toBe(Tier::ReadSensitive)
        ->and($tool->ability())->toBe('runtime-mcp.model_show')
        ->and(app(ModelsPlugin::class)->tools())->toBe([ModelShow::class]);
})->with([
    'three models' => [MODEL_SHOW_MODELS],
    'none' => [[]],
    'only invalid ones' => [['Bad' => ['class' => Order::class]]],
]);

it('MS2 lists each alias with its relations in the description, and the input schema says the same', function () {
    modelShowServe();

    $tool = collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))->firstWhere('name', 'model_show');

    expect(data_get($tool, 'description'))->toContain(
        'Models: order (relations: items, customer, tags, notes, latestNote, labels), customer (relations: orders, items), note (relations: notable).'
    )
        ->and(data_get($tool, 'description'))->toContain('Input: model, key (at most 64 characters), with (optional, at most 5 relation names).')
        ->and(data_get($tool, 'inputSchema.properties.model.enum'))->toBe(['order', 'customer', 'note'])
        ->and(data_get($tool, 'inputSchema.properties.key'))->toMatchArray(['type' => 'string', 'maxLength' => 64])
        ->and(data_get($tool, 'inputSchema.properties.with'))->toMatchArray(['type' => 'array', 'maxItems' => 5])
        ->and(data_get($tool, 'inputSchema.properties.with.items.enum'))->toBe(['customer', 'items', 'labels', 'latestNote', 'notable', 'notes', 'orders', 'tags'])
        ->and(data_get($tool, 'inputSchema.required'))->toBe(['model', 'key'])
        ->and(data_get($tool, 'annotations.readOnlyHint'))->toBeTrue();
});

it('MS3 says so when no model is allow-listed: no enum in the schema, and every call is unavailable', function () {
    modelShowServe([]);

    $tool = collect((array) currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools'))->firstWhere('name', 'model_show');
    $envelope = modelShowEnvelope(['model' => 'order', 'key' => 1]);

    expect(data_get($tool, 'description'))->toContain('No model is allow-listed in runtime-mcp.models yet, so every call returns unavailable.')
        ->and(data_get($tool, 'inputSchema.properties.model'))->not->toHaveKey('enum')
        ->and(data_get($tool, 'inputSchema.properties.model.maxLength'))->toBe(64)
        ->and(data_get($tool, 'inputSchema.properties.with.items'))->not->toHaveKey('enum')
        ->and($envelope)->toMatchArray(['status' => 'unavailable', 'note' => ModelShow::NO_MODELS, 'data' => null]);
});

it('MS4 takes a key as an integer or a string of up to 64 characters', function (mixed $key) {
    modelShowServe();

    // Valid input reaches the (unsupported) driver: the table is read before the key type is checked.
    expect(modelShowEnvelope(['model' => 'order', 'key' => $key]))->toMatchArray(['status' => 'unavailable', 'note' => MODEL_SHOW_SQLITE]);
})->with([
    'an integer' => [65],
    'a numeric string' => ['65'],
    'a uuid' => ['8d4b1c1e-0d55-4c8e-9c2f-9f1d0a9a7b11'],
    '64 characters' => [str_repeat('k', 64)],
]);

it('MS5 refuses a bad model, key or with as Invalid input, and audits the call as an error', function (array $arguments, string $message) {
    modelShowServe();

    $envelope = modelShowEnvelope($arguments);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toStartWith('Invalid input: ')
        ->and($envelope['note'])->toContain($message)
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'model_show', 'decision' => 'allowed', 'outcome' => 'error']);
})->with([
    'no model' => [['key' => 1], 'model'],
    'a model that is not allow-listed' => [['model' => 'invoice', 'key' => 1], 'model'],
    'no key' => [['model' => 'order'], 'key'],
    'a blank key' => [['model' => 'order', 'key' => '   '], 'key'],
    'a key of 65 characters' => [['model' => 'order', 'key' => str_repeat('k', 65)], 'key'],
    'a key that is a list' => [['model' => 'order', 'key' => [1]], 'The key must be a string or an integer.'],
    'a key that is true' => [['model' => 'order', 'key' => true], 'The key must be a string or an integer.'],
    'a key that is a float' => [['model' => 'order', 'key' => 1.5], 'The key must be a string or an integer.'],
    'six relations' => [['model' => 'order', 'key' => 1, 'with' => ['items', 'customer', 'tags', 'notes', 'latestNote', 'labels']], 'with'],
    'a relation of another model' => [['model' => 'order', 'key' => 1, 'with' => ['orders']], 'Each with value must be an allow-listed relation of this model.'],
    'a relation of no model' => [['model' => 'order', 'key' => 1, 'with' => ['secrets']], 'with.0'],
    'an empty relation name' => [['model' => 'order', 'key' => 1, 'with' => ['']], 'with.0'],
    'the same relation twice' => [['model' => 'order', 'key' => 1, 'with' => ['items', 'items']], 'with.0'],
    'with as a map' => [['model' => 'order', 'key' => 1, 'with' => ['a' => 'items']], 'with'],
    'with as a string' => [['model' => 'order', 'key' => 1, 'with' => 'items'], 'with'],
    'an unknown argument' => [['model' => 'order', 'key' => 1, 'relations' => ['items']], 'The relations argument is not allowed.'],
]);

it('MS6 is unavailable on a SQLite read-only connection in both protocol eras, and the envelope matches its schema', function (ProtocolEra $era) {
    modelShowServe();

    $envelope = modelShowEnvelope(['model' => 'order', 'key' => 1, 'with' => ['items']], $era);

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope)->toMatchArray(['status' => 'unavailable', 'note' => MODEL_SHOW_SQLITE, 'data' => null]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('MS7 is hidden and denied while the app defines no Gate', function () {
    modelShowServe(gate: false);

    $names = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name');

    expect($names)->toBe([])
        ->and(modelShowEnvelope(['model' => 'order', 'key' => 1]))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Access denied: the app defines no Gate `runtime-mcp.model_show`, so this tool is off (default deny).',
        ])
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'model_show', 'decision' => 'denied', 'reason' => 'no_gate']);
});

it('MS8 shows the strict-environment refusal as its fixed note', function () {
    // 01-10's boot rule stops the server in this state while the plugin is on, so the tool is called directly.
    currentTest()->bootWith([
        'runtime-mcp.plugins.database' => false,
        'runtime-mcp.plugins.models' => false,
        'runtime-mcp.database.connection' => null,
        'runtime-mcp.models' => MODEL_SHOW_MODELS,
    ], 'production');

    $result = app(ModelShow::class)->execute(new ToolInput(['model' => 'order', 'key' => 1]));

    expect($result->status)->toBe(Status::Unavailable)
        ->and($result->note)->toBe(ReadOnlySession::NO_READ_ONLY_CONNECTION);
});

it('MS9 reads a key for an integer primary key as a whole number, or not at all', function (int|string $key, ?int $expected) {
    expect(ModelShow::integerKey($key))->toBe($expected);
})->with([
    [1234, 1234],
    ['1234', 1234],
    ['-7', -7],
    ['007', 7],
    ['0', 0],
    ['9223372036854775807', PHP_INT_MAX],
    ['9223372036854775808', null],
    ['1234abc', null],
    ['abc', null],
    ['12.5', null],
    [' 12', null],
    ['+12', null],
    ['', null],
]);

it('MS10 puts the connection prefix before the last part of a table name', function (string $table, string $prefix, string $real) {
    expect(ModelShow::realName($table, $prefix))->toBe($real);
})->with([
    ['orders', '', 'orders'],
    ['orders', 'pfx_', 'pfx_orders'],
    ['billing.invoices', 'pfx_', 'billing.pfx_invoices'],
    ['billing.invoices', '', 'billing.invoices'],
]);
