<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransactionUnavailable;

/**
 * Spec 4.5, 5.9, 01-16 Part D: every read tool runs in a read-only transaction on the default
 * connection and on each connection in read_only_transactions.connections. A bad entry makes every
 * tool call fail, so it fails here. Config only: no connection is opened. A name is printed only when
 * it has the shape of a name (Sina, 2026-10-20); an entry is named by its 1-based position.
 */
final class ReadOnlyTransactionConnections implements Check
{
    public const FIX = 'List only connections from config/database.php that use PostgreSQL, MySQL, MariaDB or SQLite.';

    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Read-only transactions';
    }

    public function run(): array
    {
        $connections = $this->config->get('database.connections');
        $connections = is_array($connections) ? $connections : [];
        $fails = [];
        $names = [];

        $default = $this->config->get('database.default');

        if (is_string($default) && $default !== '' && Arr::get($connections, $default) !== null) {
            $names[] = $default;
        } else {
            $fails[] = CheckResult::fail('database.default does not name a connection in config/database.php.', self::FIX);
        }

        $listed = $this->config->get('runtime-mcp.read_only_transactions.connections') ?? [];

        if (! is_array($listed) || ! array_is_list($listed)) {
            $fails[] = CheckResult::fail('read_only_transactions.connections is not a list of connection names, so every tool call fails.', self::FIX);
            $listed = [];
        }

        foreach ($listed as $index => $name) {
            $position = $index + 1;

            if (! is_string($name) || $name === '') {
                $fails[] = CheckResult::fail("Entry {$position} of read_only_transactions.connections is not a connection name, so every tool call fails.", self::FIX);
            } elseif (Arr::get($connections, $name) === null) {
                $fails[] = CheckResult::fail("Entry {$position} of read_only_transactions.connections (".ReadOnlyTransactionUnavailable::shown($name).') is not in config/database.php, so every tool call fails.', self::FIX);
            } else {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));

        foreach ($names as $name) {
            $driver = self::driver(Arr::get($connections, $name));

            if (! in_array($driver, ReadOnlyTransaction::DRIVERS, true)) {
                $shown = is_string($driver) && preg_match('/^[a-z0-9_]{1,32}$/D', $driver) === 1 ? "the `{$driver}` driver" : 'a driver';
                $fails[] = CheckResult::fail(ucfirst(ReadOnlyTransactionUnavailable::shown($name))." uses {$shown}, which has no read-only transaction, so every tool call fails.", self::FIX);
            }
        }

        if ($fails !== []) {
            return $fails;
        }

        $count = count($names);

        return [CheckResult::pass("Read tools run in a read-only transaction on {$count} ".($count === 1 ? 'connection' : 'connections').'.')];
    }

    /** As DatabaseManager finds it: a url can set the driver (ConfigurationUrlParser). */
    private static function driver(mixed $settings): mixed
    {
        if (is_string($settings)) {
            return (new ConfigurationUrlParser)->parseConfiguration($settings)['driver'] ?? null;
        }

        if (! is_array($settings)) {
            return null;
        }

        $named = array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY);

        return (new ConfigurationUrlParser)->parseConfiguration($named)['driver'] ?? null;
    }
}
