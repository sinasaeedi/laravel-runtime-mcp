<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;

/**
 * Spec 4.2, 01-03: an unknown key in runtime-mcp.plugins does nothing, and a plugins value that is
 * not an array turns every plugin off. Both are warnings. "Enabled but not installed" is a boot rule.
 */
final class Plugins implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
    ) {}

    public function title(): string
    {
        return 'Plugins';
    }

    public function run(): array
    {
        if (! is_array($this->config->get('runtime-mcp.plugins'))) {
            return [CheckResult::warn(
                'runtime-mcp.plugins is not an array, so every plugin is off.',
                'Set runtime-mcp.plugins to an array of plugin name => true or false.',
            )];
        }

        $results = [];

        foreach ($this->plugins->unknownKeys() as $key) {
            // Print the key only when it looks like a plugin name: never an arbitrary config string.
            $results[] = CheckResult::warn(
                preg_match('/^[a-z0-9_]{1,64}$/D', $key) === 1
                    ? "No plugin named `{$key}` in this version."
                    : 'runtime-mcp.plugins has a key that no plugin in this version owns.',
                'Remove the key, or update the package if the plugin is newer.',
            );
        }

        if ($results !== []) {
            return $results;
        }

        $names = array_map(fn (Plugin $plugin): string => $plugin->name(), $this->plugins->active());

        return [CheckResult::pass($names === [] ? 'No plugin is active.' : 'Active: '.implode(', ', $names).'.')];
    }
}
