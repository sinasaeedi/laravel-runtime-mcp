<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/**
 * A real read-only role for the database tests (01-11), made from RoleSqlGenerator's own SQL. Every
 * later database story uses it, mostly through setUp().
 *
 * $privileged is a connection name (testing_pgsql or testing_mysql), not a driver: testing_mysql is
 * mysql or mariadb in CI. An instance holds the role; there is no static state.
 */
final class ReadOnlyRole
{
    /** The connection name of every role. Fixed, so a test can name it in bootWith() before the role exists. */
    public const CONNECTION = 'runtime_mcp_ro';

    private bool $dropped = false;

    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly string $privileged,
        private readonly string $driver,
        private readonly string $name,
        private readonly array $config,
    ) {}

    /** Drops every view and every table on $privileged, then builds and seeds the fixture schema. */
    public static function schema(string $privileged): void
    {
        // A crashed run, or a test that changed a table, must not change the next test.
        $schema = Schema::connection($privileged);
        $schema->dropAllViews();
        $schema->dropAllTables();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('api_token')->nullable();
            $table->timestamps();
        });

        $schema->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('number');
            $table->decimal('total', 10, 2);
            $table->string('status');
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
        });

        $schema->create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $schema->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        $schema->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $schema->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $schema->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        $db = DB::connection($privileged);

        // 01-15 reads it to prove that a view gives no way round the column grant.
        $db->statement('CREATE VIEW user_secrets AS SELECT id, password FROM users');

        // No explicit id: the tables are new, so each first row gets id 1, and a PostgreSQL sequence
        // stays in step for the rows that later stories add.
        $db->table('users')->insert([
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'hash', 'remember_token' => 'r', 'api_token' => 't',
        ]);
        $db->table('orders')->insert(['user_id' => 1, 'number' => 'A-1', 'total' => '10.00', 'status' => 'paid']);
        $db->table('order_items')->insert(['order_id' => 1, 'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => '10.00']);
        $db->table('failed_jobs')->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\SendInvoice"}', 'exception' => 'RuntimeException: boom',
        ]);
    }

    /** Creates a new role from RoleSqlGenerator's SQL, registers CONNECTION, and drops the role in tearDown. */
    public static function create(TestCase $test, string $privileged, ?RoleSqlGenerator $generator = null): self
    {
        $db = DB::connection($privileged);
        $snapshot = SchemaSnapshot::read($db);

        // PostgreSQL roles are cluster-wide, so each role needs its own name. 23 characters; MySQL allows 32.
        $name = 'runtime_mcp_ro_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));

        // From the repository, not $db->getConfig(): the live config also holds 'name' => $privileged.
        $config = config('database.connections.'.$privileged);

        if (! is_array($config)) {
            throw new RuntimeException("Connection [{$privileged}] has no config.");
        }

        $copy = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $copy[$key] = $value;
            }
        }

        $role = new self($privileged, $db->getDriverName(), $name, [...$copy, 'username' => $name, 'password' => $password]);

        // Before the SQL runs: a statement that fails half-way still leaves nothing behind.
        $test->beforeApplicationDestroyed(fn () => $role->drop());

        $sql = ($generator ?? app(RoleSqlGenerator::class))->forDriver($role->driver, $snapshot, $name);
        $sql = str_replace("'CHANGE_ME'", "'{$password}'", $sql);

        // Comments out first, then split on ; at a line end: the PostgreSQL CREATE ROLE spans two lines.
        $lines = array_filter(explode("\n", $sql), fn (string $line): bool => ! str_starts_with($line, '--'));
        $statements = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                self::run($db, $statement);
            }
        }

        config()->set('database.connections.'.self::CONNECTION, $role->config());
        DB::purge(self::CONNECTION);

        return $role;
    }

    /**
     * schema(), then $extend($privileged), then create(), then $test->bootWith([...bootConfig(), ...$config], $environment).
     * One call for later stories. $extend adds the story's own tables, columns and rows.
     *
     * @param  array<string, mixed>  $config
     * @param  (Closure(string): void)|null  $extend
     */
    public static function setUp(TestCase $test, string $privileged, array $config = [], ?string $environment = null, ?Closure $extend = null): self
    {
        self::schema($privileged);

        // The grants come from the snapshot in create(), so a story's own tables must exist before it.
        if ($extend !== null) {
            $extend($privileged);
        }

        $role = self::create($test, $privileged);
        $test->bootWith([...$role->bootConfig(), ...$config], $environment);

        return $role;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> the privileged connection's config with this role's username and password */
    public function config(): array
    {
        return $this->config;
    }

    /** @return array<string, mixed> ['database.connections.runtime_mcp_ro' => config(), 'runtime-mcp.database.connection' => 'runtime_mcp_ro'] */
    public function bootConfig(): array
    {
        return [
            'database.connections.'.self::CONNECTION => $this->config(),
            'runtime-mcp.database.connection' => self::CONNECTION,
        ];
    }

    /** Idempotent: a second call does nothing. */
    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }

        DB::purge(self::CONNECTION);
        $db = DB::connection($this->privileged);

        if ($this->driver === 'pgsql') {
            if ($db->selectOne('select 1 from pg_roles where rolname = ?', [$this->name]) !== null) {
                // DROP OWNED BY also revokes the grants; it must run in the database that holds them.
                $role = RoleSqlGenerator::quotePostgres($this->name);
                self::run($db, "DROP OWNED BY {$role}");
                self::run($db, "DROP ROLE {$role}");
            }
        } else {
            self::run($db, "DROP USER IF EXISTS '{$this->name}'@'%'");
        }

        $this->dropped = true;
    }

    /**
     * What Connection::unprepared() runs, without its literal-string type: the statements here are
     * built from the generator's output and the random role name.
     */
    private static function run(Connection $db, string $sql): void
    {
        $db->getPdo()->exec($sql);
    }
}
