<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use RuntimeException;
use Throwable;

/**
 * Spec 5.9, 01-16 Part A: the read-only transaction could not be opened, so the tool does not run
 * (fail closed). Every message is package text: ReadOnlyStage puts it in the agent's note. A
 * connection name is shown only when it has the shape of a name (Sina, 2026-10-20, as 01-12).
 */
final class ReadOnlyTransactionUnavailable extends RuntimeException
{
    public static function badConfig(): self
    {
        return new self('read_only_transactions.connections is not a list of connection names.');
    }

    public static function noDefault(): self
    {
        return new self('database.default does not name a connection in config/database.php.');
    }

    public static function unknown(string $name): self
    {
        return new self(self::shown($name)." is not in config('database.connections').");
    }

    public static function driver(string $name, string $driver): self
    {
        $shown = preg_match('/^[a-z0-9_]{1,32}$/D', $driver) === 1 ? "the `{$driver}` driver" : 'a driver';

        return new self(self::shown($name)." uses {$shown}; read-only transactions support PostgreSQL, MySQL, MariaDB and SQLite.");
    }

    public static function inTransaction(string $name): self
    {
        return new self(self::shown($name).' is already in a transaction (a test with RefreshDatabase, or app code that left one open).');
    }

    public static function lost(string $name): self
    {
        return new self(self::shown($name).' was lost while the read-only transaction was set up.');
    }

    /** Class only: a connect error holds the host and the user name, which must not reach the agent. */
    public static function failed(string $name, Throwable $previous): self
    {
        return new self(self::shown($name).' failed ('.$previous::class.').', 0, $previous);
    }

    /** "connection `reporting`", or "a listed connection" when the name does not look like one. */
    public static function shown(string $name): string
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name) === 1 ? "connection `{$name}`" : 'a listed connection';
    }
}
