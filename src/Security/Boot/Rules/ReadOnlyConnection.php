<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Security\Environment;

/**
 * Spec 5.11, 4.6: an active plugin that reads the database needs database.connection, and it must
 * not be the default connection. An error in a strict environment, a warning in a relaxed one.
 *
 * Compares connection NAMES from config. Never resolves DB::connection(): that can connect.
 */
final class ReadOnlyConnection implements BootRule
{
    public const ID = 'database.connection';

    public function __construct(
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
        private readonly Environment $environment,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $names = array_map(
            fn (Plugin $plugin): string => "`{$plugin->name()}`",
            array_values(array_filter(
                $this->plugins->active(),
                fn (Plugin $plugin): bool => $plugin->requiresReadOnlyConnection(),
            )),
        );

        if ($names === []) {
            return [];
        }

        $connection = $this->config->get('runtime-mcp.database.connection');
        $need = 'The '.implode(' and ', $names).(count($names) === 1 ? ' plugin needs' : ' plugins need')
            .' a separate read-only connection.';

        // Unset: null, '', blank, or not a string.
        if (! is_string($connection) || trim($connection) === '') {
            return [$this->issue("RUNTIME_MCP_DB_CONNECTION is not set. {$need}")];
        }

        if ($connection === $this->config->get('database.default')) {
            return [$this->issue("RUNTIME_MCP_DB_CONNECTION names `{$connection}`, the default connection. {$need}")];
        }

        return [];
    }

    private function issue(string $message): BootIssue
    {
        return $this->environment->isRelaxed()
            ? BootIssue::warning(self::ID, $message)
            : BootIssue::error(self::ID, $message);
    }
}
