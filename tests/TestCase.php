<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SinaSaeedi\RuntimeMcp\RuntimeMcpServiceProvider;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootThrottle;
use SinaSaeedi\RuntimeMcp\Tests\Support\McpRequests;
use SinaSaeedi\RuntimeMcp\Tests\Support\OutboundGuard;

abstract class TestCase extends Orchestra
{
    use McpRequests;

    /** The read-only connection of every bootWith() (01-10): it exists and is not the default. Boot never opens it. */
    public const READ_ONLY_CONNECTION = 'testing_readonly';

    /**
     * Config that must be in place before the providers boot (01-02). Set it with bootWith().
     *
     * @var array<string, mixed>
     */
    private array $configBeforeBoot = [];

    /** The environment name for the next boot, or null for phpunit.xml's APP_ENV. */
    private ?string $environmentBeforeBoot = null;

    /** @var (Closure(Application): void)|null runs after every provider's register(), before any boot() (01-10) */
    private ?Closure $beforeBoot = null;

    protected function setUp(): void
    {
        // Before the app boots, so code that runs while the providers boot is covered too.
        OutboundGuard::install();

        parent::setUp();

        Http::preventStrayRequests();
        $this->clearBootThrottle();
    }

    protected function tearDown(): void
    {
        try {
            $this->clearBootThrottle();
            parent::tearDown();
        } finally {
            OutboundGuard::uninstall();
        }
    }

    /**
     * Testbench ignores package discovery by default, so list both providers.
     *
     * @param  Application  $app
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
     * @param  Application  $app
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

        $config->set('database.connections.'.self::READ_ONLY_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
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

        if ($this->beforeBoot !== null) {
            $app->booting($this->beforeBoot);
        }
    }

    /**
     * Boots a fresh app with this config (and environment) in place before the providers boot.
     * The values stay for every later refreshApplication() in the same test. The API keys hold the
     * default test key (01-06) and database.connection names READ_ONLY_CONNECTION (01-10), unless
     * $config sets them itself (null = unset).
     *
     * $beforeBoot runs after every provider's register() and before any boot(): bind a recorder or a
     * fake there to see what happens while the providers boot (tests/Support/BootCapture).
     *
     * @param  array<string, mixed>  $config
     * @param  (Closure(Application): void)|null  $beforeBoot
     */
    public function bootWith(array $config = [], ?string $environment = null, ?Closure $beforeBoot = null): void
    {
        $this->configBeforeBoot = [
            'runtime-mcp.auth.api_keys' => self::defaultKeyEntry(),
            'runtime-mcp.database.connection' => self::READ_ONLY_CONNECTION,
            ...$config,
        ];
        $this->environmentBeforeBoot = $environment;
        $this->beforeBoot = $beforeBoot;

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

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri) === 1) {
            return $uri;
        }

        $root = config('app.url');

        return (is_string($root) ? rtrim($root, '/') : '').'/'.ltrim($uri, '/');
    }

    /**
     * The boot throttle writes files under the skeleton's storage; a file left by one test would
     * silence the same report in the next one.
     */
    private function clearBootThrottle(): void
    {
        if ($this->app === null) {
            return;
        }

        $path = $this->app->storagePath(BootThrottle::DIRECTORY);

        // A test of a broken store puts a plain file there.
        File::isFile($path) ? File::delete($path) : File::deleteDirectory($path);
    }

    private static function variable(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
