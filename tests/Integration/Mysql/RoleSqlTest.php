<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;

beforeEach(fn () => Databases::requiresMysql());

/** The driver error code the role gets (1142 table, 1143 column), or null when the statement runs. */
function mysqlRoleErrorCode(string $sql): ?int
{
    try {
        DB::connection(ReadOnlyRole::CONNECTION)->statement($sql);
    } catch (QueryException $e) {
        $code = $e->errorInfo[1] ?? null;

        return is_int($code) ? $code : -1;
    }

    return null;
}

function mysqlRoleExists(string $name): bool
{
    return DB::connection('testing_mysql')->selectOne('select 1 from mysql.user where user = ?', [$name]) !== null;
}

it('reads the granted tables and columns', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_mysql');
    $db = DB::connection(ReadOnlyRole::CONNECTION);

    expect($db->select('SELECT id, email FROM users'))->toHaveCount(1)
        ->and($db->select('SELECT id, name, email, created_at, updated_at FROM users'))->toHaveCount(1)
        ->and($db->select('SELECT * FROM failed_jobs'))->toHaveCount(1)
        ->and($db->select('SELECT * FROM orders'))->toHaveCount(1)
        ->and($db->select('SELECT o.number, i.product_name FROM orders o JOIN order_items i ON i.order_id = o.id'))->toHaveCount(1);
});

it('denies a masked column, a denied table, the view and every write', function (string $sql, int $code) {
    ReadOnlyRole::setUp(currentTest(), 'testing_mysql');

    expect(mysqlRoleErrorCode($sql))->toBe($code);
})->with([
    'a masked column' => ['SELECT password FROM users', 1143],
    'the api_token column' => ['SELECT api_token FROM users', 1143],
    'JSON_OBJECT of a masked column' => ["SELECT JSON_OBJECT('p', password) FROM users", 1143],
    'a subquery' => ['SELECT (SELECT remember_token FROM users LIMIT 1)', 1143],
    'SELECT * on a table with a masked column' => ['SELECT * FROM users', 1142],
    'the sessions table' => ['SELECT * FROM sessions', 1142],
    'the jobs table' => ['SELECT * FROM jobs', 1142],
    'the cache table' => ['SELECT `key` FROM cache', 1142],
    'the view' => ['SELECT * FROM user_secrets', 1142],
    'an insert' => ["INSERT INTO orders (user_id, number, total, status) VALUES (1, 'B-1', 1, 'paid')", 1142],
    'an update' => ["UPDATE orders SET status = 'refunded'", 1142],
    'a delete' => ['DELETE FROM order_items', 1142],
]);

it('drops the user, and a second drop does nothing', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_mysql');

    expect(mysqlRoleExists($role->name()))->toBeTrue();

    $role->drop();
    $role->drop();

    expect(mysqlRoleExists($role->name()))->toBeFalse();
});

it('drops the user when the test ends', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_mysql');
    $GLOBALS['runtime_mcp_mysql_role_left'] = $role->name();

    expect(mysqlRoleExists($role->name()))->toBeTrue();
});

it('left no user behind from the test before', function () {
    $name = $GLOBALS['runtime_mcp_mysql_role_left'] ?? null;

    if (! is_string($name)) {
        Assert::markTestSkipped('Runs only after "drops the user when the test ends".');
    }

    expect(mysqlRoleExists($name))->toBeFalse();
});

it('still connects as the user after another bootWith()', function () {
    $role = ReadOnlyRole::setUp(currentTest(), 'testing_mysql');
    currentTest()->bootWith([...$role->bootConfig(), 'app.name' => 'Rebooted']);

    expect(DB::connection(ReadOnlyRole::CONNECTION)->scalar('SELECT CURRENT_USER()'))->toBe($role->name().'@%')
        ->and(config('runtime-mcp.database.connection'))->toBe(ReadOnlyRole::CONNECTION);
});

it('gives the user a name MySQL accepts and the privileged connection settings', function () {
    $first = ReadOnlyRole::create(currentTest(), 'testing_mysql');
    $second = ReadOnlyRole::create(currentTest(), 'testing_mysql');
    $privileged = config('database.connections.testing_mysql');
    assert(is_array($privileged));

    expect(strlen($first->name()))->toBeLessThanOrEqual(32)
        ->and($first->name())->toMatch('/^runtime_mcp_ro_[0-9a-f]{8}$/')
        ->and($second->name())->not->toBe($first->name())
        ->and($first->config()['username'])->toBe($first->name())
        ->and($first->config()['password'])->not->toBe($privileged['password'])
        ->and($first->config()['driver'])->toBe($privileged['driver'])
        ->and($first->config()['host'])->toBe($privileged['host'])
        ->and($first->config()['port'])->toBe($privileged['port'])
        ->and($first->config()['database'])->toBe($privileged['database'])
        ->and($first->config()['charset'])->toBe($privileged['charset']);
});

it('grants a table that $extend adds, and not a table added after setUp()', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_mysql', extend: function (string $privileged): void {
        DB::connection($privileged)->statement('CREATE TABLE widgets (id integer, name varchar(20))');
        DB::connection($privileged)->statement("INSERT INTO widgets VALUES (1, 'w')");
    });

    DB::connection('testing_mysql')->statement('CREATE TABLE gadgets (id integer)');

    expect(DB::connection(ReadOnlyRole::CONNECTION)->select('SELECT id, name FROM widgets'))->toHaveCount(1)
        ->and(mysqlRoleErrorCode('SELECT id FROM gadgets'))->toBe(1142);
});
