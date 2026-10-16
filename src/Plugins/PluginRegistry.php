<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;

/**
 * Spec 4.2: which plugins exist, which are on, and which tools register.
 *
 * Bound as scoped (rule 14). Reads the config on every call, never caches it.
 */
final class PluginRegistry
{
    /** The plugins this version knows, in tools/list order. Later releases append to this list. */
    public const PLUGINS = [
        CorePlugin::class,
        ModelsPlugin::class,
        DatabasePlugin::class,
        QueuePlugin::class,
        LogsPlugin::class,
    ];

    /** @param  list<class-string<Plugin>>  $plugins */
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly array $plugins = self::PLUGINS,
    ) {}

    /** @return list<Plugin> every known plugin, built through the container */
    public function all(): array
    {
        $plugins = [];

        foreach ($this->plugins as $class) {
            $plugin = $this->container->make($class);

            if (! $plugin instanceof Plugin) {
                throw new LogicException("[{$class}] does not implement the Plugin contract.");
            }

            $plugins[] = $plugin;
        }

        return $plugins;
    }

    /**
     * On in config: the key is present and its value is exactly true, or the key is absent and the
     * plugin is on by default. A plugins value that is not an array turns every plugin off.
     */
    public function isEnabled(Plugin $plugin): bool
    {
        $settings = $this->config->get('runtime-mcp.plugins');

        if (! is_array($settings)) {
            return false;
        }

        if (! array_key_exists($plugin->name(), $settings)) {
            return $plugin->enabledByDefault();
        }

        return $settings[$plugin->name()] === true;
    }

    /** Its package is installed: requiredPackage() is null, or Composer knows the package. */
    public function isInstalled(Plugin $plugin): bool
    {
        $package = $plugin->requiredPackage();

        return $package === null || InstalledVersions::isInstalled($package);
    }

    /** @return list<Plugin> enabled and installed: the only plugins whose tools register */
    public function active(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Plugin $plugin): bool => $this->isEnabled($plugin) && $this->isInstalled($plugin),
        ));
    }

    /** @return list<Plugin> enabled but not installed: boot validation (01-10) fails on these */
    public function enabledButMissing(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Plugin $plugin): bool => $this->isEnabled($plugin) && ! $this->isInstalled($plugin),
        ));
    }

    /** @return list<string> keys in runtime-mcp.plugins that no known plugin owns (doctor warns, 01-12) */
    public function unknownKeys(): array
    {
        $settings = $this->config->get('runtime-mcp.plugins');

        if (! is_array($settings)) {
            return [];
        }

        $known = array_map(fn (Plugin $plugin): string => $plugin->name(), $this->all());
        $keys = array_map(fn (int|string $key): string => (string) $key, array_keys($settings));

        return array_values(array_diff($keys, $known));
    }

    /** @return list<class-string> the tools of the active plugins, in registry order */
    public function tools(): array
    {
        $tools = [];

        foreach ($this->active() as $plugin) {
            foreach ($plugin->tools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
