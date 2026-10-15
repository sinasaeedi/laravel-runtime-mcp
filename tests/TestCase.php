<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SinaSaeedi\RuntimeMcp\RuntimeMcpServiceProvider;
use SinaSaeedi\RuntimeMcp\Tests\Support\OutboundGuard;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // Before the app boots, so code that runs while the providers boot is covered too.
        OutboundGuard::install();

        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            OutboundGuard::uninstall();
        }
    }

    /**
     * Testbench ignores package discovery by default, so list both providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            RuntimeMcpServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('app.name', 'Runtime Test');
        $config->set('app.url', 'https://app.test');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('database.connections.testing_pgsql', [
            'driver' => 'pgsql',
            'host' => self::variable('RUNTIME_MCP_TEST_PGSQL_HOST', '127.0.0.1'),
            'port' => self::variable('RUNTIME_MCP_TEST_PGSQL_PORT', '55432'),
            'database' => self::variable('RUNTIME_MCP_TEST_PGSQL_DATABASE', 'runtime_mcp'),
            'username' => self::variable('RUNTIME_MCP_TEST_PGSQL_USERNAME', 'postgres'),
            'password' => self::variable('RUNTIME_MCP_TEST_PGSQL_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $config->set('database.connections.testing_mysql', [
            'driver' => self::variable('RUNTIME_MCP_TEST_MYSQL_DRIVER', 'mysql'), // 'mysql' or 'mariadb'
            'host' => self::variable('RUNTIME_MCP_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => self::variable('RUNTIME_MCP_TEST_MYSQL_PORT', '53306'),
            'database' => self::variable('RUNTIME_MCP_TEST_MYSQL_DATABASE', 'runtime_mcp'),
            'username' => self::variable('RUNTIME_MCP_TEST_MYSQL_USERNAME', 'root'),
            'password' => self::variable('RUNTIME_MCP_TEST_MYSQL_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }

    private static function variable(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
