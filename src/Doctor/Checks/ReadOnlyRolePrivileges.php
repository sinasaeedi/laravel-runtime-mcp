<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Grants\MySqlPrivileges;
use SinaSaeedi\RuntimeMcp\Security\Grants\PostgresPrivileges;
use SinaSaeedi\RuntimeMcp\Security\Grants\PrivilegeFinding;
use SinaSaeedi\RuntimeMcp\Security\Grants\RolePrivileges;
use Throwable;

/**
 * Spec 5.1, 7.3: asks the database directly what the read-only role may do. Three results: write
 * privileges, superuser rights, readable masked columns and denied tables. Every Fail sets the
 * privilege flag, so a relaxed environment shows it as a warning and exits 0 (spec 4.6).
 *
 * It reads; it never changes a grant. The fix lines are SQL for the operator to review and run.
 * No result ever holds the connection name, the host, the user of a failed login or a password.
 */
final class ReadOnlyRolePrivileges implements Check
{
    public const DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    /** At most this many items per result, then "and N more"; the same for the fix lines. */
    public const MAX_ITEMS = 20;

    public function __construct(
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
        private readonly DatabaseManager $db,
    ) {}

    public function title(): string
    {
        return 'Read-only role';
    }

    public function run(): array
    {
        if (! $this->needed()) {
            return [CheckResult::pass('Not needed: no active plugin reads the database.')];
        }

        $name = $this->config->get('runtime-mcp.database.connection');

        // The database.connection boot rule (01-10) already reports this case; no second failure.
        if (! is_string($name) || trim($name) === '' || $name === $this->config->get('database.default')) {
            return [CheckResult::warn('Not checked until RUNTIME_MCP_DB_CONNECTION names a separate connection (see Boot rules).')];
        }

        // As DatabaseManager::configuration() finds it: Arr::get() (a dot reads a nested key), and a
        // url can set the driver (ConfigurationUrlParser). Neither opens a connection.
        $connections = $this->config->get('database.connections');
        $settings = is_array($connections) ? Arr::get($connections, $name) : null;

        if (! is_array($settings) && ! is_string($settings)) {
            return [self::fail(
                'RUNTIME_MCP_DB_CONNECTION names a connection that is not in config/database.php.',
                'Add the read-only connection to config/database.php, or fix RUNTIME_MCP_DB_CONNECTION.',
            )];
        }

        $driver = (new ConfigurationUrlParser)->parseConfiguration(is_array($settings) ? self::named($settings) : $settings)['driver'] ?? null;

        if (! in_array($driver, self::DRIVERS, true)) {
            $shown = is_string($driver) && preg_match('/^[a-z0-9_]{1,32}$/D', $driver) === 1 ? "the `{$driver}` driver" : 'a driver';

            return [self::fail(
                "Cannot check the read-only role: the read-only connection uses {$shown}. Only PostgreSQL, MySQL and MariaDB have the grants that hide a column.",
                'Point RUNTIME_MCP_DB_CONNECTION at a PostgreSQL, MySQL or MariaDB connection.',
            )];
        }

        try {
            $connection = $this->db->connection($name);
            $connection->getPdo();
        } catch (Throwable $e) {
            $this->db->purge($name);

            return [self::fail(
                'Cannot connect with the read-only connection: '.$e::class.self::sqlState($e).'.',
                'Check the host, the user and the password of the read-only connection.',
            )];
        }

        try {
            return $this->checks($connection, $driver);
        } finally {
            // MySqlPrivileges changes the session role; a fresh connection forgets it.
            $this->db->purge($name);
        }
    }

    /** @return list<CheckResult> */
    private function checks(Connection $connection, string $driver): array
    {
        $privileges = $driver === 'pgsql'
            ? new PostgresPrivileges($connection)
            // The default connection is resolved inside check 3: a lookup that throws here would leave run().
            : new MySqlPrivileges($connection, fn (): Connection => $this->db->connection());

        return [
            $this->result(
                'Write privileges',
                fn (): array => $privileges->writes(),
                'No write privilege on any table.',
                'The read-only role can write or change the schema: ',
            ),
            $this->result(
                'Superuser rights',
                fn (): array => $privileges->superuser(),
                'No superuser rights.',
                'The read-only role has superuser rights: ',
            ),
            $this->readable($privileges, $connection, $driver),
        ];
    }

    private function readable(RolePrivileges $privileges, Connection $connection, string $driver): CheckResult
    {
        try {
            $mask = ColumnMask::fromConfig($this->config);
            $denied = DeniedTables::fromConfig($this->config);
        } catch (InvalidGrantConfig) {
            // The Mask lists check (01-12) fails for it; no second failure.
            return CheckResult::warn('Masked columns not checked: a mask list is not an array (see Mask lists).');
        }

        return $this->result(
            'Masked columns',
            function () use ($privileges, $connection, $driver, $mask, $denied): array|CheckResult {
                $app = $this->db->connection();

                // MySQL lists what exists through the app's connection; it must be the same database.
                if ($driver !== 'pgsql' && (! in_array($app->getDriverName(), ['mysql', 'mariadb'], true) || $app->getDatabaseName() !== $connection->getDatabaseName())) {
                    return self::fail(
                        'Masked columns not checked: the read-only connection and the default connection name different databases, so the masked columns cannot be matched.',
                        'Point both connections at the same database (a read replica keeps its name).',
                    );
                }

                return $privileges->readable($mask, $denied, $app->getTablePrefix());
            },
            'No masked column and no denied table is readable.',
            'The read-only role can read masked columns or denied tables: ',
        );
    }

    /** @param  Closure(): (list<PrivilegeFinding>|CheckResult)  $findings */
    private function result(string $what, Closure $findings, string $pass, string $failure): CheckResult
    {
        try {
            $found = $findings();
        } catch (Throwable $e) {
            // Not Doctor's own catch: that Fail has no privilege flag, so it would exit 1 in a relaxed environment.
            return self::fail("{$what} could not be checked: ".$e::class.'.', 'Run doctor again; if it fails again, check the read-only connection.');
        }

        if ($found instanceof CheckResult) {
            return $found;
        }

        if ($found === []) {
            return CheckResult::pass($pass);
        }

        $items = array_values(array_unique(array_map(fn (PrivilegeFinding $finding): string => $finding->item, $found)));
        $fixes = array_values(array_unique(array_map(fn (PrivilegeFinding $finding): string => $finding->fix, $found)));

        return self::fail(
            $failure.self::limit($items, ', ').'.',
            self::limit($fixes, "\n      ")."\n      Run doctor again after the fix: it shows what is left.",
        );
    }

    /** @param  list<string>  $lines */
    private static function limit(array $lines, string $glue): string
    {
        $shown = array_slice($lines, 0, self::MAX_ITEMS);
        $more = count($lines) - count($shown);

        return implode($glue, $shown).($more > 0 ? "{$glue}and {$more} more" : '');
    }

    private function needed(): bool
    {
        foreach ($this->plugins->active() as $plugin) {
            if ($plugin->requiresReadOnlyConnection()) {
                return true;
            }
        }

        return false;
    }

    /** ", SQLSTATE 08006": the five-character code only. The rest of a driver message names the host and the user. */
    private static function sqlState(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $current->getMessage(), $match) === 1) {
                return ', SQLSTATE '.$match[1];
            }
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function named(array $settings): array
    {
        return array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function fail(string $message, string $fix): CheckResult
    {
        return new CheckResult(CheckStatus::Fail, $message, $fix, privilege: true);
    }
}
