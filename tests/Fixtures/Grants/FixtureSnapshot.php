<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Grants;

use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Grants\TableSnapshot;

/**
 * The fixture schema of ReadOnlyRole::schema(), built by hand: the input of the golden files
 * postgres.sql and mysql.sql. SchemaSnapshotTest proves that read() of the real schema equals it.
 */
final class FixtureSnapshot
{
    public const DATABASE = 'runtime_mcp';

    public static function postgres(): SchemaSnapshot
    {
        return new SchemaSnapshot('pgsql', self::DATABASE, '', self::tables('public'));
    }

    /** On MySQL and MariaDB the schema is the database. $driver is mysql or mariadb. */
    public static function mysql(string $driver = 'mysql'): SchemaSnapshot
    {
        return new SchemaSnapshot($driver, self::DATABASE, '', self::tables(self::DATABASE));
    }

    /** @return list<TableSnapshot> sorted by name (byte order), columns in table order */
    private static function tables(string $schema): array
    {
        $columns = [
            'cache' => ['key', 'value', 'expiration'],
            'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
            'jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
            'order_items' => ['id', 'order_id', 'product_name', 'quantity', 'unit_price'],
            'orders' => ['id', 'user_id', 'number', 'total', 'status', 'created_at'],
            'password_reset_tokens' => ['email', 'token', 'created_at'],
            'personal_access_tokens' => [
                'id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at',
                'created_at', 'updated_at',
            ],
            'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
            'users' => ['id', 'name', 'email', 'password', 'remember_token', 'api_token', 'created_at', 'updated_at'],
        ];

        $tables = [];

        foreach ($columns as $name => $list) {
            $tables[] = new TableSnapshot($schema, $name, $list);
        }

        return $tables;
    }
}
