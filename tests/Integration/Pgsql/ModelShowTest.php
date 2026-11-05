<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\RefusedAccessorOrder;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ModelShowSchema;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;

/*
 * Spec 5.1, 5.5, 5.6, 5.9, 6.2, 01-21: model_show on PostgreSQL, as 01-11's read-only role, over HTTP. The
 * schema, the rows and the expected records are in ModelShowSchema (shared with the MySQL file).
 * testing_pgsql is the privileged connection; the default connection stays SQLite unless a test says so.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @param  array<string, mixed>  $config */
function pgsqlModelShow(array $config = [], ?Closure $more = null): ReadOnlyRole
{
    return ModelShowSchema::serve('testing_pgsql', $config, more: $more);
}

it('MSP1 shows a record with casts, appends and five relations, in both protocol eras, in strict mode', function (ProtocolEra $era) {
    pgsqlModelShow();
    // Model::shouldBeStrict() in many apps: reading an attribute that was not selected throws. model_show never does.
    Model::preventAccessingMissingAttributes();

    try {
        $envelope = ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['items', 'customer', 'tags', 'notes', 'latestNote']], $era);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and($envelope['truncated'])->toBeFalse()
        ->and($envelope['data'])->toBe([
            'model' => 'order',
            'key' => '1',
            'record' => ModelShowSchema::ORDER_1,
            'relations' => [
                'items' => ['type' => 'has_many', 'records' => ModelShowSchema::ITEMS_OF_1, 'truncated' => false],
                'customer' => ['type' => 'belongs_to', 'record' => ModelShowSchema::ADA],
                'tags' => ['type' => 'belongs_to_many', 'records' => [['id' => 1, 'name' => 'gift'], ['id' => 2, 'name' => 'rush']], 'truncated' => false],
                'notes' => ['type' => 'morph_many', 'records' => [
                    ['id' => 1, 'notable_type' => Order::class, 'notable_id' => 1, 'body' => 'call first'],
                    ['id' => 2, 'notable_type' => Order::class, 'notable_id' => 1, 'body' => 'paid by card'],
                ], 'truncated' => false],
                'latestNote' => ['type' => 'morph_one', 'record' => ['id' => 2, 'notable_type' => Order::class, 'notable_id' => 1, 'body' => 'paid by card']],
            ],
        ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('MSP2 takes the key as a string too, and loads a morph_to_many relation; with no with, no relations key', function () {
    pgsqlModelShow();

    expect(data_get(ModelShowSchema::call(['model' => 'order', 'key' => '1', 'with' => ['labels']]), 'data.relations'))
        ->toBe(['labels' => ['type' => 'morph_to_many', 'records' => [['id' => 1, 'name' => 'vip']], 'truncated' => false]])
        ->and(data_get(ModelShowSchema::call(['model' => 'order', 'key' => '001']), 'data'))
        ->toBe(['model' => 'order', 'key' => '001', 'record' => ModelShowSchema::ORDER_1]);
});

it('MSP3 keeps 50 related records of 120, marks the relation truncated, and is partial with an unknown count', function () {
    pgsqlModelShow();

    $envelope = ModelShowSchema::call(['model' => 'order', 'key' => 4, 'with' => ['items']]);
    $items = data_get($envelope, 'data.relations.items', []);

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['truncated'])->toBeFalse()
        ->and($envelope['note'])->toBe('Relation `items` has more than 50 records; the first 50 are shown.')
        ->and(data_get($items, 'truncated'))->toBeTrue()
        ->and(data_get($items, 'records', []))->toHaveCount(50)
        ->and(data_get($items, 'records.0.product_name'))->toBe('Bulk 1')
        ->and(data_get($items, 'records.49.product_name'))->toBe('Bulk 50')
        ->and(AuditLog::entries()[0]['row_count'] ?? null)->toBe(1);
});

it('MSP4 is empty, and says what it searched, for a missing or a soft-deleted record', function (int $key) {
    pgsqlModelShow();

    expect(ModelShowSchema::call(['model' => 'order', 'key' => $key]))->toMatchArray([
        'status' => 'empty',
        'note' => "No `order` with key `{$key}` in table `orders` (read-only connection).",
    ]);
})->with(['missing' => [999999], 'soft deleted' => [3]]);

it('MSP5 is empty, without a database error, for a key that cannot match the key type', function (string $model, string $key, string $note) {
    pgsqlModelShow();

    expect(ModelShowSchema::call(['model' => $model, 'key' => $key]))->toMatchArray(['status' => 'empty', 'note' => $note]);
})->with([
    'letters after digits' => ['order', '1234abc', '`order` has an integer primary key, so the key `1234abc` matches no record.'],
    'beyond bigint' => ['order', '99999999999999999999', '`order` has an integer primary key, so the key `99999999999999999999` matches no record.'],
    'not a uuid (22P02)' => ['device', 'abc', 'The key `abc` does not fit the type of the primary key of table `devices` (SQLSTATE 22P02), so no record matches.'],
    'a uuid that is not there' => ['device', '00000000-0000-4000-8000-000000000000', 'No `device` with key `00000000-0000-4000-8000-000000000000` in table `devices` (read-only connection).'],
]);

it('MSP6 shows a record with a uuid key', function () {
    pgsqlModelShow();

    expect(data_get(ModelShowSchema::call(['model' => 'device', 'key' => ModelShowSchema::DEVICE]), 'data.record'))
        ->toBe(['id' => ModelShowSchema::DEVICE, 'name' => 'phone']);
});

it('MSP7 enforces the view Policy: a denial is audited as denied with the Policy reason and message', function (int $key, string $reason, string $note, int $warnings) {
    pgsqlModelShow();
    $logger = RecordingLogger::install();

    $envelope = ModelShowSchema::call(['model' => 'order', 'key' => $key]);

    expect($envelope)->toMatchArray(['status' => 'unavailable', 'note' => $note, 'data' => null])
        ->and($logger->messages())->toHaveCount($warnings)
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'model_show', 'decision' => 'denied', 'reason' => $reason]);
})->with([
    'denied' => [5, 'policy_denied', 'Access denied: the `view` Policy of '.Order::class.' denied this record. Blocked orders are private.', 0],
    'the Policy throws (01-09 logs a warning)' => [6, 'gate_error', 'Access denied: the `view` Policy of '.Order::class.' failed. The server log names the cause.', 1],
]);

