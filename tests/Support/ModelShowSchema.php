<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\ApiKeyRow;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Device;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Note;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\OrderPolicy;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\SessionRow;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\SlowOrder;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\SoftUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\User;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\UserPolicy;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\WatchedOrder;

/**
 * 01-21: the schema, rows, config and expected records that tests/Integration/{Pgsql,Mysql}/ModelShowTest.php
 * share. Built with Laravel's schema builder, so one definition serves PostgreSQL, MySQL and MariaDB. The
 * tables exist before 01-11's role, so the role gets their grants (masked columns and denied tables excepted).
 *
 * Rows (01-11 already has user 1 Ada, order 1 A-1 and its item 1 Widget):
 * - users: 2 Blocked (UserPolicy denies this user), 3 Cy.
 * - orders: 1 A-1 (user 1, paid, two items, two notes, two tags, one label, one audit), 2 A-2 (user 2, paid),
 *   3 A-3 (user 1, soft deleted), 4 A-4 (user 1, 120 items), 5 A-5 (user 3, blocked), 6 A-6 (user 3, boom),
 *   7 A-7 (user 3, paid). OrderPolicy denies A-5 and throws for A-6.
 * - notes: 1 and 2 on order 1, 3 names the class App\Nope, 4 has no type.
 */
final class ModelShowSchema
{
    public const DEVICE = '8d4b1c1e-0d55-4c8e-9c2f-9f1d0a9a7b11';

    public const MODELS = [
        'order' => [
            'class' => Order::class,
            'relations' => ['items', 'customer', 'tags', 'notes', 'latestNote', 'labels', 'invoices', 'ledger', 'custom', 'customerByToken', 'sessions'],
            'appends' => ['display_total'],
        ],
        'customer' => ['class' => User::class, 'relations' => ['orders', 'latestOrder', 'items', 'firstItem', 'byToken']],
        'note' => ['class' => Note::class, 'relations' => ['notable']],
        'device' => ['class' => Device::class],
        'apikey' => ['class' => ApiKeyRow::class],
        'session' => ['class' => SessionRow::class],
        'softuser' => ['class' => SoftUser::class],
        'watched' => ['class' => WatchedOrder::class],
        'slow' => ['class' => SlowOrder::class],
    ];

    /** Order 1 as model_show shows it: no internal_note or deleted_at ($hidden), no updated_at (no such column), no margin. */
    public const ORDER_1 = [
        'id' => 1, 'user_id' => 1, 'number' => 'A-1', 'total' => '10.00', 'status' => 'paid',
        'created_at' => '2026-10-01T08:12:44+00:00', 'shipped_on' => '2026-10-02T00:00:00+00:00', 'display_total' => 'EUR 10.00',
    ];

    /** Order 1 as a related record: no appends at all. */
    public const ORDER_1_RELATED = [
        'id' => 1, 'user_id' => 1, 'number' => 'A-1', 'total' => '10.00', 'status' => 'paid',
        'created_at' => '2026-10-01T08:12:44+00:00', 'shipped_on' => '2026-10-02T00:00:00+00:00',
    ];

