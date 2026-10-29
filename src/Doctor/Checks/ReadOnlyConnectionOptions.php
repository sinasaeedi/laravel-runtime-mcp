<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use PDO;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;

/**
 * Spec 5.2, 5.13, 01-14 re-review (2026-10-29): the options of the connection that ReadOnlySession uses.
 * A persistent PDO makes every package query fail (ReadOnlySession::PERSISTENT); read and write hosts send
 * every query to the write host, because the frame runs it in a transaction. Config only: no connection
 * is opened, and no name or host is printed. A missing connection is 01-13's and the boot rules' to report.
 */
final class ReadOnlyConnectionOptions implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
    ) {}

    public function title(): string
    {
        return 'Read-only connection';
    }

    public function run(): array
    {
        $needed = array_filter($this->plugins->active(), fn (Plugin $plugin): bool => $plugin->requiresReadOnlyConnection());

        if ($needed === []) {
            return [CheckResult::pass('Not needed: no active plugin reads the database.')];
        }

        // As ReadOnlySession: unset gives the default connection (a relaxed environment only, see Boot rules).
        $name = $this->config->get('runtime-mcp.database.connection');
        $name = is_string($name) && trim($name) !== '' ? $name : $this->config->get('database.default');
        $connections = $this->config->get('database.connections');
        $settings = is_string($name) && is_array($connections) ? Arr::get($connections, $name) : null;

        if (! is_array($settings)) {
            return [CheckResult::warn('Not checked until RUNTIME_MCP_DB_CONNECTION names a connection in config/database.php (see Boot rules and Read-only role).')];
        }

        $options = $settings['options'] ?? null;
        $results = [];

        if (is_array($options) && ! empty($options[PDO::ATTR_PERSISTENT])) {
            $results[] = CheckResult::fail(
                'The read-only connection is persistent (PDO::ATTR_PERSISTENT), so every package query on it is refused: a disconnect does not end a persistent session.',
                'Remove PDO::ATTR_PERSISTENT from its options in config/database.php.',
            );
        }

        if (array_key_exists('read', $settings) || array_key_exists('write', $settings)) {
            $results[] = CheckResult::warn(
                'The read-only connection has read and write hosts. Every package query runs in a transaction, so it uses the write host.',
                'To query a read replica, make the replica its only host (no read and write keys).',
            );
        }

        return $results !== [] ? $results : [CheckResult::pass('The read-only connection is not persistent and has one host.')];
    }
}