it('MSP8 leaves out related records that their own Policy denies, counts them, and is partial', function () {
    pgsqlModelShow();
    $logger = RecordingLogger::install();

    $order = ModelShowSchema::call(['model' => 'order', 'key' => 2, 'with' => ['customer']]);
    $user = ModelShowSchema::call(['model' => 'customer', 'key' => 3, 'with' => ['orders']]);

    expect($order['status'])->toBe('partial')
        ->and($order['note'])->toBe('The `view` Policy denied 1 record(s) of relation `customer`; they are left out.')
        ->and(data_get($order, 'data.relations.customer'))->toBe(['type' => 'belongs_to', 'record' => null, 'denied' => 1])
        ->and($user['status'])->toBe('partial')
        ->and($user['note'])->toBe('The `view` Policy denied 2 record(s) of relation `orders`; they are left out.')
        ->and(data_get($user, 'data.relations.orders.records.*.number'))->toBe(['A-7'])
        ->and(data_get($user, 'data.relations.orders.denied'))->toBe(2)
        ->and($logger->messages())->toHaveCount(1);
});

it('MSP9 does not load a relation on its own connection, one it cannot build, one Laravel does not ship, or one with a masked key', function () {
    pgsqlModelShow();
    Exceptions::fake();

    $envelope = ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['invoices', 'ledger', 'custom', 'customerByToken', 'items']]);

    expect($envelope['status'])->toBe('partial')
        ->and(data_get($envelope, 'data.relations'))->toBe([
            'invoices' => ['type' => 'has_many', 'unavailable' => 'Relation `invoices` uses its own connection `billing`, so it was not loaded.'],
            'ledger' => ['type' => null, 'unavailable' => 'Relation `ledger` could not be built (InvalidArgumentException).'],
            'custom' => ['type' => null, 'unavailable' => 'Relation `custom` is a CustomHasMany, which model_show does not load.'],
            'customerByToken' => ['type' => 'belongs_to', 'unavailable' => 'Relation `customerByToken` needs column `api_token` of table `users`, which the read-only role cannot read.'],
            'items' => ['type' => 'has_many', 'records' => ModelShowSchema::ITEMS_OF_1, 'truncated' => false],
        ])
        ->and($envelope['note'])->toBe(implode(' ', [
            'Relation `invoices` uses its own connection `billing`, so it was not loaded.',
            'Relation `ledger` could not be built (InvalidArgumentException).',
            'Relation `custom` is a CustomHasMany, which model_show does not load.',
            'Relation `customerByToken` needs column `api_token` of table `users`, which the read-only role cannot read.',
        ]));

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('MSP10 reads only through the read-only connection, never selects *, and never loads the $with relation', function () {
    pgsqlModelShow();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [$query->connectionName, $query->sql];
    });

    $envelope = ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['items', 'customer', 'tags', 'notes', 'labels']]);
    // Order is the related model here: its own $with must not load either.
    $user = ModelShowSchema::call(['model' => 'customer', 'key' => 1, 'with' => ['orders']]);
    $sql = array_column($queries, 1);
    $tables = '(orders|order_items|users|tags|order_tag|notes|labels|labelables|audits)';

    expect($envelope['status'])->toBe('ok')
        ->and(data_get($user, 'data.relations.orders.records.*.number'))->toBe(['A-1', 'A-4'])
        ->and(array_filter($queries, fn (array $q): bool => $q[0] !== ReadOnlyRole::CONNECTION && preg_match('/"'.$tables.'"/', $q[1]) === 1))->toBe([])
        ->and(preg_grep('/select\s+(?:"?\w+"?\.)?\*\s+from\s+"?'.$tables.'"?/i', $sql))->toBe([])
        ->and(preg_grep('/"audits"/', $sql))->toBe([])
        ->and(preg_grep('/"orders"\."id", "orders"\."user_id"/', $sql))->not->toBe([]);
});