    /** User 1 with only the readable columns: no password, remember_token or api_token (masked). */
    public const ADA = ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'created_at' => null, 'updated_at' => null];

    public const ITEMS_OF_1 = [
        ['id' => 1, 'order_id' => 1, 'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => '10.00'],
        ['id' => 2, 'order_id' => 1, 'product_name' => 'Gadget', 'quantity' => 2, 'unit_price' => '5.00'],
    ];

    /**
     * 01-11's role, this story's tables and rows, and a server with the model_show Gate and the two Policies.
     *
     * @param  array<string, mixed>  $config
     * @param  (Closure(string): void)|null  $more  more objects, before the role
     */
    public static function serve(string $privileged, array $config = [], ?string $environment = null, ?Closure $more = null): ReadOnlyRole
    {
        $role = ReadOnlyRole::setUp(
            currentTest(),
            $privileged,
            [
                'runtime-mcp.enabled' => true,
                'cache.limiter' => 'array',
                'runtime-mcp.models' => self::MODELS,
                'database.connections.billing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                ...$config,
            ],
            $environment,
            function (string $privileged) use ($more): void {
                self::build($privileged);

                if ($more !== null) {
                    $more($privileged);
                }
            },
        );

        Gates::allow('model_show');
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        return $role;
    }

    /**
     * 01-21 triage: the read-only session dies at the order's view check, inside frame 1. The work still
     * succeeds, frame 1's reset fails, and ReadOnlySession throws the connection away (01-14 Q2), so frame 2
     * runs on a new connection object while the relations built in frame 1 hold the old one.
     */
    public static function loseSessionInFrameOne(string $privileged): void
    {
        $lost = false;

        Gate::before(function (mixed $user, string $ability, array $arguments) use ($privileged, &$lost): ?bool {
            if ($lost || $ability !== 'view' || ! ($arguments[0] ?? null) instanceof Order) {
                return null;
            }

            $lost = true;
            $readOnly = DB::connection(ReadOnlyRole::CONNECTION);
            $killer = DB::connection($privileged);
            $id = $readOnly->scalar($killer->getDriverName() === 'pgsql' ? 'select pg_backend_pid()' : 'select connection_id()');

            if (! is_int($id) && ! is_string($id)) {
                throw new LogicException('The session id of the read-only connection is not a number.');
            }

            $killer->getDriverName() === 'pgsql'
                ? $killer->select('select pg_terminate_backend(?)', [$id])
                : $killer->statement('kill '.(int) $id);

            return null;
        });
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    public static function call(array $arguments, ProtocolEra $era = ProtocolEra::Legacy): array
    {
        return FixtureTools::envelope(FixtureTools::call('model_show', $arguments, $era));
    }

    public static function build(string $privileged): void
    {
        $schema = Schema::connection($privileged);
        $db = DB::connection($privileged);

        $schema->table('orders', function (Blueprint $table): void {
            $table->string('internal_note')->nullable();
            $table->date('shipped_on')->nullable();
            $table->softDeletes();
        });

        $schema->create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $schema->create('order_tag', function (Blueprint $table): void {
            $table->foreignId('order_id');
            $table->foreignId('tag_id');
            $table->string('note')->nullable();
        });

        $schema->create('notes', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('notable');
            $table->string('body');
        });

        $schema->create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $schema->create('labelables', function (Blueprint $table): void {
            $table->foreignId('label_id');
            $table->morphs('labelable');
        });

        $schema->create('audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->string('detail');
        });

        $schema->create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        $schema->create('key_rows', function (Blueprint $table): void {
            $table->string('api_key')->primary();
            $table->string('name');
        });

        $schema->create('model_events', function (Blueprint $table): void {
            $table->id();
            $table->string('what');
        });

        $db->table('users')->insert([
            ['name' => 'Blocked', 'email' => 'blocked@example.com', 'password' => 'hash'],
            ['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'hash'],
        ]);
        $db->table('orders')->where('id', 1)->update([
            'internal_note' => 'call the warehouse', 'shipped_on' => '2026-10-02', 'created_at' => '2026-10-01 08:12:44',
        ]);
        $db->table('orders')->insert([
            ['user_id' => 2, 'number' => 'A-2', 'total' => '20.00', 'status' => 'paid', 'deleted_at' => null],
            ['user_id' => 1, 'number' => 'A-3', 'total' => '30.00', 'status' => 'paid', 'deleted_at' => '2026-10-03 10:00:00'],
            ['user_id' => 1, 'number' => 'A-4', 'total' => '40.00', 'status' => 'paid', 'deleted_at' => null],
            ['user_id' => 3, 'number' => 'A-5', 'total' => '50.00', 'status' => 'blocked', 'deleted_at' => null],
            ['user_id' => 3, 'number' => 'A-6', 'total' => '60.00', 'status' => 'boom', 'deleted_at' => null],
            ['user_id' => 3, 'number' => 'A-7', 'total' => '70.00', 'status' => 'paid', 'deleted_at' => null],
        ]);
        $db->table('order_items')->insert(['order_id' => 1, 'product_name' => 'Gadget', 'quantity' => 2, 'unit_price' => '5.00']);
        $db->table('order_items')->insert(array_map(
            fn (int $n): array => ['order_id' => 4, 'product_name' => "Bulk {$n}", 'quantity' => 1, 'unit_price' => '1.00'],
            range(1, 120),
        ));
        $db->table('tags')->insert([['name' => 'gift'], ['name' => 'rush']]);
        $db->table('order_tag')->insert([['order_id' => 1, 'tag_id' => 1, 'note' => 'wrap'], ['order_id' => 1, 'tag_id' => 2, 'note' => null]]);
        $db->table('notes')->insert([
            ['notable_type' => Order::class, 'notable_id' => 1, 'body' => 'call first'],
            ['notable_type' => Order::class, 'notable_id' => 1, 'body' => 'paid by card'],
            ['notable_type' => 'App\Nope', 'notable_id' => 1, 'body' => 'lost'],
            ['notable_type' => null, 'notable_id' => null, 'body' => 'loose'],
        ]);
        $db->table('labels')->insert(['name' => 'vip']);
        $db->table('labelables')->insert(['label_id' => 1, 'labelable_type' => Order::class, 'labelable_id' => 1]);
        $db->table('audits')->insert(['order_id' => 1, 'detail' => 'created']);
        $db->table('devices')->insert(['id' => self::DEVICE, 'name' => 'phone']);
        $db->table('key_rows')->insert(['api_key' => 'k-1', 'name' => 'first']);
    }
}
