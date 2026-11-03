<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Core\Tools;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;
use Throwable;

/**
 * Spec 6.1, 5.6: safe facts only. Versions, driver names, boolean flags, plugin names and package
 * versions. Never a config value, a URL or a credential: every field below is named on purpose, and
 * an added field is a new chance to leak.
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class AppInfo extends RuntimeTool
{
    /** Spec 6.1: the related packages, always listed, null when not installed. */
    public const PACKAGES = [
        'laravel/horizon',
        'laravel/pulse',
        'laravel/pennant',
        'laravel/telescope',
        'filament/filament',
        'laravel/octane',
        'laravel/sanctum',
        'laravel/passport',
        'spatie/laravel-activitylog',
        'spatie/laravel-settings',
        'spatie/laravel-permission',
        'spatie/laravel-health',
        'spatie/laravel-backup',
    ];

    /** A driver or channel name. Anything else (a class name, a URL) is not returned. /D refuses "redis\n". */
    public const DRIVER_PATTERN = '/^[a-z0-9_-]{1,32}$/iD';

    public const UNKNOWN = 'unknown';

    public const DEBUG_WARNING = 'APP_DEBUG is true in a strict environment. Error pages can show secrets to visitors.';

    /** %s is the exception class. Never the message: it can hold a host. */
    public const MAINTENANCE_WARNING = 'Could not read the maintenance mode (%s).';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly Environment $environment,
        private readonly RuntimeServer $server,
        private readonly PluginRegistry $plugins,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function name(): string
    {
        return 'app_info';
    }

    public function description(): string
    {
        return 'Facts about the running Laravel application: app name, environment, versions, driver names, debug '
            .'and cache flags, enabled Runtime MCP plugins, and installed versions of related packages. Takes no '
            .'input. Never returns configuration values, URLs or credentials.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $warnings = [];
        $debug = (bool) $this->config->get('app.debug');

        if ($debug && $this->environment->isStrict()) {
            $warnings[] = self::DEBUG_WARNING;
        }

        try {
            $maintenance = $this->app->isDownForMaintenance();
        } catch (Throwable $e) {
            // Q4: a cache-based maintenance mode with its store down. The other facts still help.
            $this->exceptions->report($e);
            $maintenance = null;
            $warnings[] = sprintf(self::MAINTENANCE_WARNING, $e::class);
        }

        return ToolResult::ok([
            'app' => [
                'name' => $this->server->appName(),
                'environment' => $this->environment->name(),
                'strict' => $this->environment->isStrict(),
            ],
            'versions' => [
                'runtime_mcp' => $this->server->version(),
                'laravel' => $this->app->version(),
                'php' => PHP_VERSION,
            ],
            'drivers' => [
                'cache' => $this->driverOf('cache', 'stores', 'driver'),
                'queue' => $this->driverOf('queue', 'connections', 'driver'),
                'session' => self::driverName($this->config->get('session.driver')),
                'mail' => $this->driverOf('mail', 'mailers', 'transport'),
                'database' => $this->driverOf('database', 'connections', 'driver'),
                'log_channel' => self::driverName($this->config->get('logging.default')),
            ],
            'flags' => [
                'debug' => $debug,
                'maintenance_mode' => $maintenance,
                'config_cached' => $this->app instanceof CachesConfiguration && $this->app->configurationIsCached(),
                'routes_cached' => $this->app instanceof CachesRoutes && $this->app->routesAreCached(),
            ],
            'warnings' => $warnings,
            'plugins' => array_map(
                fn (Plugin $plugin): array => ['name' => $plugin->name(), 'description' => $plugin->description()],
                $this->plugins->active(),
            ),
            'packages' => self::packages(),
        ]);
    }

    /**
     * The driver of the default entry: "<section>.<entries>.<the value of <section>.default>.<key>".
     * The default name is looked up as one key, so a name with a dot reads no nested path.
     */
    private function driverOf(string $section, string $entries, string $key): string
    {
        $default = $this->config->get("{$section}.default");
        $all = $this->config->get("{$section}.{$entries}");

        if (! is_string($default) || ! is_array($all) || ! is_array($all[$default] ?? null)) {
            return self::UNKNOWN;
        }

        return self::driverName($all[$default][$key] ?? null);
    }

    private static function driverName(mixed $value): string
    {
        return is_string($value) && preg_match(self::DRIVER_PATTERN, $value) === 1 ? $value : self::UNKNOWN;
    }

    /**
     * Q2: an installed package with no pretty version (replaced or provided) is "unknown"; null keeps
     * one meaning, "not installed". isInstalled() first: getPrettyVersion() throws for a missing package.
     *
     * @return array<string, string|null>
     */
    private static function packages(): array
    {
        $packages = [];

        foreach (self::PACKAGES as $package) {
            $packages[$package] = InstalledVersions::isInstalled($package)
                ? InstalledVersions::getPrettyVersion($package) ?? self::UNKNOWN
                : null;
        }

        return $packages;
    }
}