it('MSP11 stops a retrieved observer that writes through the default connection: the 01-16 note, no row', function () {
    pgsqlModelShow(['database.default' => 'testing_pgsql']);
    Exceptions::fake();

    expect(ModelShowSchema::call(['model' => 'watched', 'key' => 1]))->toMatchArray([
        'status' => 'unavailable',
        'note' => WriteAttemptExplainer::WRITE,
    ])
        ->and(DB::connection('testing_pgsql')->table('model_events')->count())->toBe(0);

    Exceptions::assertReported(ReadOnlyViolation::class);
});

it('MSP12 stops a slow global scope at the statement timeout (Q1: Eloquent runs inside ReadOnlySession)', function () {
    pgsqlModelShow(['runtime-mcp.database.statement_timeout_ms' => 200]);

    expect(ModelShowSchema::call(['model' => 'slow', 'key' => 1]))->toMatchArray([
        'status' => 'unavailable',
        'note' => 'Reading `slow` was stopped by the 200 ms statement timeout.',
    ]);
});

it('MSP13 finds a model on a prefixed read-only connection by the real table name (01-19 update)', function () {
    pgsqlModelShow(
        ['database.connections.runtime_mcp_ro.prefix' => 'pfx_'],
        function (string $privileged): void {
            DB::connection($privileged)->statement('CREATE TABLE pfx_orders (id bigserial primary key, number varchar(255), deleted_at timestamp null)');
            DB::connection($privileged)->table('pfx_orders')->insert(['number' => 'P-1']);
        },
    );

    expect(ModelShowSchema::call(['model' => 'order', 'key' => 1]))->toMatchArray([
        'status' => 'ok',
        'data' => ['model' => 'order', 'key' => '1', 'record' => ['id' => 1, 'number' => 'P-1', 'display_total' => 'EUR ']],
    ]);
});

it('MSP14 follows a MorphTo to the class in the type column, and not to a name that is no model', function (int $key, string $status, array $relation) {
    pgsqlModelShow();

    $envelope = ModelShowSchema::call(['model' => 'note', 'key' => $key, 'with' => ['notable']]);

    expect($envelope['status'])->toBe($status)
        ->and(data_get($envelope, 'data.relations.notable'))->toBe($relation);
})->with([
    'an order' => [1, 'ok', ['type' => 'morph_to', 'record' => ModelShowSchema::ORDER_1_RELATED]],
    'App\Nope' => [3, 'partial', ['type' => 'morph_to', 'unavailable' => 'Relation `notable` points to `App\Nope`, which is not an Eloquent model class, so it was not loaded.']],
    'no type' => [4, 'ok', ['type' => 'morph_to', 'record' => null]],
]);

