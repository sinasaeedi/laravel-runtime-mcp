<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SinaSaeedi\RuntimeMcp\RuntimeMcpServiceProvider;
use SinaSaeedi\RuntimeMcp\Tests\Support\McpRequests;
use SinaSaeedi\RuntimeMcp\Tests\Support\OutboundGuard;

abstract class TestCase extends Orchestra
{
    use McpRequests;

    /**
     * Config that must be in place before the providers boot (01-02). Set it with bootWith().
     *
     * @var array<string, mixed>
     */
    private array $configBeforeBoot = [];

    /** The environment name for the next boot, or null for phpunit.xml's APP_ENV. */
    private ?string $environmentBeforeBoot = null;

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

        // Last, so a test's bootWith() values win over the defaults above.
        foreach ($this->configBeforeBoot as $key => $value) {
            $config->set($key, $value);
        }

        if ($this->environmentBeforeBoot !== null) {
            $environment = $this->environmentBeforeBoot;
            $app->detectEnvironment(fn (): string => $environment);
        }
    }

    /**
     * Boots a fresh app with this config (and environment) in place before the providers boot.
     * The values stay for every later refreshApplication() in the same test. The API keys hold the
     * default test key (01-06), unless $config sets runtime-mcp.auth.api_keys itself.
     *
     * @param  array<string, mixed>  $config
     */
    public function bootWith(array $config = [], ?string $environment = null): void
    {
        $this->configBeforeBoot = ['runtime-mcp.auth.api_keys' => self::defaultKeyEntry(), ...$config];
        $this->environmentBeforeBoot = $environment;

        $this->rebootApplication();
    }

    /**
     * A fresh app instance with the same bootWith() values, as on a second server behind a load
     * balancer. refreshApplication() is protected, so Pest closures call this one.
     */
    public function rebootApplication(): void
    {
        $this->refreshApplication();

        // The new app has a new facade root, so the guard from setUp() must be set again.
        Http::preventStrayRequests();
    }

    /**
     * A relative URI goes to app.url (https://app.test), the host that 01-07 allows by default.
     * Without this, every test request goes to http://localhost: Testbench builds its console
     * request before defineEnvironment() sets app.url. A full URL (https://evil.example/mcp) is sent
     * as it is. That is the only way to choose the Host in a test: Request::create() overwrites a
     * Host header with the host of the URL.
     *
     * @param  Uri|string  $uri
     */
    protected function prepareUrlForRequest($uri): string
    {
        $uri = $uri instanceof Uri ? $uri->value() : $uri;

        if (str_contains($uri, '://')) {
            return $uri;
        }

        $root = config('app.url');

        return (is_string($root) ? rtrim($root, '/') : '').'/'.ltrim($uri, '/');
    }

    private static function variable(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
