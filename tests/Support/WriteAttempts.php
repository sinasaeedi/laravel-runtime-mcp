<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\WriteAttemptPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\WriteAttemptTool;

/**
 * 01-16: serves the write_attempt tool on the real endpoint, behind the whole pipeline, and makes the
 * tables its actions touch. The default connection is the one under test: SQLite `testing`, or the
 * privileged testing_pgsql / testing_mysql, so only the read-only transaction stops a write.
 */
final class WriteAttempts
{
    public const TABLES = ['ro_orders', 'ro_events', 'ro_created', 'cache', 'cache_locks'];

    /** @param  array<string, mixed>  $config  more boot-time config, as for bootWith() */
    public static function serve(array $config = [], ?string $environment = null): void
    {
        currentTest()->bootWith([
            'runtime-mcp.enabled' => true,
            'cache.stores.database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => 'cache_locks'],
            ...$config,
        ], $environment);

        app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
            $app, $app->make(Repository::class), [WriteAttemptPlugin::class],
        ));

        Gates::allow('write_attempt');
        // RunTool reports every blocked write; a real handler would write laravel.log lines.
        Exceptions::fake();
    }

    /**
     * One tools/call of write_attempt over HTTP; the envelope.
     *
     * @return array<array-key, mixed>
     */
    public static function call(string $action): array
    {
        return FixtureTools::envelope(FixtureTools::call('write_attempt', ['action' => $action]));
    }

    public static function ran(): bool
    {
        return app()->bound(WriteAttemptTool::RAN);
    }

    /** Drops and makes the tables, with one order (id 1), on $connection. */
    public static function tables(string $connection): void
    {
        self::drop($connection);
        $schema = Schema::connection($connection);

        $schema->create('ro_orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('n', 50);
            $table->integer('views')->default(0);
        });
        $schema->create('ro_events', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('what', 50);
        });
        $schema->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        DB::connection($connection)->table('ro_orders')->insert(['n' => 'first']);
    }

    public static function drop(string $connection): void
    {
        foreach (self::TABLES as $table) {
            Schema::connection($connection)->dropIfExists($table);
        }
    }

    public static function count(string $connection, string $table): int
    {
        return DB::connection($connection)->table($table)->count();
    }
}
