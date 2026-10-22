<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;

/** Spec 3.1, 01-14 Part A: picks the frame for a connection. PostgreSQL, MySQL and MariaDB only. */
final class Frames
{
    public function for(Connection $connection): SessionFrame
    {
        return match ($connection->getDriverName()) {
            'pgsql' => new PostgresFrame,
            'mariadb' => new MariaDbFrame,
            // Apps from before Laravel 11 reach MariaDB with the mysql driver. MySqlFrame would fail every
            // call there with 1193; 01-13's MySqlPrivileges uses the same test (Sina, 2026-10-21).
            'mysql' => $this->isMaria($connection) ? new MariaDbFrame : new MySqlFrame,
            default => throw new UnsupportedDriver($connection->getDriverName()),
        };
    }

    private function isMaria(Connection $connection): bool
    {
        // isMaria() reads the server version from getPdo(), which is null after a disconnect().
        $connection->reconnectIfMissingConnection();

        return $connection instanceof MySqlConnection && $connection->isMaria();
    }
}
