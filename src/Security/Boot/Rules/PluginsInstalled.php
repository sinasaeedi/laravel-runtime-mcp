<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 4.2, 5.11: the operator enabled a plugin whose Composer package is not installed.
 */
final class PluginsInstalled implements BootRule
{
    public const ID = 'plugins.installed';

    public function __construct(private readonly PluginRegistry $plugins) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $issues = [];

        foreach ($this->plugins->enabledButMissing() as $plugin) {
            $package = (string) $plugin->requiredPackage();
            $issues[] = BootIssue::error(self::ID, "Plugin `{$plugin->name()}` is enabled, but `{$package}` is not installed.");
        }

        return $issues;
    }
}