it('MSP15 loads has_one, has_many_through and has_one_through relations', function () {
    pgsqlModelShow();

    $envelope = ModelShowSchema::call(['model' => 'customer', 'key' => 1, 'with' => ['latestOrder', 'items', 'firstItem']]);
    $relations = data_get($envelope, 'data.relations', []);

    expect(data_get($envelope, 'data.record'))->toBe(ModelShowSchema::ADA)
        ->and(data_get($relations, 'latestOrder.type'))->toBe('has_one')
        ->and(data_get($relations, 'latestOrder.record.number'))->toBe('A-4')
        ->and(data_get($relations, 'items.type'))->toBe('has_many_through')
        ->and(data_get($relations, 'items.records', []))->toHaveCount(50)
        ->and(data_get($relations, 'items.records.0'))->toBe(ModelShowSchema::ITEMS_OF_1[0])
        ->and(data_get($relations, 'items.truncated'))->toBeTrue()
        ->and(data_get($relations, 'firstItem'))->toBe(['type' => 'has_one_through', 'record' => ModelShowSchema::ITEMS_OF_1[0]])
        ->and($envelope['status'])->toBe('partial');
});

it('MSP16 is unavailable for a denied table and for a table whose primary key the role cannot read', function (string $model, string $key, string $note) {
    pgsqlModelShow();

    expect(ModelShowSchema::call(['model' => $model, 'key' => $key]))->toMatchArray(['status' => 'unavailable', 'note' => $note]);
})->with([
    'sessions (denied)' => ['session', 'abc', 'Model `session` maps to table `sessions`, which the read-only role cannot read.'],
    'key_rows.api_key (masked)' => ['apikey', 'k-1', 'Model `apikey` needs column `api_key` of table `key_rows`, which the read-only role cannot read.'],
    'a SoftDeletes column (masked)' => ['softuser', '1', 'Model `softuser` needs column `remember_token` of table `users`, which the read-only role cannot read.'],
]);

it('MSP17 keeps record data out of the audit; the params are there', function () {
    pgsqlModelShow();

    ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['items']]);
    $line = implode("\n", AuditLog::lines());

    expect(AuditLog::entries()[0])->toMatchArray([
        'tool' => 'model_show',
        'decision' => 'allowed',
        'outcome' => 'success',
        'params' => ['model' => 'order', 'key' => 1, 'with' => ['items']],
    ])
        ->and($line)->not->toContain('Widget')
        ->and($line)->not->toContain('A-1');
});

it('MSP18 checks the table before the key: an unreadable table is unavailable even for a key that cannot match', function () {
    pgsqlModelShow();

    expect(ModelShowSchema::call(['model' => 'session', 'key' => 'x']))->toMatchArray(['status' => 'unavailable'])
        ->and(ModelShowSchema::call(['model' => 'order', 'key' => 'abc']))->toMatchArray(['status' => 'empty']);
});

it('MSP19 does not load a relation whose parent key column or related table the role cannot read', function () {
    pgsqlModelShow();

    $customer = ModelShowSchema::call(['model' => 'customer', 'key' => 1, 'with' => ['byToken']]);
    $order = ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['sessions']]);

    expect($customer)->toMatchArray([
        'status' => 'partial',
        'note' => 'Relation `byToken` needs column `api_token` of table `users`, which the read-only role cannot read.',
    ])
        ->and($order)->toMatchArray([
            'status' => 'partial',
            'note' => 'Relation `sessions` reads table `sessions`, which the read-only role cannot read.',
        ]);
});

it('MSP20 never reads a relation outside frame 2: a session lost after frame 1 is unavailable, not a read on a new session', function () {
    pgsqlModelShow();
    ModelShowSchema::loseSessionInFrameOne('testing_pgsql');
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    expect(ModelShowSchema::call(['model' => 'order', 'key' => 1, 'with' => ['items']]))
        ->toMatchArray(['status' => 'unavailable', 'note' => ReadOnlySession::SESSION_LOST, 'data' => null])
        ->and(preg_grep('/from "order_items"/', $sql))->toBe([]);
});

it('MSP21 is unavailable, not empty, when an accessor after the lookup gets 22P02 (Q4 is about the lookup only)', function () {
    pgsqlModelShow(['runtime-mcp.models' => [...ModelShowSchema::MODELS, 'refused' => ['class' => RefusedAccessorOrder::class, 'appends' => ['refused']]]]);
    Exceptions::fake();

    expect(ModelShowSchema::call(['model' => 'refused', 'key' => 1]))->toMatchArray([
        'status' => 'unavailable',
        'note' => 'The database refused the read of `refused` (SQLSTATE 22P02).',
    ]);

    Exceptions::assertReported(QueryFailed::class);
});
