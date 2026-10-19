<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

beforeEach(fn () => Databases::requiresPostgres());

/** The SQLSTATE of the error the role gets, or null when the statement runs. */
function pgsqlRoleSqlState(string $sql): ?string
{
    try {
        DB::connection(ReadOnlyRole::CONNECTION)->statement($sql);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

function pgsqlRoleExists(string $name): bool
{
    return DB::connection('testing_pgsql')->selectOne('select 1 from pg_roles where rolname = ?', [$name]) !== null;
}

it('reads the granted tables and columns', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql');
    $db = DB::connection(ReadOnlyRole::CONNECTION);

    expect($db->select('SELECT id, email FROM users'))->toHaveCount(1)
        ->and($db->select('SELECT id, name, email, created_at, updated_at FROM users'))->toHaveCount(1)
        ->and($db->select('SELECT * FROM failed_jobs'))->toHaveCount(1)
        ->and($db->select('SELECT * FROM orders'))->toHaveCount(1)
        ->and($db->select('SELECT o.number, i.product_name FROM orders o JOIN order_items i ON i.order_id = o.id'))->toHaveCount(1);
});

it('denies a masked column, a denied table, the view and every write', function (string $sql) {
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql');

    expect(pgsqlRoleSqlState($sql))->toBe('42501');
})->with([
    'a masked column' => ['SELECT password FROM users'],
    'the api_token column' => ['SELECT api_token FROM users'],
    'SELECT * on a table with a masked column' => ['SELECT * FROM users'],
    'row_to_json' => ['SELECT row_to_json(u) FROM users u'],
    'to_jsonb of the whole row' => ['SELECT to_jsonb(u.*) FROM users u'],
    'a whole-row reference' => ['SELECT u FROM users u'],
    'a subquery' => ['SELECT (SELECT remember_token FROM users LIMIT 1)'],
    'the sessions table' => ['SELECT * FROM sessions'],
    'the jobs table' => ['SELECT * FROM jobs'],
    'the cache table' => ['SELECT key FROM cache'],
    'the view' => ['SELECT * FROM user_secrets'],
    'an insert' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1, 'paid')"],
    'an update' => ["UPDATE orders SET status = 'refunded'"],
    'a delete' => ['DELETE FROM order_items'],
]);

it('reads a partitioned table through its parent only, and never a masked column', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', extend: function (string $privileged): void {
        $db = DB::connection($privileged);
        $db->statement('CREATE TABLE events (id integer, happened_on date, client_secret text) PARTITION BY RANGE (happened_on)');
        $db->statement("CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')");
        $db->statement("INSERT INTO events VALUES (1, '2026-10-19', 's')");
    });

    expect(DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT id, happened_on FROM events'))->toHaveCount(1)
        ->and(pgsqlRoleSqlState('SELECT client_secret FROM events'))->toBe('42501')
        ->and(pgsqlRoleSqlState('SELECT id FROM events_2026'))->toBe('42501');
});

it('drops the role, and a second drop does nothing', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_pgsql');

    expect(pgsqlRoleExists($role->name()))->toBeTrue();

    $role->drop();
    $role->drop();

    expect(pgsqlRoleExists($role->name()))->toBeFalse();
});

it('drops the role when the test ends', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_pgsql');
    $GLOBALS['runtime_mcp_pgsql_role_left'] = $role->name();

    expect(pgsqlRoleExists($role->name()))->toBeTrue();
});

it('left no role behind from the test before', function () {
    $name = $GLOBALS['runtime_mcp_pgsql_role_left'] ?? null;

    if (! is_string($name)) {
        Assert::markTestSkipped('Runs only after "drops the role when the test ends".');
    }

    expect(pgsqlRoleExists($name))->toBeFalse();
});

it('still connects as the role after another bootWith()', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_pgsql');
    currentTest()->bootWith([...$role->bootConfig(), 'app.name' => 'Rebooted']);

    expect(DB::connection(ReadOnlyRole::CONNECTION)->scalar('select current_user'))->toBe($role->name())
        ->and(config('runtime-mcp.database.connection'))->toBe(ReadOnlyRole::CONNECTION);
});

it('gives the role a short unique name and the privileged connection settings', function () {
    $first = ReadOnlyRole::create(currentTest(), 'testing_pgsql');
    $second = ReadOnlyRole::create(currentTest(), 'testing_pgsql');
    $privileged = config('database.connections.testing_pgsql');
    assert(is_array($privileged));

    expect(strlen($first->name()))->toBeLessThanOrEqual(32)
        ->and($first->name())->toMatch('/^runtime_mcp_ro_[0-9a-f]{8}$/')
        ->and($second->name())->not->toBe($first->name())
        ->and($first->config()['username'])->toBe($first->name())
        ->and($first->config()['password'])->not->toBe($privileged['password'])
        ->and($first->config()['host'])->toBe($privileged['host'])
        ->and($first->config()['port'])->toBe($privileged['port'])
        ->and($first->config()['database'])->toBe($privileged['database'])
        ->and($first->config()['search_path'])->toBe($privileged['search_path'])
        ->and($first->bootConfig())->toBe([
            'database.connections.runtime_mcp_ro' => $first->config(),
            'runtime-mcp.database.connection' => 'runtime_mcp_ro',
        ]);
});

it('grants a table that $extend adds, and not a table added after setUp()', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', extend: function (string $privileged): void {
        DB::connection($privileged)->statement('CREATE TABLE widgets (id integer, name text)');
        DB::connection($privileged)->statement("INSERT INTO widgets VALUES (1, 'w')");
    });

    DB::connection('testing_pgsql')->statement('CREATE TABLE gadgets (id integer)');

    expect(DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT id, name FROM widgets'))->toHaveCount(1)
        ->and(pgsqlRoleSqlState('SELECT id FROM gadgets'))->toBe('42501');
});
